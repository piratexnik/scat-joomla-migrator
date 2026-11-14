<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class ContentMigrator
{
    public const AJAX_CREATE_WP_CAT_ACTION = 'scat_migrator_create_wp_cat';
    public const AJAX_IMPORT_BATCH_ACTION = 'scat_migrator_import_articles_batch';

    public const META_JOOMLA_ID = '_joomla_content_id';
    public const META_JOOMLA_CAT_ID = '_joomla_cat_id';
    public const META_JOOMLA_SECTION_ID = '_joomla_section_id';
    public const META_JOOMLA_HITS = '_joomla_hits';
    public const META_NEWS_THUMB = '_scat_joomla_news_thumb';

    public function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_CREATE_WP_CAT_ACTION, [$this, 'ajax_create_wp_cat']);
        add_action('wp_ajax_' . self::AJAX_IMPORT_BATCH_ACTION, [$this, 'ajax_import_articles_batch']);
    }

    /**
     * AJAX handler: create or get the "Архів новин" (archive-news) category in WordPress.
     */
    public function ajax_create_wp_cat(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $cat_name = isset($_POST['cat_name']) ? sanitize_text_field(wp_unslash((string) $_POST['cat_name'])) : '';
        if ($cat_name === '') {
            $cat_name = 'Архів новин';
        }

        $cat_slug = isset($_POST['cat_slug']) ? sanitize_title(wp_unslash((string) $_POST['cat_slug'])) : '';
        if ($cat_slug === '') {
            $cat_slug = 'archive-news';
        }

        $lang = isset($_POST['lang']) ? sanitize_key(wp_unslash((string) $_POST['lang'])) : 'uk';
        if ($lang === '') {
            $lang = 'uk';
        }

        $existing_term = get_term_by('slug', $cat_slug, 'category');
        if ($existing_term instanceof \WP_Term) {
            $term_id = (int) $existing_term->term_id;
        } else {
            $inserted = wp_insert_term($cat_name, 'category', [
                'slug' => $cat_slug,
            ]);

            if (is_wp_error($inserted)) {
                wp_send_json_error(['message' => $inserted->get_error_message()], 500);
            }

            $term_id = (int) ($inserted['term_id'] ?? 0);
        }

        if ($term_id > 0 && function_exists('pll_set_term_language')) {
            pll_set_term_language($term_id, $lang);
        }

        wp_send_json_success([
            'term_id'       => $term_id,
            'wp_categories' => DatabaseAnalyzer::get_wp_categories_list(),
        ]);
    }

    /**
     * AJAX handler: batch migrate articles from jos_content to WordPress.
     */
    public function ajax_import_articles_batch(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $raw_cat_ids = isset($_POST['joomla_cat_ids']) ? wp_unslash($_POST['joomla_cat_ids']) : [];
        if (is_string($raw_cat_ids)) {
            $decoded = json_decode($raw_cat_ids, true);
            $raw_cat_ids = is_array($decoded) ? $decoded : explode(',', $raw_cat_ids);
        }

        $joomla_cat_ids = [];
        if (is_array($raw_cat_ids)) {
            foreach ($raw_cat_ids as $cid) {
                if (is_numeric($cid)) {
                    $joomla_cat_ids[] = max(0, (int) $cid);
                }
            }
        }
        $joomla_cat_ids = array_values(array_unique($joomla_cat_ids));

        if ($joomla_cat_ids === []) {
            wp_send_json_error(['message' => __('Не вибрано жодної категорії Joomla для імпорту.', 'scat-joomla-migrator')], 400);
        }

        $target_wp_cat_id = isset($_POST['target_wp_cat_id']) ? max(0, (int) wp_unslash($_POST['target_wp_cat_id'])) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash((string) $_POST['post_type'])) : 'post';
        if (!in_array($post_type, ['post', 'page'], true)) {
            $post_type = 'post';
        }

        $target_lang = isset($_POST['target_lang']) ? sanitize_key(wp_unslash((string) $_POST['target_lang'])) : 'uk';
        if ($target_lang === '') {
            $target_lang = 'uk';
        }

        $slug_mode = isset($_POST['slug_mode']) ? sanitize_key(wp_unslash((string) $_POST['slug_mode'])) : 'date';
        if (!in_array($slug_mode, ['date', 'alias'], true)) {
            $slug_mode = 'date';
        }

        $excerpt_length = isset($_POST['excerpt_length']) ? (int) wp_unslash($_POST['excerpt_length']) : 270;
        $excerpt_length = max(50, min(1000, $excerpt_length));

        $set_featured_image = !isset($_POST['set_featured_image']) || $this->to_bool($_POST['set_featured_image']);
        $strip_first_img = isset($_POST['strip_first_img_from_body']) && $this->to_bool($_POST['strip_first_img_from_body']);
        $only_published = !isset($_POST['only_published']) || $this->to_bool($_POST['only_published']);
        $skip_existing = !isset($_POST['skip_existing']) || $this->to_bool($_POST['skip_existing']);
        $import_jf = isset($_POST['import_jf_translations']) && $this->to_bool($_POST['import_jf_translations']);

        $offset = isset($_POST['offset']) ? max(0, (int) wp_unslash($_POST['offset'])) : 0;
        $batch_size = isset($_POST['batch_size']) ? (int) wp_unslash($_POST['batch_size']) : 20;
        $batch_size = max(1, min(100, $batch_size));

        global $wpdb;

        $exists_content = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_content'));
        if ($exists_content !== 'jos_content') {
            wp_send_json_error(['message' => __('Таблицю jos_content не знайдено в БД.', 'scat-joomla-migrator')], 400);
        }

        $cat_placeholders = implode(', ', array_fill(0, count($joomla_cat_ids), '%d'));
        $state_clause = $only_published ? 'AND state IN (1, -1)' : '';

        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM `jos_content` WHERE catid IN ({$cat_placeholders}) {$state_clause}",
            ...$joomla_cat_ids
        );
        $total = is_string($count_sql) ? (int) $wpdb->get_var($count_sql) : 0;

        if ($total === 0 || $offset >= $total) {
            wp_send_json_success([
                'processed'   => 0,
                'imported'    => 0,
                'skipped'     => 0,
                'next_offset' => $total,
                'total'       => $total,
                'percent'     => 100,
                'logs'        => [],
            ]);
        }

        $select_args = array_merge($joomla_cat_ids, [$batch_size, $offset]);
        $select_sql = $wpdb->prepare(
            "SELECT * FROM `jos_content`
             WHERE catid IN ({$cat_placeholders}) {$state_clause}
             ORDER BY created ASC, id ASC
             LIMIT %d OFFSET %d",
            ...$select_args
        );

        $rows = is_string($select_sql) ? $wpdb->get_results($select_sql) : [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $processed = 0;
        $imported = 0;
        $skipped = 0;
        $logs = [];

        foreach ($rows as $row) {
            if (!is_object($row) || empty($row->id)) {
                continue;
            }

            $processed++;
            $joomla_id = (int) $row->id;
            $existing_post_id = $this->find_post_by_joomla_id($joomla_id);

            if ($existing_post_id > 0 && $skip_existing) {
                $skipped++;
                $logs[] = [
                    'status'    => 'skipped',
                    'joomla_id' => $joomla_id,
                    'post_id'   => $existing_post_id,
                    'title'     => (string) $row->title,
                    'url'       => (string) get_permalink($existing_post_id),
                    'edit_url'  => (string) get_edit_post_link($existing_post_id, 'raw'),
                    'message'   => sprintf('Пропущено (вже існує WP #%d)', $existing_post_id),
                ];
                continue;
            }

            $post_id = $this->migrate_single_article($row, [
                'existing_post_id'          => $existing_post_id,
                'target_wp_cat_id'          => $target_wp_cat_id,
                'post_type'                 => $post_type,
                'target_lang'               => $target_lang,
                'slug_mode'                 => $slug_mode,
                'excerpt_length'            => $excerpt_length,
                'set_featured_image'        => $set_featured_image,
                'strip_first_img_from_body' => $strip_first_img,
                'import_jf_translations'    => $import_jf,
            ]);

            if (is_wp_error($post_id)) {
                $logs[] = [
                    'status'    => 'error',
                    'joomla_id' => $joomla_id,
                    'post_id'   => 0,
                    'title'     => (string) $row->title,
                    'url'       => '',
                    'edit_url'  => '',
                    'message'   => $post_id->get_error_message(),
                ];
                continue;
            }

            $imported++;
            $logs[] = [
                'status'    => $existing_post_id > 0 ? 'updated' : 'imported',
                'joomla_id' => $joomla_id,
                'post_id'   => $post_id,
                'title'     => (string) $row->title,
                'url'       => (string) get_permalink($post_id),
                'edit_url'  => admin_url('post.php?post=' . $post_id . '&action=edit'),
                'message'   => sprintf(
                    '%s WP #%d (%s)',
                    $existing_post_id > 0 ? 'Оновлено' : 'Імпортовано',
                    $post_id,
                    (string) get_post_field('post_name', $post_id)
                ),
            ];
        }

        $next_offset = min($total, $offset + $processed);
        $percent = $total > 0 ? (int) round(($next_offset / $total) * 100) : 100;

        wp_send_json_success([
            'processed'   => $processed,
            'imported'    => $imported,
            'skipped'     => $skipped,
            'next_offset' => $next_offset,
            'total'       => $total,
            'percent'     => $percent,
            'logs'        => $logs,
        ]);
    }

    /**
     * Migrate a single jos_content row into WordPress.
     *
     * @param object               $row
     * @param array<string, mixed> $options
     * @return int|\WP_Error
     */
    private function migrate_single_article(object $row, array $options)
    {
        $joomla_id = (int) $row->id;
        $existing_post_id = (int) ($options['existing_post_id'] ?? 0);
        $target_wp_cat_id = (int) ($options['target_wp_cat_id'] ?? 0);
        $post_type = (string) ($options['post_type'] ?? 'post');
        $target_lang = (string) ($options['target_lang'] ?? 'uk');
        $slug_mode = (string) ($options['slug_mode'] ?? 'date');
        $excerpt_length = (int) ($options['excerpt_length'] ?? 270);
        $set_featured_image = (bool) ($options['set_featured_image'] ?? true);
        $strip_first_img = (bool) ($options['strip_first_img_from_body'] ?? false);
        $import_jf = (bool) ($options['import_jf_translations'] ?? false);

        $title = trim( wp_strip_all_tags( html_entity_decode((string) $row->title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ) );
        if ($title === '') {
            $title = 'Стаття #' . $joomla_id;
        }

        $post_date = $this->normalize_joomla_date((string) ($row->created ?? ''), (string) ($row->modified ?? ''));
        $timestamp = strtotime($post_date);
        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = time();
            $post_date = current_time('mysql');
        }

        if ($slug_mode === 'date') {
            $desired_slug = 'article-' . gmdate('Y-m-d', $timestamp);
        } else {
            $raw_alias = trim((string) ($row->alias ?? ''));
            $desired_slug = $raw_alias !== '' ? sanitize_title($raw_alias) : sanitize_title($title);
            if ($desired_slug === '') {
                $desired_slug = 'article-' . $joomla_id;
            }
        }

        $introtext = (string) ($row->introtext ?? '');
        $fulltext = (string) ($row->fulltext ?? '');

        $combined_html = $this->combine_intro_and_fulltext($introtext, $fulltext);
        $processed_content = $this->process_article_html(
            $combined_html,
            $set_featured_image && $strip_first_img
        );

        $clean_html = $processed_content['html'];
        $first_webp_rel = $processed_content['first_webp_rel'];

        $excerpt_source = trim($introtext) !== '' ? $introtext : $fulltext;
        $excerpt = $this->build_excerpt($excerpt_source, $excerpt_length);

        $state = (int) ($row->state ?? 1);
        $post_status = ($state === 1 || $state === -1) ? 'publish' : 'draft';

        $post_data = [
            'post_type'     => $post_type,
            'post_title'    => $title,
            'post_name'     => $desired_slug,
            'post_content'  => $clean_html,
            'post_excerpt'  => $excerpt,
            'post_status'   => $post_status,
            'post_date'     => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date),
        ];

        if ($existing_post_id > 0) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post(wp_slash($post_data), true);
        } else {
            $post_id = wp_insert_post(wp_slash($post_data), true);
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $post_id = (int) $post_id;

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $target_lang);
        }

        if ($post_type === 'post' && $target_wp_cat_id > 0) {
            if (function_exists('pll_get_term_language') && function_exists('pll_set_term_language')) {
                $term_lang = pll_get_term_language($target_wp_cat_id);
                if (empty($term_lang)) {
                    pll_set_term_language($target_wp_cat_id, $target_lang);
                }
            }
            wp_set_post_categories($post_id, [$target_wp_cat_id], false);
        }

        if ($set_featured_image && $first_webp_rel !== null) {
            $thumb_id = $this->get_or_register_webp_thumbnail($first_webp_rel);
            if ($thumb_id > 0) {
                set_post_thumbnail($post_id, $thumb_id);
            }
        }

        update_post_meta($post_id, self::META_JOOMLA_ID, $joomla_id);
        update_post_meta($post_id, self::META_JOOMLA_CAT_ID, (int) ($row->catid ?? 0));
        update_post_meta($post_id, self::META_JOOMLA_SECTION_ID, (int) ($row->sectionid ?? 0));
        update_post_meta($post_id, self::META_JOOMLA_HITS, (int) ($row->hits ?? 0));

        $metadesc = trim((string) ($row->metadesc ?? ''));
        if ($metadesc !== '') {
            update_post_meta($post_id, 'rank_math_description', sanitize_text_field($metadesc));
        }

        $metakey = trim((string) ($row->metakey ?? ''));
        if ($metakey !== '') {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($metakey));
        }

        if ($import_jf) {
            $this->import_joomfish_translations($joomla_id, $post_id, $row, $options);
        }

        return $post_id;
    }

    /**
     * Find existing WordPress post ID by _joomla_content_id meta.
     */
    private function find_post_by_joomla_id(int $joomla_id): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value = %s
               AND p.post_status != 'trash'
             LIMIT 1",
            self::META_JOOMLA_ID,
            (string) $joomla_id
        );

        return is_string($sql) ? (int) $wpdb->get_var($sql) : 0;
    }

    /**
     * Combine Joomla introtext and fulltext without duplicating introtext.
     */
    private function combine_intro_and_fulltext(string $introtext, string $fulltext): string
    {
        $intro_trim = trim($introtext);
        $full_trim = trim($fulltext);

        if ($full_trim === '') {
            return $intro_trim;
        }

        if ($intro_trim === '') {
            return $full_trim;
        }

        $intro_plain = trim(wp_strip_all_tags($intro_trim));
        $full_plain = trim(wp_strip_all_tags($full_trim));

        if ($intro_plain !== '' && strpos($full_plain, $intro_plain) === 0) {
            return $full_trim;
        }

        return $intro_trim . "\n<!--more-->\n" . $full_trim;
    }

    /**
     * Rewrite image src to scat-content-webp, rewrite dmdocuments links, and clean legacy editor attributes.
     *
     * @return array{html: string, first_webp_rel: string|null}
     */
    private function process_article_html(string $html, bool $strip_first_local_img): array
    {
        $paths = ImageMigrator::get_paths();
        $first_webp_rel = null;
        $first_img_stripped = false;

        $html = (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use ($paths, &$first_webp_rel, &$first_img_stripped, $strip_first_local_img): string {
                $img_tag = $match[0];

                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $img_tag, $src_match) !== 1) {
                    return $img_tag;
                }

                $quote = $src_match[1];
                $raw_src = trim($src_match[2]);
                $rel_image_path = $this->extract_legacy_image_rel_path($raw_src);

                if ($rel_image_path === null) {
                    return $img_tag;
                }

                $webp_rel = ImageMigrator::to_webp_relative_path($rel_image_path);
                $webp_abs = $paths['webp_dir'] . '/' . $webp_rel;
                $legacy_abs = $paths['legacy_images_dir'] . '/' . $rel_image_path;

                $new_url = null;
                if (is_file($webp_abs)) {
                    $new_url = $this->build_encoded_url(content_url('uploads/scat-content-webp'), $webp_rel);
                    if ($first_webp_rel === null) {
                        $first_webp_rel = $webp_rel;
                    }
                } elseif (is_file($legacy_abs)) {
                    $new_url = $this->build_encoded_url(content_url('uploads/joomla-legacy/images'), $rel_image_path);
                }

                if ($new_url === null) {
                    return $img_tag;
                }

                if ($strip_first_local_img && !$first_img_stripped && $first_webp_rel !== null) {
                    $first_img_stripped = true;
                    return '';
                }

                return (string) preg_replace(
                    '/\bsrc\s*=\s*(["\'])(.*?)\1/i',
                    'src=' . $quote . esc_url($new_url) . $quote,
                    $img_tag,
                    1
                );
            },
            $html
        );

        // Rewrite links to dmdocuments/...
        $html = (string) preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/i',
            function (array $match): string {
                $prefix = $match[1];
                $quote = $match[2];
                $href = trim($match[3]);

                $doc_rel = $this->extract_legacy_doc_rel_path($href);
                if ($doc_rel === null) {
                    return $match[0];
                }

                $new_href = $this->build_encoded_url(content_url('uploads/joomla-legacy/dmdocuments'), $doc_rel);
                return $prefix . $quote . esc_url($new_href) . $quote;
            },
            $html
        );

        // Clean legacy Joomla TinyMCE and MS Word attributes
        $html = (string) preg_replace('/\s+mce_[a-z0-9_]+\s*=\s*(["\']).*?\1/i', '', $html);
        $html = (string) preg_replace('/\bmso-[a-z0-9_-]+\s*:\s*[^;"]+;?/i', '', $html);
        $html = (string) preg_replace('/\s+style\s*=\s*(["\'])\s*\1/i', '', $html);

        return [
            'html'           => trim($html),
            'first_webp_rel' => $first_webp_rel,
        ];
    }

    /**
     * Extract relative path inside Joomla images/ folder from legacy src attribute.
     */
    private function extract_legacy_image_rel_path(string $src): ?string
    {
        if ($src === '' || stripos($src, 'data:') === 0) {
            return null;
        }

        $clean = preg_replace('/[?#].*$/', '', $src);
        $clean = is_string($clean) ? trim($clean) : '';

        $pattern = '#^(?:https?://(?:www\.)?scat\.com\.ua)?/?(?:\.\./)*images/(.+)$#i';
        if (preg_match($pattern, $clean, $matches) !== 1) {
            return null;
        }

        $rel = rawurldecode($matches[1]);
        $rel = ltrim(str_replace('\\', '/', $rel), '/');

        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            return null;
        }

        return $rel;
    }

    /**
     * Extract relative path inside dmdocuments/ folder from legacy href attribute.
     */
    private function extract_legacy_doc_rel_path(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        $clean = preg_replace('/[?#].*$/', '', $href);
        $clean = is_string($clean) ? trim($clean) : '';

        $pattern = '#^(?:https?://(?:www\.)?scat\.com\.ua)?/?(?:\.\./)*dmdocuments/(.+)$#i';
        if (preg_match($pattern, $clean, $matches) !== 1) {
            return null;
        }

        $rel = rawurldecode($matches[1]);
        $rel = ltrim(str_replace('\\', '/', $rel), '/');

        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            return null;
        }

        return $rel;
    }

    /**
     * Build clean plain-text excerpt truncated by whole word up to $max_length characters.
     */
    private function build_excerpt(string $html, int $max_length = 270): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = wp_strip_all_tags($decoded);
        $collapsed = (string) preg_replace('/\s+/u', ' ', $stripped);
        $collapsed = trim($collapsed);

        if ($collapsed === '') {
            return '';
        }

        $len = function_exists('mb_strlen') ? mb_strlen($collapsed, 'UTF-8') : strlen($collapsed);
        if ($len <= $max_length) {
            return $collapsed;
        }

        $slice = function_exists('mb_substr')
            ? mb_substr($collapsed, 0, $max_length, 'UTF-8')
            : substr($collapsed, 0, $max_length);

        $last_space = function_exists('mb_strrpos')
            ? mb_strrpos($slice, ' ', 0, 'UTF-8')
            : strrpos($slice, ' ');

        if ($last_space !== false && $last_space > (int) ($max_length * 0.5)) {
            $slice = function_exists('mb_substr')
                ? mb_substr($slice, 0, $last_space, 'UTF-8')
                : substr($slice, 0, $last_space);
        }

        return rtrim($slice, " \t\n\r\0\x0B.,;:-") . '…';
    }

    /**
     * Register (or reuse) a .webp file in scat-content-webp/ as a WordPress attachment without intermediate sizes.
     */
    private function get_or_register_webp_thumbnail(string $webp_rel): int
    {
        $webp_rel = ltrim(str_replace('\\', '/', $webp_rel), '/');
        if ($webp_rel === '' || strpos($webp_rel, '..') !== false) {
            return 0;
        }

        $meta_val = 'webp:' . $webp_rel;

        global $wpdb;
        $existing_sql = $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
             WHERE pm.meta_key = %s
               AND pm.meta_value = %s
             LIMIT 1",
            ArchiveBrowser::META_KEY_REL_PATH,
            $meta_val
        );

        $existing_id = is_string($existing_sql) ? (int) $wpdb->get_var($existing_sql) : 0;
        if ($existing_id > 0) {
            return $existing_id;
        }

        $paths = ImageMigrator::get_paths();
        $abs_file = $paths['webp_dir'] . '/' . $webp_rel;
        if (!is_file($abs_file)) {
            return 0;
        }

        $file_url = $this->build_encoded_url($paths['webp_url'], $webp_rel);
        $filename = basename($abs_file);
        $title = preg_replace('/\.[^.]+$/', '', $filename);
        $title = is_string($title) && $title !== '' ? $title : $filename;

        $attachment_data = [
            'guid'           => $file_url,
            'post_mime_type' => 'image/webp',
            'post_title'     => sanitize_text_field($title),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment_data, $abs_file, 0, true);
        if (is_wp_error($attach_id) || $attach_id <= 0) {
            return 0;
        }

        $attach_id = (int) $attach_id;

        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Temporarily disable intermediate thumbnail generation to save disk space and speed up batch import
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 9999);
        $metadata = wp_generate_attachment_metadata($attach_id, $abs_file);
        remove_filter('intermediate_image_sizes_advanced', '__return_empty_array', 9999);

        if (is_array($metadata)) {
            wp_update_attachment_metadata($attach_id, $metadata);
        }

        update_post_meta($attach_id, ArchiveBrowser::META_KEY_REL_PATH, $meta_val);
        update_post_meta($attach_id, self::META_NEWS_THUMB, 1);

        return $attach_id;
    }

    /**
     * Optional Joom!Fish translations importer for an article.
     *
     * @param array<string, mixed> $options
     */
    private function import_joomfish_translations(int $joomla_id, int $source_post_id, object $source_row, array $options): void
    {
        if (!function_exists('pll_save_post_translations') || !function_exists('pll_set_post_language')) {
            return;
        }

        global $wpdb;

        $has_jf = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_jf_content')) === 'jos_jf_content';
        $has_langs = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_languages')) === 'jos_languages';
        if (!$has_jf || !$has_langs) {
            return;
        }

        $sql = $wpdb->prepare(
            "SELECT jf.language_id, jf.reference_field, jf.value, l.shortcode, l.iso, l.code
             FROM `jos_jf_content` jf
             INNER JOIN `jos_languages` l ON l.id = jf.language_id
             WHERE jf.reference_table = 'content'
               AND jf.reference_id = %d
               AND jf.published = 1",
            $joomla_id
        );

        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows) || $rows === []) {
            return;
        }

        $by_lang = [];
        foreach ($rows as $r) {
            if (!is_object($r)) {
                continue;
            }

            $lang_slug = $this->resolve_polylang_slug_from_joomla($r);
            if ($lang_slug === '' || $lang_slug === ($options['target_lang'] ?? 'uk')) {
                continue;
            }

            $field = (string) ($r->reference_field ?? '');
            $by_lang[$lang_slug][$field] = (string) ($r->value ?? '');
        }

        if ($by_lang === []) {
            return;
        }

        $translations_map = [
            (string) ($options['target_lang'] ?? 'uk') => $source_post_id,
        ];

        foreach ($by_lang as $lang_slug => $fields) {
            $tr_title = trim(wp_strip_all_tags((string) ($fields['title'] ?? '')));
            if ($tr_title === '') {
                continue;
            }

            $tr_intro = (string) ($fields['introtext'] ?? $source_row->introtext ?? '');
            $tr_full = (string) ($fields['fulltext'] ?? $source_row->fulltext ?? '');
            $combined = $this->combine_intro_and_fulltext($tr_intro, $tr_full);
            $processed = $this->process_article_html(
                $combined,
                !empty($options['set_featured_image']) && !empty($options['strip_first_img_from_body'])
            );

            $excerpt = $this->build_excerpt(
                trim($tr_intro) !== '' ? $tr_intro : $tr_full,
                (int) ($options['excerpt_length'] ?? 270)
            );

            $post_date = get_post_field('post_date', $source_post_id);
            $source_slug = get_post_field('post_name', $source_post_id);

            $tr_post_id = wp_insert_post(wp_slash([
                'post_type'     => (string) ($options['post_type'] ?? 'post'),
                'post_title'    => $tr_title,
                'post_name'     => $source_slug . '-' . $lang_slug,
                'post_content'  => $processed['html'],
                'post_excerpt'  => $excerpt,
                'post_status'   => get_post_status($source_post_id) ?: 'publish',
                'post_date'     => $post_date,
                'post_date_gmt' => get_gmt_from_date($post_date),
            ]), true);

            if (is_wp_error($tr_post_id) || $tr_post_id <= 0) {
                continue;
            }

            $tr_post_id = (int) $tr_post_id;
            pll_set_post_language($tr_post_id, $lang_slug);

            $thumb_id = (int) get_post_thumbnail_id($source_post_id);
            if ($thumb_id > 0) {
                set_post_thumbnail($tr_post_id, $thumb_id);
            }

            update_post_meta($tr_post_id, self::META_JOOMLA_ID, $joomla_id);
            $translations_map[$lang_slug] = $tr_post_id;
        }

        if (count($translations_map) > 1) {
            pll_save_post_translations($translations_map);
        }
    }

    private function resolve_polylang_slug_from_joomla(object $lang_row): string
    {
        $candidates = [
            strtolower(trim((string) ($lang_row->shortcode ?? ''))),
            strtolower(trim((string) ($lang_row->iso ?? ''))),
            strtolower( substr(trim((string) ($lang_row->code ?? '')), 0, 2) ),
        ];

        foreach ($candidates as $c) {
            if ($c === 'ua' || $c === 'uk') {
                return 'uk';
            }
            if ($c === 'ru') {
                return 'ru';
            }
            if ($c === 'en') {
                return 'en';
            }
        }

        return $candidates[0] ?? '';
    }

    private function normalize_joomla_date(string $created, string $modified): string
    {
        foreach ([$created, $modified] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || strpos($candidate, '0000-00-00') === 0) {
                continue;
            }
            $ts = strtotime($candidate);
            if ($ts !== false && $ts > 0) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
        }

        return (string) current_time('mysql');
    }

    private function build_encoded_url(string $base_url, string $rel_path): string
    {
        $segments = explode('/', ltrim(str_replace('\\', '/', $rel_path), '/'));
        $encoded = array_map('rawurlencode', $segments);

        return untrailingslashit($base_url) . '/' . implode('/', $encoded);
    }

    /**
     * @param mixed $val
     */
    private function to_bool($val): bool
    {
        if (is_bool($val)) {
            return $val;
        }
        $str = strtolower(trim((string) $val));
        return in_array($str, ['1', 'true', 'yes', 'on'], true);
    }
}
