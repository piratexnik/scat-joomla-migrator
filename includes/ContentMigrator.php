<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class ContentMigrator
{
    public const AJAX_CREATE_WP_CAT_ACTION = 'scat_migrator_create_wp_cat';
    public const AJAX_IMPORT_BATCH_ACTION = 'scat_migrator_import_articles_batch';
    public const AJAX_FIX_EXISTING_ACTION = 'scat_migrator_fix_existing_posts';

    public const META_JOOMLA_ID = '_joomla_content_id';
    public const META_JOOMLA_LANG = '_joomla_content_lang';
    public const META_JOOMLA_CAT_ID = '_joomla_cat_id';
    public const META_JOOMLA_SECTION_ID = '_joomla_section_id';
    public const META_JOOMLA_HITS = '_joomla_hits';
    public const META_NEWS_THUMB = '_scat_joomla_news_thumb';

    public const JF_MODE_HYBRID_FULL_UK = 'uk_full_plus_existing';
    public const JF_MODE_UK_PRIORITY = 'uk_priority';
    public const JF_MODE_MULTILANG_PLL = 'multilang_pll';
    public const JF_MODE_RAW_ORIGINAL = 'raw_original';

    /** @var array<int, string>|null */
    private ?array $docman_map_cache = null;

    public function init(): void
    {
        if (is_admin()) {
            add_action('wp_ajax_' . self::AJAX_CREATE_WP_CAT_ACTION, [$this, 'ajax_create_wp_cat']);
            add_action('wp_ajax_' . self::AJAX_IMPORT_BATCH_ACTION, [$this, 'ajax_import_articles_batch']);
            add_action('wp_ajax_' . self::AJAX_FIX_EXISTING_ACTION, [$this, 'ajax_fix_existing_posts']);
        } else {
            add_action('wp_footer', [$this, 'render_frontend_lightbox'], 99);
        }
    }

    /**
     * Set or reset \PolylangUp\Modules\SameSlug::$forced_lang before wp_insert_post/wp_update_post/wp_insert_term.
     */
    private function set_same_slug_forced_lang(?string $lang): void
    {
        if (class_exists('\PolylangUp\Modules\SameSlug') && property_exists('\PolylangUp\Modules\SameSlug', 'forced_lang')) {
            \PolylangUp\Modules\SameSlug::$forced_lang = $lang;
        }
    }

    /**
     * Clean legacy language (-ru, -en) or numeric (-2, -3) suffixes from a post slug.
     */
    private function strip_legacy_slug_suffix(string $slug): string
    {
        $cleaned = (string) preg_replace('/(?:-(?:ru|en)|-\d+)+$/i', '', $slug);
        return $cleaned !== '' ? $cleaned : $slug;
    }

    /**
     * AJAX handler: carefully find or create and link 3 Polylang categories (UK + RU + EN) in 1 click.
     */
    public function ajax_create_wp_cat(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $name_uk = isset($_POST['name_uk']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['name_uk']))) : '';
        if ($name_uk === '' && isset($_POST['cat_name'])) {
            $name_uk = trim(sanitize_text_field(wp_unslash((string) $_POST['cat_name'])));
        }
        if ($name_uk === '') {
            $name_uk = 'Архів новин';
        }

        $name_ru = isset($_POST['name_ru']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['name_ru']))) : '';
        $name_en = isset($_POST['name_en']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['name_en']))) : '';

        $slug_uk = isset($_POST['slug_uk']) ? sanitize_title(wp_unslash((string) $_POST['slug_uk'])) : '';
        $slug_ru = isset($_POST['slug_ru']) ? sanitize_title(wp_unslash((string) $_POST['slug_ru'])) : '';
        $slug_en = isset($_POST['slug_en']) ? sanitize_title(wp_unslash((string) $_POST['slug_en'])) : '';

        if ($slug_uk === '') {
            $base_uk = sanitize_title($name_uk);
            $slug_uk = $base_uk !== '' ? $base_uk : 'category';
        }

        $items = [
            'uk' => [
                'name' => $name_uk,
                'slug' => $slug_uk,
            ],
        ];

        if ($name_ru !== '') {
            if ($slug_ru === '') {
                $base_ru = sanitize_title($name_ru);
                $slug_ru = $base_ru !== '' ? $base_ru : $slug_uk;
            }
            $items['ru'] = [
                'name' => $name_ru,
                'slug' => $slug_ru,
            ];
        }

        if ($name_en !== '') {
            if ($slug_en === '') {
                $base_en = sanitize_title($name_en);
                $slug_en = $base_en !== '' ? $base_en : $slug_uk;
            }
            $items['en'] = [
                'name' => $name_en,
                'slug' => $slug_en,
            ];
        }

        // First, inspect existing categories by name+lang and their Polylang translation links to avoid breaking existing groups
        $existing_translations = [];
        foreach ($items as $lang_code => $item) {
            $found_id = $this->find_existing_category_by_name_and_lang($item['name'], $lang_code);
            if ($found_id > 0) {
                $existing_translations[$lang_code] = $found_id;
                if (function_exists('pll_get_term_translations')) {
                    $linked = pll_get_term_translations($found_id);
                    if (is_array($linked)) {
                        foreach ($linked as $tr_lang => $tr_term_id) {
                            $tr_term_id = (int) $tr_term_id;
                            if (is_string($tr_lang) && $tr_term_id > 0 && empty($existing_translations[$tr_lang])) {
                                $existing_translations[$tr_lang] = $tr_term_id;
                            }
                        }
                    }
                }
            }
        }

        $term_translations = $existing_translations;
        $created_summary = [];

        foreach ($items as $lang_code => $item) {
            if (!empty($term_translations[$lang_code])) {
                $term_id = (int) $term_translations[$lang_code];
                $term_obj = get_term($term_id, 'category');
                $display_name = $term_obj instanceof \WP_Term ? $term_obj->name : $item['name'];
                $created_summary[] = sprintf('%s: %s (#%d, існує)', strtoupper($lang_code), $display_name, $term_id);
                continue;
            }

            $term_id = $this->get_or_create_category_term($item['name'], $item['slug'], $lang_code);
            if ($term_id > 0) {
                $term_translations[$lang_code] = $term_id;
                $created_summary[] = sprintf('%s: %s (#%d)', strtoupper($lang_code), $item['name'], $term_id);
            }
        }

        if (count($term_translations) > 1 && function_exists('pll_save_term_translations')) {
            pll_save_term_translations($term_translations);
        }

        $uk_term_id = (int) ($term_translations['uk'] ?? 0);

        wp_send_json_success([
            'term_id'       => $uk_term_id,
            'translations'  => $term_translations,
            'summary'       => implode(' | ', $created_summary),
            'wp_categories' => DatabaseAnalyzer::get_wp_categories_list(),
        ]);
    }

    /**
     * Find an existing category term strictly matching $name and Polylang language $lang.
     */
    private function find_existing_category_by_name_and_lang(string $name, string $lang): int
    {
        $args = [
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'name'       => $name,
            'fields'     => 'ids',
        ];

        if (function_exists('pll_languages_list')) {
            $args['lang'] = $lang;
        }

        $terms = get_terms($args);
        if (is_array($terms) && $terms !== []) {
            foreach ($terms as $tid_raw) {
                $tid = (int) $tid_raw;
                if ($tid <= 0) {
                    continue;
                }
                if (function_exists('pll_get_term_language')) {
                    $t_lang = pll_get_term_language($tid);
                    if (is_string($t_lang) && $t_lang !== '' && $t_lang !== $lang) {
                        continue;
                    }
                }
                return $tid;
            }
        }

        return 0;
    }

    /**
     * Find or create a single category term with SameSlug::$forced_lang support and safe Polylang Free slug fallback.
     */
    private function get_or_create_category_term(string $name, string $slug, string $lang): int
    {
        $existing_by_name = $this->find_existing_category_by_name_and_lang($name, $lang);
        if ($existing_by_name > 0) {
            return $existing_by_name;
        }

        $this->set_same_slug_forced_lang($lang);

        $term_id = 0;
        $inserted = wp_insert_term($name, 'category', ['slug' => $slug]);

        if (is_wp_error($inserted)) {
            $error_code = $inserted->get_error_code();
            if ($error_code === 'term_exists') {
                $existing_id = (int) $inserted->get_error_data('term_exists');
                $existing_lang = ($existing_id > 0 && function_exists('pll_get_term_language'))
                    ? (string) pll_get_term_language($existing_id)
                    : '';

                // If the existing term belongs to the same language (or has no language), reuse it;
                // otherwise (Polylang Free wp_terms slug collision across languages), fallback to -$lang suffix.
                if ($existing_id > 0 && ($existing_lang === '' || $existing_lang === $lang)) {
                    $term_id = $existing_id;
                } else {
                    $base_slug = (string) preg_replace('/-(uk|ru|en)$/i', '', $slug);
                    $base_slug = $base_slug !== '' ? $base_slug : sanitize_title($name);
                    if ($base_slug === '') {
                        $base_slug = 'category';
                    }
                    $fallback_slug = $base_slug . '-' . $lang;
                    $retry = wp_insert_term($name, 'category', ['slug' => $fallback_slug]);
                    if (!is_wp_error($retry)) {
                        $term_id = (int) ($retry['term_id'] ?? 0);
                    } elseif ($retry->get_error_code() === 'term_exists') {
                        $retry_id = (int) $retry->get_error_data('term_exists');
                        if ($retry_id > 0) {
                            $term_id = $retry_id;
                        }
                    }
                }
            }
        } else {
            $term_id = (int) ($inserted['term_id'] ?? 0);
        }

        if ($term_id > 0 && function_exists('pll_set_term_language')) {
            pll_set_term_language($term_id, $lang);
        }

        $this->set_same_slug_forced_lang(null);

        return $term_id;
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

        $joomla_article_ids = $this->parse_id_list($_POST['joomla_article_ids'] ?? [], false);
        $joomla_cat_ids = $this->parse_id_list($_POST['joomla_cat_ids'] ?? [], true);

        if ($joomla_article_ids === [] && $joomla_cat_ids === []) {
            wp_send_json_error([
                'message' => __('Не вибрано жодної категорії або статті Joomla для імпорту.', 'scat-joomla-migrator'),
            ], 400);
        }

        $target_wp_cat_id = isset($_POST['target_wp_cat_id']) ? max(0, (int) wp_unslash($_POST['target_wp_cat_id'])) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash((string) $_POST['post_type'])) : 'post';
        if (!in_array($post_type, ['post', 'page'], true)) {
            $post_type = 'post';
        }

        $post_status = isset($_POST['post_status']) ? sanitize_key(wp_unslash((string) $_POST['post_status'])) : 'publish';
        if (!in_array($post_status, ['publish', 'draft'], true)) {
            $post_status = 'publish';
        }

        $target_lang = isset($_POST['target_lang']) ? sanitize_key(wp_unslash((string) $_POST['target_lang'])) : 'uk';
        if ($target_lang === '') {
            $target_lang = 'uk';
        }

        $jf_mode = isset($_POST['jf_mode']) ? sanitize_key(wp_unslash((string) $_POST['jf_mode'])) : self::JF_MODE_HYBRID_FULL_UK;
        $allowed_modes = [
            self::JF_MODE_HYBRID_FULL_UK,
            self::JF_MODE_UK_PRIORITY,
            self::JF_MODE_MULTILANG_PLL,
            self::JF_MODE_RAW_ORIGINAL,
        ];
        if (!in_array($jf_mode, $allowed_modes, true)) {
            $jf_mode = self::JF_MODE_HYBRID_FULL_UK;
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

        $offset = isset($_POST['offset']) ? max(0, (int) wp_unslash($_POST['offset'])) : 0;
        $batch_size = isset($_POST['batch_size']) ? (int) wp_unslash($_POST['batch_size']) : 20;
        $batch_size = max(1, min(100, $batch_size));

        global $wpdb;

        $exists_content = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_content'));
        if ($exists_content !== 'jos_content') {
            wp_send_json_error(['message' => __('Таблицю jos_content не знайдено в БД.', 'scat-joomla-migrator')], 400);
        }

        if ($joomla_article_ids !== []) {
            $id_placeholders = implode(', ', array_fill(0, count($joomla_article_ids), '%d'));
            $count_sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM `jos_content` WHERE id IN ({$id_placeholders})",
                ...$joomla_article_ids
            );
            $total = is_string($count_sql) ? (int) $wpdb->get_var($count_sql) : 0;

            $select_args = array_merge($joomla_article_ids, [$batch_size, $offset]);
            $select_sql = $wpdb->prepare(
                "SELECT * FROM `jos_content`
                 WHERE id IN ({$id_placeholders})
                 ORDER BY created ASC, id ASC
                 LIMIT %d OFFSET %d",
                ...$select_args
            );
        } else {
            $cat_placeholders = implode(', ', array_fill(0, count($joomla_cat_ids), '%d'));
            $state_clause = $only_published ? 'AND state IN (1, -1)' : '';

            $count_sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM `jos_content` WHERE catid IN ({$cat_placeholders}) {$state_clause}",
                ...$joomla_cat_ids
            );
            $total = is_string($count_sql) ? (int) $wpdb->get_var($count_sql) : 0;

            $select_args = array_merge($joomla_cat_ids, [$batch_size, $offset]);
            $select_sql = $wpdb->prepare(
                "SELECT * FROM `jos_content`
                 WHERE catid IN ({$cat_placeholders}) {$state_clause}
                 ORDER BY created ASC, id ASC
                 LIMIT %d OFFSET %d",
                ...$select_args
            );
        }

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

        $rows = is_string($select_sql) ? $wpdb->get_results($select_sql) : [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $processed = 0;
        $imported = 0;
        $skipped = 0;
        $logs = [];

        $options = [
            'target_wp_cat_id'          => $target_wp_cat_id,
            'post_type'                 => $post_type,
            'post_status'               => $post_status,
            'target_lang'               => $target_lang,
            'jf_mode'                   => $jf_mode,
            'slug_mode'                 => $slug_mode,
            'excerpt_length'            => $excerpt_length,
            'set_featured_image'        => $set_featured_image,
            'strip_first_img_from_body' => $strip_first_img,
            'skip_existing'             => $skip_existing,
        ];

        foreach ($rows as $row) {
            if (!is_object($row) || empty($row->id)) {
                continue;
            }

            $processed++;
            $joomla_id = (int) $row->id;

            if ($jf_mode === self::JF_MODE_HYBRID_FULL_UK) {
                $hybrid_res = $this->migrate_article_hybrid_full_uk($row, $options);
                $imported += $hybrid_res['imported'];
                $skipped += $hybrid_res['skipped'];
                foreach ($hybrid_res['logs'] as $log_entry) {
                    $logs[] = $log_entry;
                }
                continue;
            }

            if ($jf_mode === self::JF_MODE_MULTILANG_PLL) {
                $multi_res = $this->migrate_article_multilang_pll($row, $options);
                $imported += $multi_res['imported'];
                $skipped += $multi_res['skipped'];
                foreach ($multi_res['logs'] as $log_entry) {
                    $logs[] = $log_entry;
                }
                continue;
            }

            $existing_post_id = $this->find_post_by_joomla_id($joomla_id, $target_lang);

            if ($existing_post_id > 0 && $skip_existing) {
                $skipped++;
                $logs[] = [
                    'status'    => 'skipped',
                    'joomla_id' => $joomla_id,
                    'post_id'   => $existing_post_id,
                    'title'     => (string) $row->title,
                    'url'       => (string) get_permalink($existing_post_id),
                    'edit_url'  => admin_url('post.php?post=' . $existing_post_id . '&action=edit'),
                    'message'   => sprintf('Пропущено (вже існує WP #%d)', $existing_post_id),
                ];
                continue;
            }

            $options['existing_post_id'] = $existing_post_id;
            $res = $this->migrate_article_single_lang($row, $options);

            if (is_wp_error($res)) {
                $logs[] = [
                    'status'    => 'error',
                    'joomla_id' => $joomla_id,
                    'post_id'   => 0,
                    'title'     => (string) $row->title,
                    'url'       => '',
                    'edit_url'  => '',
                    'message'   => $res->get_error_message(),
                ];
                continue;
            }

            $post_id = $res['post_id'];
            $final_title = $res['title'];
            $lang_tag = $res['used_jf_uk'] ? ' [🇺🇦 Joom!Fish]' : '';

            $imported++;
            $logs[] = [
                'status'    => $existing_post_id > 0 ? 'updated' : 'imported',
                'joomla_id' => $joomla_id,
                'post_id'   => $post_id,
                'title'     => $final_title,
                'url'       => (string) get_permalink($post_id),
                'edit_url'  => admin_url('post.php?post=' . $post_id . '&action=edit'),
                'message'   => sprintf(
                    '%s WP #%d (%s)%s',
                    $existing_post_id > 0 ? 'Оновлено' : 'Імпортовано',
                    $post_id,
                    (string) get_post_field('post_name', $post_id),
                    $lang_tag
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
     * AJAX handler: scan all existing WordPress posts and pages across all Polylang languages (UK/RU/EN),
     * rewrite legacy images/... paths, replace DocMan (?option=com_docman&gid=X) & dmdocuments/ links,
     * and pack inline image columns/lists into compact lightbox thumbnail grids.
     */
    public function ajax_fix_existing_posts(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        @set_time_limit(240);

        global $wpdb;

        $docman_map = $this->get_docman_map();

        $checked_posts = 0;
        $updated_posts = 0;
        $total_doc_links_fixed = 0;
        $total_groups_fixed = 0;
        $total_images_fixed = 0;
        $updated_titles = [];
        $logs = [];

        $chunk_limit = 200;
        $offset = 0;

        do {
            $sql = $wpdb->prepare(
                "SELECT ID, post_title, post_content
                 FROM {$wpdb->posts}
                 WHERE post_type IN (%s, %s)
                   AND post_status IN (%s, %s, %s, %s)
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                'post',
                'page',
                'publish',
                'draft',
                'private',
                'future',
                $chunk_limit,
                $offset
            );

            $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
            if (!is_array($rows) || $rows === []) {
                break;
            }

            $fetched_count = count($rows);
            $offset += $fetched_count;

            foreach ($rows as $row) {
                if (!is_object($row) || empty($row->ID)) {
                    continue;
                }

                $checked_posts++;
                $post_id = (int) $row->ID;
                $original_content = (string) ($row->post_content ?? '');

                if ($original_content === '') {
                    continue;
                }

                $updated_content = $original_content;
                $post_images_fixed = 0;
                $post_docs_fixed = 0;
                $post_groups_fixed = 0;

                if (stripos($updated_content, 'images/') !== false) {
                    $updated_content = $this->rewrite_legacy_image_urls($updated_content, $post_images_fixed);
                }

                if (stripos($updated_content, 'option=com_docman') !== false || stripos($updated_content, 'dmdocuments') !== false) {
                    $updated_content = $this->replace_docman_links($updated_content, $post_docs_fixed);
                }

                if (stripos($updated_content, '<img') !== false) {
                    $joomla_id = (int) get_post_meta($post_id, self::META_JOOMLA_ID, true);
                    $group_prefix = $joomla_id > 0 ? $joomla_id . '-' . $post_id : 'wp' . $post_id;
                    $updated_content = $this->convert_inline_image_groups(
                        $updated_content,
                        $post_groups_fixed,
                        $group_prefix,
                        (string) ($row->post_title ?? '')
                    );
                }

                if ($updated_content === '' || $updated_content === $original_content) {
                    continue;
                }

                $result = $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $updated_content],
                    ['ID' => $post_id],
                    ['%s'],
                    ['%d']
                );

                if ($result === false) {
                    continue;
                }

                clean_post_cache($post_id);

                $updated_posts++;
                $total_doc_links_fixed += $post_docs_fixed;
                $total_groups_fixed += $post_groups_fixed;
                $total_images_fixed += $post_images_fixed;

                $lang_code = '';
                if (function_exists('pll_get_post_language')) {
                    $pll_lang = pll_get_post_language($post_id);
                    if (is_string($pll_lang) && $pll_lang !== '') {
                        $lang_code = strtoupper($pll_lang);
                    }
                }
                if ($lang_code === '') {
                    $meta_lang = get_post_meta($post_id, self::META_JOOMLA_LANG, true);
                    if (is_string($meta_lang) && $meta_lang !== '') {
                        $lang_code = strtoupper($meta_lang);
                    }
                }

                $title_str = (string) ($row->post_title ?? '');
                if ($title_str === '') {
                    $title_str = 'WP #' . $post_id;
                }
                $display_title = $lang_code !== '' ? '[' . $lang_code . '] ' . $title_str : $title_str;
                $updated_titles[] = $display_title;

                $changes_parts = [];
                if ($post_docs_fixed > 0) {
                    $changes_parts[] = 'DocMan: ' . $post_docs_fixed;
                }
                if ($post_groups_fixed > 0) {
                    $changes_parts[] = 'фото-рядів: ' . $post_groups_fixed;
                }
                if ($post_images_fixed > 0) {
                    $changes_parts[] = 'images/: ' . $post_images_fixed;
                }
                if ($changes_parts === []) {
                    $changes_parts[] = 'оновлено розмітку';
                }

                $joomla_meta_id = (int) get_post_meta($post_id, self::META_JOOMLA_ID, true);

                $logs[] = [
                    'status'    => 'updated',
                    'joomla_id' => $joomla_meta_id,
                    'post_id'   => $post_id,
                    'title'     => $display_title,
                    'url'       => (string) get_permalink($post_id),
                    'edit_url'  => admin_url('post.php?post=' . $post_id . '&action=edit'),
                    'message'   => sprintf('WP #%d (%s)', $post_id, implode(', ', $changes_parts)),
                ];
            }
        } while ($fetched_count === $chunk_limit);

        wp_send_json_success([
            'checked_posts'       => $checked_posts,
            'updated_posts'       => $updated_posts,
            'docman_links_fixed'  => $total_doc_links_fixed,
            'inline_groups_fixed' => $total_groups_fixed,
            'images_fixed'        => $total_images_fixed,
            'docman_map_count'    => count($docman_map),
            'updated_titles'      => $updated_titles,
            'logs'                => $logs,
        ]);
    }

    /**
     * Hybrid Mode ('uk_full_plus_existing'):
     * 1) Always creates main [UK] post for 100% of imported articles (using Joom!Fish UK if available, otherwise jos_content).
     * 2) Preserves [RU] original (when UK translation existed in Joom!Fish) as a separate linked [RU] post.
     * 3) Preserves [EN] translation (when EN exists in Joom!Fish) as a separate linked [EN] post.
     * 4) Links all created versions via pll_save_post_translations() and shares the same WebP Featured Image.
     *
     * @param object               $row
     * @param array<string, mixed> $options
     * @return array{imported: int, skipped: int, logs: array<int, array<string, mixed>>}
     */
    private function migrate_article_hybrid_full_uk(object $row, array $options): array
    {
        $joomla_id = (int) $row->id;
        $skip_existing = (bool) ($options['skip_existing'] ?? true);

        $orig_title = (string) ($row->title ?? '');
        $orig_intro = (string) ($row->introtext ?? '');
        $orig_full = (string) ($row->fulltext ?? '');

        $orig_combined = $this->combine_intro_and_fulltext($orig_intro, $orig_full);
        $orig_scan = $this->process_article_html($orig_combined, false, $joomla_id, $orig_title);
        $orig_first_webp = $orig_scan['first_webp_rel'];
        $orig_first_img_tag = $orig_scan['first_img_tag'];
        $orig_galleries_html = $orig_scan['galleries_html'];

        $orig_fields = [
            'title'     => $orig_title,
            'alias'     => (string) ($row->alias ?? ''),
            'introtext' => $orig_intro,
            'fulltext'  => $orig_full,
            'metadesc'  => (string) ($row->metadesc ?? ''),
            'metakey'   => (string) ($row->metakey ?? ''),
        ];

        $jf_by_lang = $this->get_joomfish_fields_for_article($joomla_id);
        $jf_uk = $jf_by_lang['uk'] ?? [];
        $jf_en = $jf_by_lang['en'] ?? [];
        $jf_ru = $jf_by_lang['ru'] ?? [];

        $has_jf_uk = (isset($jf_uk['title']) && trim($jf_uk['title']) !== '')
            || (isset($jf_uk['introtext']) && trim($jf_uk['introtext']) !== '')
            || (isset($jf_uk['fulltext']) && trim($jf_uk['fulltext']) !== '');

        $has_jf_en = (isset($jf_en['title']) && trim($jf_en['title']) !== '')
            || (isset($jf_en['introtext']) && trim($jf_en['introtext']) !== '')
            || (isset($jf_en['fulltext']) && trim($jf_en['fulltext']) !== '');

        $has_jf_ru = (isset($jf_ru['title']) && trim($jf_ru['title']) !== '')
            || (isset($jf_ru['introtext']) && trim($jf_ru['introtext']) !== '')
            || (isset($jf_ru['fulltext']) && trim($jf_ru['fulltext']) !== '');

        if ($has_jf_uk) {
            $orig_lang = 'ru';
        } else {
            $orig_lang = $this->detect_cyrillic_language($orig_title . ' ' . wp_strip_all_tags($orig_intro));
        }

        $versions = [];

        // 1. Main [UK] version is ALWAYS created for 100% of articles
        $uk_version = $orig_fields;
        if ($has_jf_uk) {
            foreach (['title', 'alias', 'introtext', 'fulltext', 'metadesc', 'metakey'] as $k) {
                if (isset($jf_uk[$k]) && trim($jf_uk[$k]) !== '') {
                    $uk_version[$k] = $jf_uk[$k];
                }
            }
            if (trim($uk_version['introtext']) === '' && trim($uk_version['fulltext']) === '') {
                $uk_version['introtext'] = $orig_intro;
                $uk_version['fulltext'] = $orig_full;
            }
        }
        $versions['uk'] = $uk_version;

        // 2. Preserve [RU] version if BOTH UK translation and RU original/translation exist
        if ($has_jf_uk && $orig_lang === 'ru') {
            $versions['ru'] = $orig_fields;
        } elseif ($has_jf_ru) {
            $ru_version = $orig_fields;
            foreach (['title', 'alias', 'introtext', 'fulltext', 'metadesc', 'metakey'] as $k) {
                if (isset($jf_ru[$k]) && trim($jf_ru[$k]) !== '') {
                    $ru_version[$k] = $jf_ru[$k];
                }
            }
            $versions['ru'] = $ru_version;
        }

        // 3. Preserve [EN] version if EN translation exists in Joom!Fish
        if ($has_jf_en) {
            $en_version = [
                'title'     => $orig_title,
                'alias'     => (string) ($row->alias ?? ''),
                'introtext' => '',
                'fulltext'  => '',
                'metadesc'  => '',
                'metakey'   => '',
            ];
            foreach (['title', 'alias', 'introtext', 'fulltext', 'metadesc', 'metakey'] as $k) {
                if (isset($jf_en[$k]) && trim($jf_en[$k]) !== '') {
                    $en_version[$k] = $jf_en[$k];
                }
            }
            if (trim($en_version['introtext']) === '' && trim($en_version['fulltext']) === '') {
                $en_version['introtext'] = $orig_intro;
                $en_version['fulltext'] = $orig_full;
            }
            $versions['en'] = $en_version;
        }

        return $this->persist_and_link_versions(
            $row,
            $versions,
            $orig_first_webp,
            $orig_first_img_tag,
            $orig_galleries_html,
            $skip_existing,
            $options
        );
    }

    /**
     * Mode 1 ('uk_priority') and Mode 3 ('raw_original'): migrate single post in $target_lang.
     *
     * @param object               $row
     * @param array<string, mixed> $options
     * @return array{post_id: int, title: string, used_jf_uk: bool}|\WP_Error
     */
    private function migrate_article_single_lang(object $row, array $options)
    {
        $joomla_id = (int) $row->id;
        $jf_mode = (string) ($options['jf_mode'] ?? self::JF_MODE_UK_PRIORITY);
        $target_lang = (string) ($options['target_lang'] ?? 'uk');

        $orig_title = (string) ($row->title ?? '');
        $orig_intro = (string) ($row->introtext ?? '');
        $orig_full = (string) ($row->fulltext ?? '');
        $orig_combined = $this->combine_intro_and_fulltext($orig_intro, $orig_full);
        $orig_scan = $this->process_article_html($orig_combined, false, $joomla_id, $orig_title);
        $orig_first_webp = $orig_scan['first_webp_rel'];
        $orig_first_img_tag = $orig_scan['first_img_tag'];
        $orig_galleries_html = $orig_scan['galleries_html'];

        $fields = [
            'title'     => $orig_title,
            'alias'     => (string) ($row->alias ?? ''),
            'introtext' => $orig_intro,
            'fulltext'  => $orig_full,
            'metadesc'  => (string) ($row->metadesc ?? ''),
            'metakey'   => (string) ($row->metakey ?? ''),
        ];

        $used_jf_uk = false;

        if ($jf_mode === self::JF_MODE_UK_PRIORITY) {
            $jf_by_lang = $this->get_joomfish_fields_for_article($joomla_id);
            $uk_fields = $jf_by_lang['uk'] ?? [];

            if ($uk_fields !== []) {
                foreach (['title', 'alias', 'introtext', 'fulltext', 'metadesc', 'metakey'] as $f_key) {
                    if (isset($uk_fields[$f_key]) && trim($uk_fields[$f_key]) !== '') {
                        $fields[$f_key] = $uk_fields[$f_key];
                        if ($f_key === 'title' || $f_key === 'introtext' || $f_key === 'fulltext') {
                            $used_jf_uk = true;
                        }
                    }
                }
            }
        }

        $post_id = $this->save_article_version(
            $row,
            $fields,
            $target_lang,
            (int) ($options['existing_post_id'] ?? 0),
            $orig_first_webp,
            $orig_first_img_tag,
            $orig_galleries_html,
            $options
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return [
            'post_id'    => $post_id,
            'title'      => $fields['title'],
            'used_jf_uk' => $used_jf_uk,
        ];
    }

    /**
     * Mode 2 ('multilang_pll'): create and link all available language versions (UK, RU, EN) via Polylang.
     *
     * @param object               $row
     * @param array<string, mixed> $options
     * @return array{imported: int, skipped: int, logs: array<int, array<string, mixed>>}
     */
    private function migrate_article_multilang_pll(object $row, array $options): array
    {
        $joomla_id = (int) $row->id;
        $skip_existing = (bool) ($options['skip_existing'] ?? true);

        $orig_title = (string) ($row->title ?? '');
        $orig_intro = (string) ($row->introtext ?? '');
        $orig_full = (string) ($row->fulltext ?? '');

        $orig_combined = $this->combine_intro_and_fulltext($orig_intro, $orig_full);
        $orig_scan = $this->process_article_html($orig_combined, false, $joomla_id, $orig_title);
        $orig_first_webp = $orig_scan['first_webp_rel'];
        $orig_first_img_tag = $orig_scan['first_img_tag'];
        $orig_galleries_html = $orig_scan['galleries_html'];

        $orig_lang = $this->detect_cyrillic_language($orig_title . ' ' . wp_strip_all_tags($orig_intro));

        $versions = [];
        $versions[$orig_lang] = [
            'title'     => $orig_title,
            'alias'     => (string) ($row->alias ?? ''),
            'introtext' => $orig_intro,
            'fulltext'  => $orig_full,
            'metadesc'  => (string) ($row->metadesc ?? ''),
            'metakey'   => (string) ($row->metakey ?? ''),
        ];

        $jf_by_lang = $this->get_joomfish_fields_for_article($joomla_id);
        foreach ($jf_by_lang as $lang_code => $jf_fields) {
            $has_title = isset($jf_fields['title']) && trim($jf_fields['title']) !== '';
            $has_body = (isset($jf_fields['introtext']) && trim($jf_fields['introtext']) !== '')
                || (isset($jf_fields['fulltext']) && trim($jf_fields['fulltext']) !== '');

            if (!$has_title && !$has_body) {
                continue;
            }

            $base = $versions[$lang_code] ?? [
                'title'     => $orig_title,
                'alias'     => (string) ($row->alias ?? ''),
                'introtext' => '',
                'fulltext'  => '',
                'metadesc'  => '',
                'metakey'   => '',
            ];

            foreach (['title', 'alias', 'introtext', 'fulltext', 'metadesc', 'metakey'] as $k) {
                if (isset($jf_fields[$k]) && trim($jf_fields[$k]) !== '') {
                    $base[$k] = $jf_fields[$k];
                }
            }

            if (trim($base['introtext']) === '' && trim($base['fulltext']) === '') {
                $base['introtext'] = $orig_intro;
                $base['fulltext'] = $orig_full;
            }

            $versions[$lang_code] = $base;
        }

        return $this->persist_and_link_versions(
            $row,
            $versions,
            $orig_first_webp,
            $orig_first_img_tag,
            $orig_galleries_html,
            $skip_existing,
            $options
        );
    }

    /**
     * Save multiple language versions of a Joomla article and link them via pll_save_post_translations().
     * When $skip_existing === false, updates existing posts in place (expanding {yoogallery}, updating excerpt,
     * and setting Featured Image if missing) without creating duplicate posts.
     *
     * @param object                               $row
     * @param array<string, array<string, string>> $versions
     * @param string|null                          $orig_first_webp
     * @param string|null                          $orig_first_img_tag
     * @param array<int, string>                   $orig_galleries_html
     * @param bool                                 $skip_existing
     * @param array<string, mixed>                 $options
     * @return array{imported: int, skipped: int, logs: array<int, array<string, mixed>>}
     */
    private function persist_and_link_versions(
        object $row,
        array $versions,
        ?string $orig_first_webp,
        ?string $orig_first_img_tag,
        array $orig_galleries_html,
        bool $skip_existing,
        array $options
    ): array {
        $joomla_id = (int) $row->id;
        $existing_by_lang = $this->find_all_posts_by_joomla_id($joomla_id);
        $translations_map = $existing_by_lang;

        $imported = 0;
        $skipped = 0;
        $logs = [];

        foreach ($versions as $lang_code => $ver_fields) {
            $existing_id = $existing_by_lang[$lang_code] ?? $this->find_post_by_joomla_id($joomla_id, $lang_code);

            if ($existing_id > 0 && $skip_existing) {
                $translations_map[$lang_code] = $existing_id;
                $skipped++;
                $logs[] = [
                    'status'    => 'skipped',
                    'joomla_id' => $joomla_id,
                    'post_id'   => $existing_id,
                    'title'     => '[' . strtoupper($lang_code) . '] ' . $ver_fields['title'],
                    'url'       => (string) get_permalink($existing_id),
                    'edit_url'  => admin_url('post.php?post=' . $existing_id . '&action=edit'),
                    'message'   => sprintf('Пропущено (%s вже існує WP #%d)', strtoupper($lang_code), $existing_id),
                ];
                continue;
            }

            $post_id = $this->save_article_version(
                $row,
                $ver_fields,
                $lang_code,
                $existing_id,
                $orig_first_webp,
                $orig_first_img_tag,
                $orig_galleries_html,
                $options
            );

            if (is_wp_error($post_id)) {
                $logs[] = [
                    'status'    => 'error',
                    'joomla_id' => $joomla_id,
                    'post_id'   => 0,
                    'title'     => '[' . strtoupper($lang_code) . '] ' . $ver_fields['title'],
                    'url'       => '',
                    'edit_url'  => '',
                    'message'   => $post_id->get_error_message(),
                ];
                continue;
            }

            $translations_map[$lang_code] = $post_id;
            $imported++;

            $logs[] = [
                'status'    => $existing_id > 0 ? 'updated' : 'imported',
                'joomla_id' => $joomla_id,
                'post_id'   => $post_id,
                'title'     => '[' . strtoupper($lang_code) . '] ' . $ver_fields['title'],
                'url'       => (string) get_permalink($post_id),
                'edit_url'  => admin_url('post.php?post=' . $post_id . '&action=edit'),
                'message'   => sprintf(
                    '%s [%s] WP #%d (%s)',
                    $existing_id > 0 ? 'Оновлено' : 'Імпортовано',
                    strtoupper($lang_code),
                    $post_id,
                    (string) get_post_field('post_name', $post_id)
                ),
            ];
        }

        // If skip_existing is false and there are extra linked translations in WP (e.g. created earlier via AI translator),
        // also expand {yoogallery} in their content, clean their excerpt, and set Featured Image if missing.
        if (!$skip_existing) {
            $set_featured_image = (bool) ($options['set_featured_image'] ?? true);
            $strip_first_img = (bool) ($options['strip_first_img_from_body'] ?? false);
            $excerpt_length = (int) ($options['excerpt_length'] ?? 270);

            foreach ($existing_by_lang as $extra_lang => $extra_post_id) {
                if (isset($versions[$extra_lang]) || $extra_post_id <= 0) {
                    continue;
                }

                $extra_post = get_post($extra_post_id);
                if (!$extra_post instanceof \WP_Post) {
                    continue;
                }

                $processed_extra = $this->process_article_html(
                    (string) $extra_post->post_content,
                    $set_featured_image && $strip_first_img,
                    $joomla_id,
                    (string) $extra_post->post_title
                );

                $updated_extra_html = $processed_extra['html'];
                if (
                    $processed_extra['galleries_html'] === []
                    && $orig_galleries_html !== []
                    && strpos($updated_extra_html, 'scat-migrated-gallery') === false
                ) {
                    $updated_extra_html = trim($updated_extra_html . "\n" . implode("\n", $orig_galleries_html));
                }

                $updated_extra_excerpt = $this->build_excerpt(
                    (string) $extra_post->post_excerpt !== '' ? (string) $extra_post->post_excerpt : $updated_extra_html,
                    $excerpt_length
                );

                $extra_post_status = (string) ($options['post_status'] ?? 'publish');
                if (!in_array($extra_post_status, ['publish', 'draft'], true)) {
                    $extra_post_status = 'publish';
                }

                $update_extra_data = [
                    'ID'           => $extra_post_id,
                    'post_content' => $updated_extra_html,
                    'post_excerpt' => $updated_extra_excerpt,
                    'post_status'  => $extra_post_status,
                ];

                $extra_slug = (string) $extra_post->post_name;
                if ($extra_slug !== '' && preg_match('/(?:-(?:ru|en)|-\d+)+$/i', $extra_slug)) {
                    $cleaned_extra_slug = $this->strip_legacy_slug_suffix($extra_slug);
                    if ($cleaned_extra_slug !== '' && $cleaned_extra_slug !== $extra_slug) {
                        $update_extra_data['post_name'] = $cleaned_extra_slug;
                    }
                }

                $this->set_same_slug_forced_lang((string) $extra_lang);
                wp_update_post(wp_slash($update_extra_data));
                $this->set_same_slug_forced_lang(null);

                $extra_first_webp = $processed_extra['first_webp_rel'] ?? $orig_first_webp;
                if ($set_featured_image && $extra_first_webp !== null) {
                    $existing_thumb_id = (int) get_post_thumbnail_id($extra_post_id);
                    if ($existing_thumb_id <= 0 || !get_post($existing_thumb_id)) {
                        $thumb_id = $this->get_or_register_webp_thumbnail($extra_first_webp);
                        if ($thumb_id > 0) {
                            set_post_thumbnail($extra_post_id, $thumb_id);
                        }
                    }
                }

                update_post_meta($extra_post_id, self::META_JOOMLA_ID, $joomla_id);
                update_post_meta($extra_post_id, self::META_JOOMLA_LANG, $extra_lang);

                $translations_map[$extra_lang] = $extra_post_id;
                $imported++;

                $logs[] = [
                    'status'    => 'updated',
                    'joomla_id' => $joomla_id,
                    'post_id'   => $extra_post_id,
                    'title'     => '[' . strtoupper($extra_lang) . '] ' . $extra_post->post_title,
                    'url'       => (string) get_permalink($extra_post_id),
                    'edit_url'  => admin_url('post.php?post=' . $extra_post_id . '&action=edit'),
                    'message'   => sprintf(
                        'Оновлено [%s] WP #%d (%s)',
                        strtoupper($extra_lang),
                        $extra_post_id,
                        (string) get_post_field('post_name', $extra_post_id)
                    ),
                ];
            }
        }

        if (count($translations_map) > 1 && function_exists('pll_save_post_translations')) {
            pll_save_post_translations($translations_map);
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'logs'     => $logs,
        ];
    }

    /**
     * Create or update a single language version of a Joomla article in WordPress.
     *
     * @param object                $row
     * @param array<string, string> $fields
     * @param string                $lang_code
     * @param int                   $existing_post_id
     * @param string|null           $fallback_first_webp
     * @param string|null           $fallback_first_img_tag
     * @param array<int, string>    $fallback_galleries_html
     * @param array<string, mixed>  $options
     * @return int|\WP_Error
     */
    private function save_article_version(
        object $row,
        array $fields,
        string $lang_code,
        int $existing_post_id,
        ?string $fallback_first_webp,
        ?string $fallback_first_img_tag,
        array $fallback_galleries_html,
        array $options
    ) {
        $joomla_id = (int) $row->id;
        $target_wp_cat_id = (int) ($options['target_wp_cat_id'] ?? 0);
        $post_type = (string) ($options['post_type'] ?? 'post');
        $slug_mode = (string) ($options['slug_mode'] ?? 'date');
        $excerpt_length = (int) ($options['excerpt_length'] ?? 270);
        $set_featured_image = (bool) ($options['set_featured_image'] ?? true);
        $strip_first_img = (bool) ($options['strip_first_img_from_body'] ?? false);
        $jf_mode = (string) ($options['jf_mode'] ?? self::JF_MODE_HYBRID_FULL_UK);

        $title = trim(wp_strip_all_tags(html_entity_decode($fields['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($title === '') {
            $title = 'Стаття #' . $joomla_id;
        }

        $post_date = $this->normalize_joomla_date((string) ($row->created ?? ''), (string) ($row->modified ?? ''));
        $timestamp = strtotime($post_date);
        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = time();
            $post_date = (string) current_time('mysql');
        }

        if ($slug_mode === 'date') {
            $desired_slug = 'article-' . gmdate('Y-m-d', $timestamp);
        } else {
            $raw_alias = trim($fields['alias']);
            $desired_slug = $raw_alias !== '' ? sanitize_title($raw_alias) : sanitize_title($title);
            if ($desired_slug === '') {
                $desired_slug = 'article-' . $joomla_id;
            }
        }

        $combined_html = $this->combine_intro_and_fulltext($fields['introtext'], $fields['fulltext']);
        $processed = $this->process_article_html($combined_html, $set_featured_image && $strip_first_img, $joomla_id, $title);

        $clean_html = $processed['html'];
        $first_webp_rel = $processed['first_webp_rel'] ?? $fallback_first_webp;

        // If Joom!Fish translation lost the <img> tag that existed in original jos_content, preserve it if not stripping
        if (
            $processed['first_webp_rel'] === null
            && $fallback_first_img_tag !== null
            && !($set_featured_image && $strip_first_img)
            && stripos($clean_html, '<img') === false
        ) {
            $clean_html = $fallback_first_img_tag . "\n" . $clean_html;
        }

        // If Joom!Fish translation omitted the {yoogallery ...} tag that existed in original jos_content, preserve the gallery
        if (
            $processed['galleries_html'] === []
            && $fallback_galleries_html !== []
            && strpos($clean_html, 'scat-migrated-gallery') === false
        ) {
            $clean_html = trim($clean_html . "\n" . implode("\n", $fallback_galleries_html));
        }

        $excerpt_source = trim($fields['introtext']) !== '' ? $fields['introtext'] : $fields['fulltext'];
        $excerpt = $this->build_excerpt($excerpt_source, $excerpt_length);

        $post_status = (string) ($options['post_status'] ?? 'publish');
        if (!in_array($post_status, ['publish', 'draft'], true)) {
            $post_status = 'publish';
        }

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

        $this->set_same_slug_forced_lang($lang_code);

        if ($existing_post_id > 0) {
            $post_data['ID'] = $existing_post_id;
            $existing_slug = (string) get_post_field('post_name', $existing_post_id);
            if ($existing_slug !== '') {
                if (preg_match('/(?:-(?:ru|en)|-\d+)+$/i', $existing_slug)) {
                    $post_data['post_name'] = $this->strip_legacy_slug_suffix($existing_slug);
                } else {
                    $post_data['post_name'] = $existing_slug;
                }
            }
            $post_id = wp_update_post(wp_slash($post_data), true);
        } else {
            $post_id = wp_insert_post(wp_slash($post_data), true);
        }

        if (is_wp_error($post_id)) {
            $this->set_same_slug_forced_lang(null);
            return $post_id;
        }

        $post_id = (int) $post_id;

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $lang_code);
        }

        $this->set_same_slug_forced_lang(null);

        if ($post_type === 'post' && $target_wp_cat_id > 0) {
            $cat_to_assign = $target_wp_cat_id;

            if (function_exists('pll_get_term')) {
                $translated_term_id = (int) pll_get_term($target_wp_cat_id, $lang_code);
                if ($translated_term_id > 0) {
                    $cat_to_assign = $translated_term_id;
                }
            }

            if (function_exists('pll_get_term_language') && function_exists('pll_set_term_language')) {
                $term_lang = pll_get_term_language($cat_to_assign);
                if (empty($term_lang)) {
                    pll_set_term_language($cat_to_assign, $lang_code);
                }
            }

            wp_set_post_categories($post_id, [$cat_to_assign], false);
        }

        if ($set_featured_image && $first_webp_rel !== null) {
            $existing_thumb_id = (int) get_post_thumbnail_id($post_id);
            if ($existing_thumb_id <= 0 || !get_post($existing_thumb_id)) {
                $thumb_id = $this->get_or_register_webp_thumbnail($first_webp_rel);
                if ($thumb_id > 0) {
                    set_post_thumbnail($post_id, $thumb_id);
                }
            }
        }

        update_post_meta($post_id, self::META_JOOMLA_ID, $joomla_id);
        update_post_meta($post_id, self::META_JOOMLA_LANG, $lang_code);
        update_post_meta($post_id, self::META_JOOMLA_CAT_ID, (int) ($row->catid ?? 0));
        update_post_meta($post_id, self::META_JOOMLA_SECTION_ID, (int) ($row->sectionid ?? 0));
        update_post_meta($post_id, self::META_JOOMLA_HITS, (int) ($row->hits ?? 0));

        $metadesc = trim($fields['metadesc']);
        if ($metadesc !== '') {
            update_post_meta($post_id, 'rank_math_description', sanitize_text_field($metadesc));
        }

        $metakey = trim($fields['metakey']);
        if ($metakey !== '') {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($metakey));
        }

        return $post_id;
    }

    /**
     * Fetch all non-empty Joom!Fish content fields for a given article grouped by normalized language code ('uk', 'ru', 'en').
     *
     * @return array<string, array<string, string>>
     */
    private function get_joomfish_fields_for_article(int $joomla_id): array
    {
        global $wpdb;

        $has_jf = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_jf_content')) === 'jos_jf_content';
        if (!$has_jf) {
            return [];
        }

        $langs = DatabaseAnalyzer::get_joomla_languages();
        if ($langs['by_id'] === []) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT language_id, reference_field, value, published
             FROM `jos_jf_content`
             WHERE reference_table = 'content'
               AND reference_id = %d
               AND TRIM(value) != ''
             ORDER BY published DESC, id ASC",
            $joomla_id
        );

        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $r) {
            if (!is_object($r)) {
                continue;
            }

            $lid = (int) ($r->language_id ?? 0);
            $lang_code = $langs['by_id'][$lid] ?? '';
            if ($lang_code === '') {
                continue;
            }

            $field = (string) ($r->reference_field ?? '');
            if ($field === '' || isset($result[$lang_code][$field])) {
                continue;
            }

            $result[$lang_code][$field] = (string) $r->value;
        }

        return $result;
    }

    /**
     * Detect whether original Cyrillic text in jos_content is Ukrainian ('uk') or Russian ('ru').
     */
    private function detect_cyrillic_language(string $text): string
    {
        if (preg_match('/[іїєґІЇЄҐ]/u', $text) === 1) {
            return 'uk';
        }

        return 'ru';
    }

    /**
     * Find all existing WordPress post IDs for a given _joomla_content_id grouped by language ('uk', 'ru', 'en'),
     * including any translations linked in Polylang.
     *
     * @return array<string, int>
     */
    private function find_all_posts_by_joomla_id(int $joomla_id): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value = %s
               AND p.post_status != 'trash'
             ORDER BY p.ID ASC",
            self::META_JOOMLA_ID,
            (string) $joomla_id
        );

        $post_ids = is_string($sql) ? $wpdb->get_col($sql) : [];
        if (!is_array($post_ids) || $post_ids === []) {
            return [];
        }

        $by_lang = [];

        foreach ($post_ids as $pid_raw) {
            $pid = (int) $pid_raw;
            if ($pid <= 0) {
                continue;
            }

            $lang = '';
            if (function_exists('pll_get_post_language')) {
                $pll_lang = pll_get_post_language($pid);
                if (is_string($pll_lang) && $pll_lang !== '') {
                    $lang = $pll_lang;
                }
            }

            if ($lang === '') {
                $meta_lang = get_post_meta($pid, self::META_JOOMLA_LANG, true);
                if (is_string($meta_lang) && $meta_lang !== '') {
                    $lang = $meta_lang;
                }
            }

            if ($lang !== '' && !isset($by_lang[$lang])) {
                $by_lang[$lang] = $pid;
            }

            if (function_exists('pll_get_post_translations')) {
                $tr_map = pll_get_post_translations($pid);
                if (is_array($tr_map)) {
                    foreach ($tr_map as $tr_lang => $tr_id) {
                        $tr_id_int = (int) $tr_id;
                        if (is_string($tr_lang) && $tr_lang !== '' && $tr_id_int > 0 && !isset($by_lang[$tr_lang])) {
                            $tr_post = get_post($tr_id_int);
                            if ($tr_post instanceof \WP_Post && $tr_post->post_status !== 'trash') {
                                $by_lang[$tr_lang] = $tr_id_int;
                            }
                        }
                    }
                }
            }
        }

        return $by_lang;
    }

    /**
     * Find existing WordPress post ID by _joomla_content_id and language.
     */
    private function find_post_by_joomla_id(int $joomla_id, string $lang_code = ''): int
    {
        $by_lang = $this->find_all_posts_by_joomla_id($joomla_id);

        if ($lang_code !== '' && isset($by_lang[$lang_code])) {
            return $by_lang[$lang_code];
        }

        if ($lang_code === '' && $by_lang !== []) {
            return (int) reset($by_lang);
        }

        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value = %s
               AND p.post_status != 'trash'
             ORDER BY p.ID ASC",
            self::META_JOOMLA_ID,
            (string) $joomla_id
        );

        $post_ids = is_string($sql) ? $wpdb->get_col($sql) : [];
        if (!is_array($post_ids) || $post_ids === []) {
            return 0;
        }

        if ($lang_code === '') {
            return (int) $post_ids[0];
        }

        if (count($post_ids) === 1) {
            $single_id = (int) $post_ids[0];
            $existing_lang = function_exists('pll_get_post_language')
                ? (string) pll_get_post_language($single_id)
                : (string) get_post_meta($single_id, self::META_JOOMLA_LANG, true);

            if ($existing_lang === '' || $existing_lang === $lang_code) {
                return $single_id;
            }
        }

        return 0;
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
     * Rewrite image src to scat-content-webp, expand {yoogallery ...} tags into responsive WebP galleries,
     * rewrite DocMan / dmdocuments links, pack consecutive inline images into compact galleries,
     * and clean legacy editor attributes.
     *
     * @return array{html: string, first_webp_rel: string|null, first_img_tag: string|null, galleries_html: array<int, string>}
     */
    private function process_article_html(
        string $html,
        bool $strip_first_local_img,
        int $joomla_id = 0,
        string $article_title = ''
    ): array {
        $paths = ImageMigrator::get_paths();
        $first_webp_rel = null;
        $first_img_tag = null;
        $first_img_stripped = false;
        $galleries_html = [];

        $rewritten_imgs = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use (&$first_webp_rel, &$first_img_tag, &$first_img_stripped, $strip_first_local_img): string {
                $img_tag = $match[0];

                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $img_tag, $src_match) !== 1) {
                    return $img_tag;
                }

                $quote = $src_match[1];
                $raw_src = trim($src_match[2]);
                $resolved_webp_rel = null;
                $new_url = $this->rewrite_legacy_image_src($raw_src, $resolved_webp_rel);

                if ($resolved_webp_rel !== null && $first_webp_rel === null) {
                    $first_webp_rel = $resolved_webp_rel;
                }

                if ($new_url === null) {
                    return $img_tag;
                }

                $updated_tag = preg_replace(
                    '/\bsrc\s*=\s*(["\'])(.*?)\1/i',
                    'src=' . $quote . esc_url($new_url) . $quote,
                    $img_tag,
                    1
                );
                $updated_tag = is_string($updated_tag) ? $updated_tag : $img_tag;

                if ($first_img_tag === null) {
                    $first_img_tag = $updated_tag;
                }

                if ($strip_first_local_img && !$first_img_stripped && $first_webp_rel !== null) {
                    $first_img_stripped = true;
                    return '';
                }

                return $updated_tag;
            },
            $html
        );
        if (is_string($rewritten_imgs)) {
            $html = $rewritten_imgs;
        }

        // Also rewrite anchor hrefs that point directly to legacy Joomla images/... files
        if (stripos($html, 'images/') !== false) {
            $rewritten_img_links = preg_replace_callback(
                '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/i',
                function (array $match): string {
                    $resolved_webp_rel = null;
                    $new_url = $this->rewrite_legacy_image_src(trim($match[3]), $resolved_webp_rel);
                    if ($new_url === null) {
                        return $match[0];
                    }
                    return $match[1] . $match[2] . esc_url($new_url) . $match[2];
                },
                $html
            );
            if (is_string($rewritten_img_links)) {
                $html = $rewritten_img_links;
            }
        }

        // Expand legacy {yoogallery src=[...] ...} shortcodes into responsive 4:3 WebP galleries
        if (stripos($html, '{yoogallery') !== false) {
            $gallery_counter = 0;

            $expanded = preg_replace_callback(
                '/\{yoogallery\s+([^}]+)\}/i',
                function (array $match) use ($paths, &$first_webp_rel, &$galleries_html, &$gallery_counter, $joomla_id, $article_title): string {
                    $gallery_counter++;
                    $attr_str = $match[1];
                    $rel_dir = $this->extract_yoogallery_rel_dir($attr_str);

                    if ($rel_dir === null) {
                        return '';
                    }

                    $gallery_data = $this->build_yoogallery_block(
                        $paths,
                        $rel_dir,
                        $joomla_id,
                        $gallery_counter,
                        $article_title
                    );

                    if ($gallery_data === null) {
                        return '';
                    }

                    if ($first_webp_rel === null && $gallery_data['first_webp_rel'] !== null) {
                        $first_webp_rel = $gallery_data['first_webp_rel'];
                    }

                    $galleries_html[] = $gallery_data['html'];

                    return $gallery_data['html'];
                },
                $html
            );
            if (is_string($expanded)) {
                $html = $expanded;
            }

            // Unwrap <p><div class="scat-migrated-gallery">...</div></p> if TinyMCE wrapped the shortcode in a paragraph
            $unwrapped_yoo = preg_replace(
                '#<p[^>]*>\s*(<div class="scat-migrated-gallery"[\s\S]*?</div>)\s*</p>#i',
                '$1',
                $html
            );
            if (is_string($unwrapped_yoo)) {
                $html = $unwrapped_yoo;
            }
        }

        // Rewrite ?option=com_docman&...&gid=X and direct dmdocuments/... links
        $html = $this->replace_docman_links($html);

        // Clean legacy Joomla TinyMCE and MS Word attributes
        $cleaned = preg_replace('/\s+mce_[a-z0-9_]+\s*=\s*(["\']).*?\1/i', '', $html);
        if (is_string($cleaned)) {
            $html = $cleaned;
        }
        $cleaned = preg_replace('/\bmso-[a-z0-9_-]+\s*:\s*[^;"]+;?/i', '', $html);
        if (is_string($cleaned)) {
            $html = $cleaned;
        }
        $cleaned = preg_replace('/\s+style\s*=\s*(["\'])\s*\1/i', '', $html);
        if (is_string($cleaned)) {
            $html = $cleaned;
        }

        // Pack consecutive inline <img> sequences and <img> inside <li> into compact lightbox thumb grids
        $inline_groups_count = 0;
        $group_prefix = $joomla_id > 0 ? (string) $joomla_id : '';
        $html = $this->convert_inline_image_groups($html, $inline_groups_count, $group_prefix, $article_title);

        return [
            'html'           => trim($html),
            'first_webp_rel' => $first_webp_rel,
            'first_img_tag'  => $first_img_tag,
            'galleries_html' => $galleries_html,
        ];
    }

    /**
     * Resolve a legacy Joomla image src/href (`images/...`) to its migrated WebP (or legacy fallback) URL.
     */
    private function rewrite_legacy_image_src(string $raw_src, ?string &$resolved_webp_rel_out = null): ?string
    {
        $resolved_webp_rel_out = null;
        $rel_image_path = $this->extract_legacy_image_rel_path($raw_src);
        if ($rel_image_path === null) {
            return null;
        }

        $paths = ImageMigrator::get_paths();
        $webp_rel = ImageMigrator::to_webp_relative_path($rel_image_path);
        $resolved_webp_rel = $this->resolve_case_insensitive_file($paths['webp_dir'], $webp_rel);

        if ($resolved_webp_rel !== null) {
            $resolved_webp_rel_out = $resolved_webp_rel;
            return $this->build_encoded_url(content_url('uploads/scat-content-webp'), $resolved_webp_rel);
        }

        $resolved_legacy_rel = $this->resolve_case_insensitive_file($paths['legacy_images_dir'], $rel_image_path);
        if ($resolved_legacy_rel !== null) {
            return $this->build_encoded_url(content_url('uploads/joomla-legacy/images'), $resolved_legacy_rel);
        }

        return null;
    }

    /**
     * Rewrite any remaining legacy Joomla image URLs (`images/...`) inside post HTML to WebP/legacy URLs.
     */
    private function rewrite_legacy_image_urls(string $html, int &$rewritten_count = 0): string
    {
        if (stripos($html, 'images/') === false) {
            return $html;
        }

        $updated_imgs = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use (&$rewritten_count): string {
                $img_tag = $match[0];
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $img_tag, $src_match) !== 1) {
                    return $img_tag;
                }

                $quote = $src_match[1];
                $raw_src = trim($src_match[2]);
                $resolved_webp_rel = null;
                $new_url = $this->rewrite_legacy_image_src($raw_src, $resolved_webp_rel);
                if ($new_url === null || $new_url === $raw_src) {
                    return $img_tag;
                }

                $replaced = preg_replace(
                    '/\bsrc\s*=\s*(["\'])(.*?)\1/i',
                    'src=' . $quote . esc_url($new_url) . $quote,
                    $img_tag,
                    1
                );
                if (is_string($replaced) && $replaced !== $img_tag) {
                    $rewritten_count++;
                    return $replaced;
                }

                return $img_tag;
            },
            $html
        );
        if (is_string($updated_imgs)) {
            $html = $updated_imgs;
        }

        $updated_anchors = preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/i',
            function (array $match) use (&$rewritten_count): string {
                $raw_href = trim($match[3]);
                $resolved_webp_rel = null;
                $new_url = $this->rewrite_legacy_image_src($raw_href, $resolved_webp_rel);
                if ($new_url === null || $new_url === $raw_href) {
                    return $match[0];
                }

                $rewritten_count++;
                return $match[1] . $match[2] . esc_url($new_url) . $match[2];
            },
            $html
        );
        if (is_string($updated_anchors)) {
            $html = $updated_anchors;
        }

        return $html;
    }

    /**
     * Load cached map of DocMan ID => public file URL in /wp-content/uploads/joomla-legacy/dmdocuments/.
     *
     * @return array<int, string>
     */
    public function get_docman_map(): array
    {
        if ($this->docman_map_cache !== null) {
            return $this->docman_map_cache;
        }

        $this->docman_map_cache = [];

        global $wpdb;
        $has_docman = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_docman')) === 'jos_docman';
        if (!$has_docman) {
            return $this->docman_map_cache;
        }

        $sql = $wpdb->prepare(
            "SELECT id, dmfilename
             FROM `jos_docman`
             WHERE id > %d
               AND TRIM(dmfilename) != ''
             ORDER BY id ASC",
            0
        );
        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows) || $rows === []) {
            return $this->docman_map_cache;
        }

        $paths = ImageMigrator::get_paths();
        $docs_dir = $paths['legacy_docs_dir'];
        $docs_base_url = content_url('uploads/joomla-legacy/dmdocuments');

        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $doc_id = (int) ($row->id ?? 0);
            $raw_filename = trim((string) ($row->dmfilename ?? ''));
            if ($doc_id <= 0 || $raw_filename === '') {
                continue;
            }

            if (preg_match('#^(?:https?://|link:|javascript:)#i', $raw_filename) === 1) {
                continue;
            }

            $clean_rel = ltrim(str_replace('\\', '/', $raw_filename), '/');
            if ($clean_rel === '' || strpos($clean_rel, '..') !== false || strpos($clean_rel, "\0") !== false) {
                continue;
            }

            $actual_rel = $this->resolve_case_insensitive_file($docs_dir, $clean_rel);
            if ($actual_rel === null && strpos($clean_rel, '%') !== false) {
                $decoded = rawurldecode($clean_rel);
                if ($decoded !== '' && $decoded !== $clean_rel) {
                    $actual_rel = $this->resolve_case_insensitive_file($docs_dir, $decoded);
                }
            }
            if ($actual_rel === null && strpos($clean_rel, '&') !== false) {
                $entity_decoded = html_entity_decode($clean_rel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($entity_decoded !== '' && $entity_decoded !== $clean_rel) {
                    $actual_rel = $this->resolve_case_insensitive_file($docs_dir, $entity_decoded);
                }
            }

            if ($actual_rel === null) {
                continue;
            }

            $this->docman_map_cache[$doc_id] = $this->build_encoded_url($docs_base_url, $actual_rel);
        }

        return $this->docman_map_cache;
    }

    /**
     * Replace legacy Joomla DocMan (?option=com_docman&...&gid=X) and direct dmdocuments/... hrefs
     * with real file URLs under /wp-content/uploads/joomla-legacy/dmdocuments/.
     */
    public function replace_docman_links(string $html, int &$replaced_count = 0): string
    {
        if (stripos($html, 'option=com_docman') === false && stripos($html, 'dmdocuments') === false) {
            return $html;
        }

        $paths = ImageMigrator::get_paths();
        $docs_dir = $paths['legacy_docs_dir'];
        $docs_base_url = content_url('uploads/joomla-legacy/dmdocuments');
        $docman_map = stripos($html, 'option=com_docman') !== false ? $this->get_docman_map() : [];

        $result = preg_replace_callback(
            '/(\bhref\s*=\s*)(["\'])(.*?)\2/is',
            function (array $match) use ($docman_map, $docs_dir, $docs_base_url, &$replaced_count): string {
                $prefix = $match[1];
                $quote = $match[2];
                $raw_href = trim($match[3]);

                if ($raw_href === '') {
                    return $match[0];
                }

                if (stripos($raw_href, 'option=com_docman') !== false) {
                    $decoded_href = html_entity_decode($raw_href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $gid = 0;

                    if (preg_match('/(?:^|[?&])gid=(\d+)(?:&|#|$)/i', $decoded_href, $gm) === 1) {
                        $gid = (int) $gm[1];
                    } elseif (preg_match('/(?:^|[?&])id=(\d+)(?:&|#|$)/i', $decoded_href, $im) === 1) {
                        $gid = (int) $im[1];
                    }

                    if ($gid > 0 && isset($docman_map[$gid])) {
                        $new_url = $docman_map[$gid];
                        if ($new_url !== $raw_href) {
                            $replaced_count++;
                            return $prefix . $quote . esc_url($new_url) . $quote;
                        }
                    }

                    return $match[0];
                }

                $doc_rel = $this->extract_legacy_doc_rel_path($raw_href);
                if ($doc_rel !== null) {
                    $resolved_doc = $this->resolve_case_insensitive_file($docs_dir, $doc_rel);
                    $final_rel = $resolved_doc ?? $doc_rel;
                    $new_url = $this->build_encoded_url($docs_base_url, $final_rel);
                    if ($new_url !== $raw_href) {
                        $replaced_count++;
                        return $prefix . $quote . esc_url($new_url) . $quote;
                    }
                }

                return $match[0];
            },
            $html
        );

        return is_string($result) ? $result : $html;
    }

    /**
     * Convert consecutive <img> groups (2+ images separated by spaces/breaks/&nbsp;/adjacent <p>)
     * and 1+ <img> tags inside <li> items into compact single-line lightbox thumbnail grids.
     */
    public function convert_inline_image_groups(
        string $html,
        int &$groups_created = 0,
        string $group_prefix = '',
        string $fallback_title = ''
    ): string {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $working = $html;
        $protected_galleries = [];

        // 1. Protect any existing .scat-migrated-gallery blocks so the pass is strictly idempotent
        $step1 = preg_replace_callback(
            '~<div\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bscat-migrated-gallery\b[^"\']*\1[^>]*>(?:(?!<div\b).)*?</div>~is',
            static function (array $m) use (&$protected_galleries): string {
                $key = '[[SCAT_PROTECTED_GAL_' . count($protected_galleries) . ']]';
                $protected_galleries[$key] = $m[0];
                return $key;
            },
            $working
        );
        if (is_string($step1)) {
            $working = $step1;
        }

        if (stripos($working, '<img') === false) {
            return $html;
        }

        // 2. Protect non-image <a>...</a> anchors (e.g. PDF download icons or external partner links)
        $protected_links = [];
        $step2 = preg_replace_callback(
            '~<a\b[^>]*>(?:(?!<a\b).)*?</a>~is',
            static function (array $m) use (&$protected_links): string {
                $anchor = $m[0];
                $is_pure_image_lightbox = preg_match(
                    '~^<a\b[^>]*?\bhref\s*=\s*(["\'])([^"\']*\.(?:webp|jpe?g|png|gif)(?:[?#][^"\']*)?)\1[^>]*>(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;)*<img\b[^>]*>(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;)*</a>$~is',
                    $anchor
                ) === 1;

                if ($is_pure_image_lightbox) {
                    return $anchor;
                }

                $key = '[[SCAT_PROTECTED_LNK_' . count($protected_links) . ']]';
                $protected_links[$key] = $anchor;
                return $key;
            },
            $working
        );
        if (is_string($step2)) {
            $working = $step2;
        }

        $new_galleries = [];
        $group_index = 0;
        $local_groups_created = 0;

        // 3. Process <li>...</li> items from innermost outward (converting 1+ images inside each <li>)
        $li_placeholders = [];
        $li_pass = 0;
        while ($li_pass < 10 && stripos($working, '<li') !== false) {
            $li_pass++;
            $li_replaced = 0;

            $step_li = preg_replace_callback(
                '~(<li\b[^>]*>)((?:(?!</?li\b).)*?)(</li>)~is',
                function (array $m) use (
                    &$li_placeholders,
                    &$li_replaced,
                    &$group_index,
                    $group_prefix,
                    $fallback_title,
                    &$new_galleries,
                    &$local_groups_created
                ): string {
                    $li_replaced++;
                    $li_open = $m[1];
                    $li_body = $m[2];
                    $li_close = $m[3];

                    if (stripos($li_body, '<img') !== false) {
                        $li_groups = 0;
                        $converted_body = $this->replace_img_sequences_with_placeholders(
                            $li_body,
                            1,
                            $group_index,
                            $group_prefix,
                            $fallback_title,
                            $new_galleries,
                            $li_groups
                        );

                        if ($li_groups > 0) {
                            $local_groups_created += $li_groups;
                            $cleaned_li = preg_replace(
                                '/(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;|<br\b[^>]*\/?>)+(?=\[\[SCAT_NEW_GAL_\d+\]\])/i',
                                '',
                                $converted_body
                            );
                            $li_body = is_string($cleaned_li) ? $cleaned_li : $converted_body;
                        }
                    }

                    $key = '[[SCAT_LI_PH_' . count($li_placeholders) . ']]';
                    $li_placeholders[$key] = $li_open . $li_body . $li_close;
                    return $key;
                },
                $working
            );

            if (!is_string($step_li) || $li_replaced === 0) {
                break;
            }
            $working = $step_li;
        }

        // 4. Process sequences of 2+ consecutive images anywhere else in the article
        if (stripos($working, '<img') !== false) {
            $body_groups = 0;
            $converted_rest = $this->replace_img_sequences_with_placeholders(
                $working,
                2,
                $group_index,
                $group_prefix,
                $fallback_title,
                $new_galleries,
                $body_groups
            );
            if ($body_groups > 0) {
                $local_groups_created += $body_groups;
                $working = $converted_rest;
            }
        }

        // If nothing was grouped, return the exact original HTML untouched (preserving formatting/whitespace)
        if ($local_groups_created === 0) {
            return $html;
        }

        $groups_created += $local_groups_created;

        // 5. Restore <li> placeholders in reverse order before unwrapping wrapper <p>/<span> tags
        if ($li_placeholders !== []) {
            $working = strtr($working, array_reverse($li_placeholders, true));
        }

        // 6. Unwrap pure inline wrappers (span/strong/em/b/i/font/small) around [[SCAT_NEW_GAL_X]]
        $prev_working = '';
        $unwrap_iter = 0;
        while ($working !== $prev_working && $unwrap_iter < 5) {
            $prev_working = $working;
            $unwrap_iter++;
            $unwrapped_inline = preg_replace(
                '~<(span|strong|em|b|i|font|small)\b[^>]*>(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;|<br\b[^>]*\/?>)*(\[\[SCAT_NEW_GAL_\d+\]\])(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;|<br\b[^>]*\/?>)*</\1>~i',
                '$2',
                $working
            );
            if (is_string($unwrapped_inline)) {
                $working = $unwrapped_inline;
            }
        }

        // 7. Split or unwrap <p> tags that ended up containing block-level [[SCAT_NEW_GAL_X]] placeholders
        $unwrapped_p = preg_replace_callback(
            '~<p(\b[^>]*)>((?:(?!</p>).)*?\[\[SCAT_NEW_GAL_\d+\]\](?:(?!</p>).)*?)</p>~is',
            static function (array $pm): string {
                $p_attrs = $pm[1];
                $inner = $pm[2];
                $parts = preg_split('/(\[\[SCAT_NEW_GAL_\d+\]\])/', $inner, -1, PREG_SPLIT_DELIM_CAPTURE);
                if (!is_array($parts)) {
                    return $pm[0];
                }

                $out = '';
                foreach ($parts as $part) {
                    if (preg_match('/^\[\[SCAT_NEW_GAL_\d+\]\]$/', $part) === 1) {
                        $out .= $part;
                        continue;
                    }

                    $cleaned = preg_replace(
                        '/^(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;|<br\b[^>]*\/?>)+|(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;|<br\b[^>]*\/?>)+$/i',
                        '',
                        $part
                    );
                    $cleaned = is_string($cleaned) ? $cleaned : $part;

                    $visible_check = preg_replace('/<\/?(?:span|strong|em|b|i|font|small)\b[^>]*>|<br\b[^>]*\/?>/i', '', $cleaned);
                    $visible_check = is_string($visible_check) ? str_replace(["\xC2\xA0", '&nbsp;', '&#160;', '&#xA0;'], '', $visible_check) : '';

                    if (trim($visible_check) !== '' || stripos($cleaned, '<img') !== false || strpos($cleaned, '[[SCAT_') !== false) {
                        $balanced = function_exists('force_balance_tags') ? force_balance_tags($cleaned) : $cleaned;
                        $out .= '<p' . $p_attrs . '>' . $balanced . '</p>';
                    }
                }

                return $out;
            },
            $working
        );
        if (is_string($unwrapped_p)) {
            $working = $unwrapped_p;
        }

        // 8. Restore all placeholders in reverse order
        if ($new_galleries !== []) {
            $working = strtr($working, $new_galleries);
        }
        if ($protected_links !== []) {
            $working = strtr($working, array_reverse($protected_links, true));
        }
        if ($protected_galleries !== []) {
            $working = strtr($working, array_reverse($protected_galleries, true));
        }

        return $working;
    }

    /**
     * Find runs of image units separated only by whitespace/breaks/&nbsp;/<p> splits and replace runs >= $min_items
     * with [[SCAT_NEW_GAL_X]] tokens.
     *
     * @param array<string, string> $new_galleries
     */
    private function replace_img_sequences_with_placeholders(
        string $html,
        int $min_items,
        int &$group_index,
        string $group_prefix,
        string $fallback_title,
        array &$new_galleries,
        int &$matched_groups_count
    ): string {
        $unit = '(?:<a\b[^>]*>(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;)*<img\b[^>]*>(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;)*</a>|<img\b[^>]*>)';
        $empty_p = '(?:<p\b[^>]*>(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;|<br\b[^>]*\/?>)*</p>\s*)';
        $sep = '(?:(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;|<br\b[^>]*\/?>)|</p\b[^>]*>\s*' . $empty_p . '*<p\b[^>]*>|</span>\s*<span\b[^>]*>)*+';

        $seq_pattern = '~' . $unit . '(?:' . $sep . $unit . '){' . max(0, $min_items - 1) . ',}~is';

        $result = preg_replace_callback(
            $seq_pattern,
            function (array $match) use (
                $min_items,
                &$group_index,
                $group_prefix,
                $fallback_title,
                &$new_galleries,
                &$matched_groups_count
            ): string {
                $chunk = $match[0];
                if (preg_match_all('~(<a\b[^>]*>(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;)*)?(<img\b[^>]*>)(?:(?:\s|\xC2\xA0|&nbsp;|&#160;|&#xA0;)*</a>)?~is', $chunk, $parts, PREG_SET_ORDER) < $min_items) {
                    return $chunk;
                }

                $items = [];
                foreach ($parts as $part) {
                    $a_open = $part[1] ?? '';
                    $img_tag = $part[2];

                    if ($this->is_tiny_decorative_icon($img_tag)) {
                        continue;
                    }

                    if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $img_tag, $src_m) !== 1) {
                        continue;
                    }

                    $img_src = html_entity_decode(trim($src_m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($img_src === '' || stripos($img_src, 'data:') === 0) {
                        continue;
                    }

                    $href_src = $img_src;
                    if ($a_open !== '' && preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $a_open, $href_m) === 1) {
                        $cand_href = html_entity_decode(trim($href_m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($cand_href !== '' && preg_match('/\.(?:webp|jpe?g|png|gif)(?:[?#].*)?$/i', $cand_href) === 1) {
                            $href_src = $cand_href;
                        }
                    }

                    $alt = '';
                    if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is', $img_tag, $alt_m) === 1) {
                        $alt = trim(html_entity_decode($alt_m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }
                    if ($alt === '' && preg_match('/\btitle\s*=\s*(["\'])(.*?)\1/is', $img_tag, $title_m) === 1) {
                        $alt = trim(html_entity_decode($title_m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }

                    $items[] = [
                        'href' => $href_src,
                        'src'  => $img_src,
                        'alt'  => $alt,
                    ];
                }

                if (count($items) < $min_items) {
                    return $chunk;
                }

                $group_index++;
                $matched_groups_count++;
                $gid = ($group_prefix !== '' ? $group_prefix . '-' : '') . $group_index;
                $gallery_html = $this->build_inline_thumbs_block($items, $gid, $fallback_title);

                $key = '[[SCAT_NEW_GAL_' . count($new_galleries) . ']]';
                $new_galleries[$key] = $gallery_html;
                return $key;
            },
            $html
        );

        return is_string($result) ? $result : $html;
    }

    /**
     * Detect tiny 16x16/24x24 UI icons, smilies, or data-URI spacers that must stay inline.
     */
    private function is_tiny_decorative_icon(string $img_tag): bool
    {
        $w = null;
        $h = null;
        if (preg_match('/\bwidth\s*=\s*(["\']?)(\d{1,4})\1/i', $img_tag, $wm) === 1) {
            $w = (int) $wm[2];
        }
        if (preg_match('/\bheight\s*=\s*(["\']?)(\d{1,4})\1/i', $img_tag, $hm) === 1) {
            $h = (int) $hm[2];
        }
        if ($w !== null && $h !== null && $w > 0 && $h > 0 && $w <= 32 && $h <= 32) {
            return true;
        }

        if (preg_match('/\bsrc\s*=\s*(["\'])([^"\']+)\1/i', $img_tag, $sm) === 1) {
            $src_lower = strtolower($sm[2]);
            if (
                strpos($src_lower, 'images/m_images/') !== false
                || strpos($src_lower, '/smilies/') !== false
                || strpos($src_lower, 'data:') === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a strictly single-line compact inline thumbnail gallery with lightbox support.
     *
     * @param array<int, array{href: string, src: string, alt: string}> $items
     */
    private function build_inline_thumbs_block(array $items, string $group_id, string $fallback_title = ''): string
    {
        $clean_fallback = trim(wp_strip_all_tags(html_entity_decode($fallback_title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $links = [];

        foreach ($items as $idx => $item) {
            $alt = $item['alt'];
            if ($alt === '') {
                if ($clean_fallback !== '') {
                    $alt = sprintf('%s — фото %d', $clean_fallback, $idx + 1);
                } else {
                    $path = (string) parse_url($item['src'], PHP_URL_PATH);
                    $stem = rawurldecode((string) pathinfo($path, PATHINFO_FILENAME));
                    $alt = trim(str_replace(['_', '-'], ' ', $stem));
                    if ($alt === '') {
                        $alt = 'Фото ' . ($idx + 1);
                    }
                }
            }

            $links[] = sprintf(
                '<a href="%1$s" class="scat-gallery-item et_pb_lightbox_image" title="%3$s" data-scat-lightbox="inline-%4$s" style="position:relative;display:block;overflow:hidden;border-radius:6px;border:1px solid #cbd5e1;aspect-ratio:4/3;background:#f8fafc;line-height:0;text-decoration:none;"><img src="%2$s" alt="%3$s" loading="lazy" style="width:100%%;height:100%%;object-fit:cover;display:block;margin:0 !important;padding:0 !important;border:none;transition:transform 0.25s ease;" /></a>',
                esc_url($item['href']),
                esc_url($item['src']),
                esc_attr($alt),
                esc_attr($group_id)
            );
        }

        return '<div class="scat-migrated-gallery scat-inline-thumbs" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,180px));gap:10px;margin:10px 0 18px 0;">'
            . implode('', $links)
            . '</div>';
    }

    /**
     * Extract relative folder path inside images/ from a {yoogallery ...} attribute string.
     * Example: 'src=[/images/Fotoarchive/2019/east_expo_2019] width=[96]' -> 'Fotoarchive/2019/east_expo_2019'
     */
    private function extract_yoogallery_rel_dir(string $attr_string): ?string
    {
        $decoded = html_entity_decode($attr_string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace("\xC2\xA0", ' ', $decoded);

        $raw_src = '';
        if (preg_match('/\bsrc\s*=\s*\[([^\]]+)\]/i', $decoded, $m) === 1) {
            $raw_src = $m[1];
        } elseif (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $decoded, $m) === 1) {
            $raw_src = $m[2];
        } elseif (preg_match('/\bsrc\s*=\s*([^\s\]\}]+)/i', $decoded, $m) === 1) {
            $raw_src = $m[1];
        }

        $raw_src = rawurldecode(trim($raw_src));
        if ($raw_src === '') {
            return null;
        }

        $clean = str_replace('\\', '/', $raw_src);
        $clean = (string) preg_replace('#^https?://(?:www\.)?scat\.com\.ua/+?#i', '', $clean);
        $clean = (string) preg_replace('#^(?:\.\./)+#', '', ltrim($clean, "/ \t\n\r\0\x0B"));
        $clean = (string) preg_replace('#^images/+?#i', '', $clean);
        $clean = trim($clean, "/ \t\n\r\0\x0B");

        if ($clean === '' || strpos($clean, '..') !== false || strpos($clean, "\0") !== false) {
            return null;
        }

        return $clean;
    }

    /**
     * Case-insensitively resolve a relative directory path inside $base_dir segment by segment on Linux.
     * Returns the resolved absolute directory path on disk, or null if not found.
     */
    public function resolve_case_insensitive_dir(string $base_dir, string $rel_path): ?string
    {
        $base_dir = rtrim(str_replace('\\', '/', $base_dir), '/');
        if ($base_dir === '' || !is_dir($base_dir)) {
            return null;
        }

        $rel_path = trim(str_replace('\\', '/', $rel_path), '/');
        if ($rel_path === '' || strpos($rel_path, '..') !== false || strpos($rel_path, "\0") !== false) {
            return null;
        }

        $segments = explode('/', $rel_path);
        $current = $base_dir;

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }

            $exact_candidate = $current . '/' . $segment;
            if (is_dir($exact_candidate)) {
                $current = $exact_candidate;
                continue;
            }

            $entries = @scandir($current);
            if (!is_array($entries)) {
                return null;
            }

            $matched_entry = null;
            $seg_lower = function_exists('mb_strtolower')
                ? mb_strtolower($segment, 'UTF-8')
                : strtolower($segment);

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!is_dir($current . '/' . $entry)) {
                    continue;
                }
                $entry_lower = function_exists('mb_strtolower')
                    ? mb_strtolower($entry, 'UTF-8')
                    : strtolower($entry);

                if ($entry_lower === $seg_lower) {
                    $matched_entry = $entry;
                    break;
                }
            }

            if ($matched_entry === null) {
                return null;
            }

            $current .= '/' . $matched_entry;
        }

        return is_dir($current) ? $current : null;
    }

    /**
     * Case-insensitively resolve a relative file path inside $base_dir.
     * Returns the exact-case relative path inside $base_dir, or null if not found.
     */
    private function resolve_case_insensitive_file(string $base_dir, string $rel_file_path): ?string
    {
        $base_dir = rtrim(str_replace('\\', '/', $base_dir), '/');
        $rel_file_path = ltrim(str_replace('\\', '/', $rel_file_path), '/');

        if ($base_dir === '' || $rel_file_path === '' || strpos($rel_file_path, '..') !== false) {
            return null;
        }

        if (is_file($base_dir . '/' . $rel_file_path)) {
            return $rel_file_path;
        }

        $dir_part = dirname($rel_file_path);
        if ($dir_part === '.' || $dir_part === '/') {
            $dir_part = '';
        }
        $file_part = basename($rel_file_path);

        $resolved_dir_abs = $dir_part !== ''
            ? $this->resolve_case_insensitive_dir($base_dir, $dir_part)
            : (is_dir($base_dir) ? $base_dir : null);

        if ($resolved_dir_abs === null) {
            return null;
        }

        if (is_file($resolved_dir_abs . '/' . $file_part)) {
            $exact_dir_rel = ltrim(substr($resolved_dir_abs, strlen($base_dir)), '/');
            return ($exact_dir_rel !== '' ? $exact_dir_rel . '/' : '') . $file_part;
        }

        $entries = @scandir($resolved_dir_abs);
        if (!is_array($entries)) {
            return null;
        }

        $file_lower = function_exists('mb_strtolower')
            ? mb_strtolower($file_part, 'UTF-8')
            : strtolower($file_part);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!is_file($resolved_dir_abs . '/' . $entry)) {
                continue;
            }
            $entry_lower = function_exists('mb_strtolower')
                ? mb_strtolower($entry, 'UTF-8')
                : strtolower($entry);

            if ($entry_lower === $file_lower) {
                $exact_dir_rel = ltrim(substr($resolved_dir_abs, strlen($base_dir)), '/');
                return ($exact_dir_rel !== '' ? $exact_dir_rel . '/' : '') . $entry;
            }
        }

        return null;
    }

    /**
     * Read 1st-level images from resolved gallery folder, filter out legacy YooGallery thumbnails,
     * natural-sort them, and build the responsive 4:3 HTML gallery block.
     *
     * @param array<string, string> $paths
     * @return array{html: string, first_webp_rel: string|null}|null
     */
    private function build_yoogallery_block(
        array $paths,
        string $rel_dir,
        int $joomla_id,
        int $gallery_index,
        string $article_title
    ): ?array {
        $webp_base_dir = rtrim(str_replace('\\', '/', $paths['webp_dir']), '/');
        $legacy_base_dir = rtrim(str_replace('\\', '/', $paths['legacy_images_dir']), '/');

        $resolved_abs = $this->resolve_case_insensitive_dir($webp_base_dir, $rel_dir);
        $source_base_dir = $webp_base_dir;
        $source_base_url = content_url('uploads/scat-content-webp');
        $is_webp_source = true;

        $files = $resolved_abs !== null ? $this->scan_gallery_directory_files($resolved_abs) : [];

        if ($files === []) {
            $resolved_abs = $this->resolve_case_insensitive_dir($legacy_base_dir, $rel_dir);
            if ($resolved_abs === null) {
                return null;
            }
            $files = $this->scan_gallery_directory_files($resolved_abs);
            if ($files === []) {
                return null;
            }
            $source_base_dir = $legacy_base_dir;
            $source_base_url = content_url('uploads/joomla-legacy/images');
            $is_webp_source = false;
        }

        $exact_rel_dir = ltrim(substr($resolved_abs, strlen($source_base_dir)), '/');
        $first_file_rel = ($exact_rel_dir !== '' ? $exact_rel_dir . '/' : '') . $files[0];

        $first_webp_rel = null;
        if ($is_webp_source) {
            $first_webp_rel = $first_file_rel;
        } else {
            $candidate_webp = ImageMigrator::to_webp_relative_path($first_file_rel);
            $resolved_webp = $this->resolve_case_insensitive_file($webp_base_dir, $candidate_webp);
            if ($resolved_webp !== null) {
                $first_webp_rel = $resolved_webp;
            }
        }

        $gallery_group_id = 'gallery-' . ($joomla_id > 0 ? $joomla_id . '-' . $gallery_index : substr(md5($exact_rel_dir), 0, 8));
        $clean_title = trim(wp_strip_all_tags(html_entity_decode($article_title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $items_html = [];
        foreach ($files as $idx => $file_name) {
            $file_rel = ($exact_rel_dir !== '' ? $exact_rel_dir . '/' : '') . $file_name;
            $full_url = $this->build_encoded_url($source_base_url, $file_rel);

            $raw_stem = (string) pathinfo($file_name, PATHINFO_FILENAME);
            $alt_text = $clean_title !== ''
                ? sprintf('%s — фото %d', $clean_title, $idx + 1)
                : trim(str_replace(['_', '-'], ' ', $raw_stem));

            if ($alt_text === '') {
                $alt_text = 'Фото ' . ($idx + 1);
            }

            $items_html[] = sprintf(
                '<a href="%1$s" class="scat-gallery-item et_pb_lightbox_image" title="%2$s" data-scat-lightbox="%3$s" style="display:block;overflow:hidden;border-radius:8px;border:1px solid #e2e8f0;aspect-ratio:4/3;background:#f8fafc;line-height:0;text-decoration:none;"><img src="%1$s" alt="%2$s" loading="lazy" style="width:100%%;height:100%%;object-fit:cover;display:block;margin:0 !important;padding:0 !important;border:none;transition:transform 0.25s ease;" /></a>',
                esc_url($full_url),
                esc_attr($alt_text),
                esc_attr($gallery_group_id)
            );
        }

        $gallery_html = '<div class="scat-migrated-gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin:24px 0;">'
            . implode('', $items_html)
            . '</div>';

        return [
            'html'           => $gallery_html,
            'first_webp_rel' => $first_webp_rel,
        ];
    }

    /**
     * Scan only 1st-level image files in a gallery directory, ignoring subdirectories (e.g. thumbs/)
     * and filtering out YooGallery thumbnail files (thumb_*, *_t.webp, *thmb.webp) when main files exist.
     *
     * @return array<int, string>
     */
    private function scan_gallery_directory_files(string $abs_dir): array
    {
        $entries = @scandir($abs_dir);
        if (!is_array($entries)) {
            return [];
        }

        $main_files = [];
        $thumb_files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }

            $full_path = $abs_dir . '/' . $entry;
            if (!is_file($full_path)) {
                continue;
            }

            if (preg_match('/\.(webp|jpe?g|png|gif)$/i', $entry) !== 1) {
                continue;
            }

            $stem = (string) pathinfo($entry, PATHINFO_FILENAME);
            if (trim($stem) === '') {
                continue;
            }

            $is_thumb = preg_match('/^(?:thumb_)|(?:_t|thmb)\.(?:webp|jpe?g|png|gif)$/i', $entry) === 1;
            if ($is_thumb) {
                $thumb_files[] = $entry;
            } else {
                $main_files[] = $entry;
            }
        }

        $selected = $main_files !== [] ? $main_files : $thumb_files;
        if ($selected === []) {
            return [];
        }

        natsort($selected);

        return array_values($selected);
    }

    /**
     * Render lightweight frontend gallery lightbox with Prev/Next navigation, keyboard arrows, and Esc/backdrop close
     * when .scat-gallery-item elements exist on the page.
     */
    public function render_frontend_lightbox(): void
    {
        ?>
        <style id="scat-migrated-gallery-lightbox-css">
            .scat-migrated-gallery {
                display: grid !important;
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)) !important;
                gap: 16px !important;
                margin: 24px 0 !important;
            }
            .scat-migrated-gallery br,
            .scat-migrated-gallery p:empty {
                display: none !important;
            }
            .scat-migrated-gallery p {
                display: contents !important;
            }
            .scat-migrated-gallery .scat-gallery-item {
                line-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .scat-migrated-gallery .scat-gallery-item img {
                width: 100% !important;
                height: 100% !important;
                object-fit: cover !important;
                margin: 0 !important;
                padding: 0 !important;
                display: block !important;
            }
            .scat-migrated-gallery .scat-gallery-item:hover img {
                transform: scale(1.04);
            }
            .scat-migrated-gallery.scat-inline-thumbs {
                grid-template-columns: repeat(auto-fill, minmax(160px, 180px)) !important;
                gap: 10px !important;
                margin: 10px 0 18px 0 !important;
            }
            .scat-inline-thumbs .scat-gallery-item {
                position: relative !important;
            }
            .scat-inline-thumbs .scat-gallery-item::after {
                content: "";
                position: absolute;
                right: 6px;
                bottom: 6px;
                z-index: 2;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                background-color: rgba(15, 23, 42, 0.68);
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: center;
                background-size: 13px 13px;
                pointer-events: none;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
                transition: background-color 0.2s ease, transform 0.2s ease;
            }
            .scat-inline-thumbs .scat-gallery-item:hover::after {
                background-color: rgba(15, 23, 42, 0.92);
                transform: scale(1.08);
            }
            .scat-lb-overlay {
                position: fixed;
                inset: 0;
                z-index: 999999;
                background: rgba(15, 23, 42, 0.92);
                display: none;
                align-items: center;
                justify-content: center;
                user-select: none;
                -webkit-backdrop-filter: blur(4px);
                backdrop-filter: blur(4px);
            }
            .scat-lb-overlay.is-open {
                display: flex;
            }
            .scat-lb-figure {
                position: relative;
                max-width: 90vw;
                max-height: 86vh;
                margin: 0;
                display: flex;
                flex-direction: column;
                align-items: center;
            }
            .scat-lb-img {
                max-width: 90vw;
                max-height: 80vh;
                width: auto;
                height: auto;
                object-fit: contain;
                border-radius: 6px;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
                background: #0f172a;
            }
            .scat-lb-caption {
                margin-top: 10px;
                color: #f1f5f9;
                font-size: 14px;
                line-height: 1.4;
                text-align: center;
                max-width: 80vw;
            }
            .scat-lb-counter {
                position: fixed;
                top: 18px;
                left: 24px;
                color: #cbd5e1;
                font-size: 14px;
                font-weight: 600;
                background: rgba(15, 23, 42, 0.7);
                padding: 4px 10px;
                border-radius: 999px;
            }
            .scat-lb-btn {
                position: fixed;
                background: rgba(30, 41, 59, 0.75);
                color: #ffffff;
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                width: 46px;
                height: 46px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 26px;
                line-height: 1;
                cursor: pointer;
                transition: background 0.2s ease, transform 0.15s ease;
                padding: 0;
            }
            .scat-lb-btn:hover {
                background: rgba(51, 65, 85, 0.95);
                transform: scale(1.06);
            }
            .scat-lb-close {
                top: 16px;
                right: 20px;
                font-size: 28px;
            }
            .scat-lb-prev {
                left: 18px;
                top: 50%;
                transform: translateY(-50%);
            }
            .scat-lb-prev:hover {
                transform: translateY(-50%) scale(1.06);
            }
            .scat-lb-next {
                right: 18px;
                top: 50%;
                transform: translateY(-50%);
            }
            .scat-lb-next:hover {
                transform: translateY(-50%) scale(1.06);
            }
            @media (max-width: 640px) {
                .scat-lb-btn {
                    width: 40px;
                    height: 40px;
                    font-size: 22px;
                }
                .scat-lb-prev { left: 8px; }
                .scat-lb-next { right: 8px; }
            }
        </style>
        <script id="scat-migrated-gallery-lightbox-js">
        (function() {
            var items = document.querySelectorAll('a.scat-gallery-item');
            if (!items.length) {
                return;
            }

            var overlay = null;
            var imgEl = null;
            var captionEl = null;
            var counterEl = null;
            var prevBtn = null;
            var nextBtn = null;
            var currentGroup = [];
            var currentIndex = 0;
            var touchStartX = 0;

            function ensureModal() {
                if (overlay) {
                    return;
                }
                overlay = document.createElement('div');
                overlay.className = 'scat-lb-overlay';
                overlay.setAttribute('role', 'dialog');
                overlay.setAttribute('aria-modal', 'true');
                overlay.innerHTML =
                    '<div class="scat-lb-counter"></div>' +
                    '<button type="button" class="scat-lb-btn scat-lb-close" aria-label="Закрити">&times;</button>' +
                    '<button type="button" class="scat-lb-btn scat-lb-prev" aria-label="Попереднє фото">&#8249;</button>' +
                    '<figure class="scat-lb-figure">' +
                        '<img class="scat-lb-img" src="" alt="" />' +
                        '<figcaption class="scat-lb-caption"></figcaption>' +
                    '</figure>' +
                    '<button type="button" class="scat-lb-btn scat-lb-next" aria-label="Наступне фото">&#8250;</button>';

                document.body.appendChild(overlay);

                imgEl = overlay.querySelector('.scat-lb-img');
                captionEl = overlay.querySelector('.scat-lb-caption');
                counterEl = overlay.querySelector('.scat-lb-counter');
                prevBtn = overlay.querySelector('.scat-lb-prev');
                nextBtn = overlay.querySelector('.scat-lb-next');

                overlay.querySelector('.scat-lb-close').addEventListener('click', closeLightbox);
                prevBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    step(-1);
                });
                nextBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    step(1);
                });
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay || e.target.classList.contains('scat-lb-figure')) {
                        closeLightbox();
                    }
                });

                overlay.addEventListener('touchstart', function(e) {
                    if (e.changedTouches && e.changedTouches.length) {
                        touchStartX = e.changedTouches[0].screenX;
                    }
                }, { passive: true });

                overlay.addEventListener('touchend', function(e) {
                    if (!e.changedTouches || !e.changedTouches.length) {
                        return;
                    }
                    var dx = e.changedTouches[0].screenX - touchStartX;
                    if (Math.abs(dx) > 40) {
                        step(dx < 0 ? 1 : -1);
                    }
                }, { passive: true });
            }

            function renderCurrent() {
                if (!currentGroup.length) {
                    return;
                }
                var link = currentGroup[currentIndex];
                var href = link.getAttribute('href') || '';
                var innerImg = link.querySelector('img');
                var title = link.getAttribute('title') || (innerImg ? innerImg.getAttribute('alt') : '') || '';

                imgEl.src = href;
                imgEl.alt = title;
                captionEl.textContent = title;
                counterEl.textContent = (currentIndex + 1) + ' / ' + currentGroup.length;

                var showNav = currentGroup.length > 1;
                prevBtn.style.display = showNav ? 'flex' : 'none';
                nextBtn.style.display = showNav ? 'flex' : 'none';
            }

            function step(delta) {
                if (currentGroup.length <= 1) {
                    return;
                }
                currentIndex = (currentIndex + delta + currentGroup.length) % currentGroup.length;
                renderCurrent();
            }

            function openLightbox(clickedLink) {
                ensureModal();
                var groupName = clickedLink.getAttribute('data-scat-lightbox') || '';
                if (groupName) {
                    currentGroup = Array.prototype.slice.call(
                        document.querySelectorAll('a.scat-gallery-item[data-scat-lightbox="' + groupName.replace(/"/g, '\\"') + '"]')
                    );
                } else {
                    var parentGallery = clickedLink.closest('.scat-migrated-gallery');
                    currentGroup = parentGallery
                        ? Array.prototype.slice.call(parentGallery.querySelectorAll('a.scat-gallery-item'))
                        : [clickedLink];
                }

                currentIndex = currentGroup.indexOf(clickedLink);
                if (currentIndex < 0) {
                    currentIndex = 0;
                }

                renderCurrent();
                overlay.classList.add('is-open');
            }

            function closeLightbox() {
                if (!overlay) {
                    return;
                }
                overlay.classList.remove('is-open');
                if (imgEl) {
                    imgEl.src = '';
                }
            }

            document.addEventListener('click', function(e) {
                var link = e.target && e.target.closest ? e.target.closest('a.scat-gallery-item') : null;
                if (!link) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') {
                    e.stopImmediatePropagation();
                }
                openLightbox(link);
            }, true);

            document.addEventListener('keydown', function(e) {
                if (!overlay || !overlay.classList.contains('is-open')) {
                    return;
                }
                if (e.key === 'Escape') {
                    closeLightbox();
                } else if (e.key === 'ArrowLeft') {
                    step(-1);
                } else if (e.key === 'ArrowRight') {
                    step(1);
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Build regex prefix matching optional legacy/current site origin before relative Joomla paths.
     */
    private function build_legacy_host_prefix_regex(): string
    {
        $hosts = ['(?:www\.)?scat\.com\.ua'];
        $home_host = (string) parse_url(home_url(), PHP_URL_HOST);
        if ($home_host !== '' && stripos($home_host, 'scat.com.ua') === false) {
            $hosts[] = preg_quote(ltrim($home_host, '.'), '#');
        }

        return '(?:https?://(?:' . implode('|', $hosts) . '))?';
    }

    /**
     * Extract relative path inside Joomla images/ folder from legacy src/href attribute.
     */
    private function extract_legacy_image_rel_path(string $src): ?string
    {
        if ($src === '' || stripos($src, 'data:') === 0) {
            return null;
        }

        $clean = preg_replace('/[?#].*$/', '', $src);
        $clean = is_string($clean) ? trim($clean) : '';

        $pattern = '#^' . $this->build_legacy_host_prefix_regex() . '/?(?:\.\./)*images/(.+)$#i';
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

        $pattern = '#^' . $this->build_legacy_host_prefix_regex() . '/?(?:\.\./)*dmdocuments/(.+)$#i';
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
        $decoded = (string) preg_replace('/\{yoogallery\b[^}]*\}/i', ' ', $decoded);
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

        $paths = ImageMigrator::get_paths();
        $resolved_rel = $this->resolve_case_insensitive_file($paths['webp_dir'], $webp_rel);
        if ($resolved_rel !== null) {
            $webp_rel = $resolved_rel;
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
     * Parse JSON/CSV/array of integer IDs from $_POST.
     *
     * @param mixed $raw
     * @return array<int, int>
     */
    private function parse_id_list($raw, bool $allow_zero): array
    {
        $unslashed = wp_unslash($raw);
        if (is_string($unslashed)) {
            $trimmed = trim($unslashed);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            $items = is_array($decoded) ? $decoded : explode(',', $trimmed);
        } elseif (is_array($unslashed)) {
            $items = $unslashed;
        } else {
            return [];
        }

        $ids = [];
        foreach ($items as $val) {
            if (is_numeric($val)) {
                $int_val = (int) $val;
                if ($allow_zero ? $int_val >= 0 : $int_val > 0) {
                    $ids[] = $int_val;
                }
            }
        }

        return array_values(array_unique($ids));
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
