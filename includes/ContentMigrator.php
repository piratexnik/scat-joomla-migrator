<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class ContentMigrator
{
    public const AJAX_CREATE_WP_CAT_ACTION = 'scat_migrator_create_wp_cat';
    public const AJAX_IMPORT_BATCH_ACTION = 'scat_migrator_import_articles_batch';
    public const AJAX_AI_TRANSLATE_ACTION = 'scat_migrator_ai_translate_recent';

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

    public function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_CREATE_WP_CAT_ACTION, [$this, 'ajax_create_wp_cat']);
        add_action('wp_ajax_' . self::AJAX_IMPORT_BATCH_ACTION, [$this, 'ajax_import_articles_batch']);
        add_action('wp_ajax_' . self::AJAX_AI_TRANSLATE_ACTION, [$this, 'ajax_ai_translate_recent']);
    }

    /**
     * AJAX handler: create and link 3 Polylang categories (UK + RU + EN) in 1 click.
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
            $slug_uk = ($base_uk !== '' ? $base_uk : 'category') . '-uk';
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
                $slug_ru = ($base_ru !== '' ? $base_ru : $slug_uk) . '-ru';
            }
            $items['ru'] = [
                'name' => $name_ru,
                'slug' => $slug_ru,
            ];
        }

        if ($name_en !== '') {
            if ($slug_en === '') {
                $base_en = sanitize_title($name_en);
                $slug_en = ($base_en !== '' ? $base_en : $slug_uk) . '-en';
            }
            $items['en'] = [
                'name' => $name_en,
                'slug' => $slug_en,
            ];
        }

        $term_translations = [];
        $created_summary = [];

        foreach ($items as $lang_code => $item) {
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
            'term_id'         => $uk_term_id,
            'translations'    => $term_translations,
            'summary'         => implode(' | ', $created_summary),
            'wp_categories'   => DatabaseAnalyzer::get_wp_categories_list(),
        ]);
    }

    /**
     * Find or create a single category term and assign its Polylang language.
     */
    private function get_or_create_category_term(string $name, string $slug, string $lang): int
    {
        $existing = get_term_by('slug', $slug, 'category');
        if ($existing instanceof \WP_Term) {
            $term_id = (int) $existing->term_id;
        } else {
            $inserted = wp_insert_term($name, 'category', ['slug' => $slug]);
            if (is_wp_error($inserted)) {
                $existing_id = $inserted->get_error_data('term_exists');
                if (is_numeric($existing_id) && (int) $existing_id > 0) {
                    $term_id = (int) $existing_id;
                } else {
                    return 0;
                }
            } else {
                $term_id = (int) ($inserted['term_id'] ?? 0);
            }
        }

        if ($term_id > 0 && function_exists('pll_set_term_language')) {
            pll_set_term_language($term_id, $lang);
        }

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
     * AJAX handler: AI batch translation of recent N posts in a selected UK category via Polyland-Up (plu_translate_dictionary).
     */
    public function ajax_ai_translate_recent(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        if (!function_exists('plu_is_translator_ready') || !plu_is_translator_ready() || !function_exists('plu_translate_dictionary')) {
            wp_send_json_error([
                'message' => __('AI-перекладач Polyland-Up не активний або функція plu_translate_dictionary() недоступна.', 'scat-joomla-migrator'),
            ], 400);
        }

        if (!function_exists('pll_get_post_translations') || !function_exists('pll_save_post_translations') || !function_exists('pll_set_post_language')) {
            wp_send_json_error([
                'message' => __('Плагін Polylang не активний.', 'scat-joomla-migrator'),
            ], 400);
        }

        $cat_id = isset($_POST['cat_id']) ? max(0, (int) wp_unslash($_POST['cat_id'])) : 0;
        if ($cat_id <= 0) {
            wp_send_json_error(['message' => __('Виберіть цільову рубрику WordPress (UK).', 'scat-joomla-migrator')], 400);
        }

        $limit = isset($_POST['limit']) ? (int) wp_unslash($_POST['limit']) : 25;
        $limit = max(1, min(200, $limit));

        $offset = isset($_POST['offset']) ? max(0, (int) wp_unslash($_POST['offset'])) : 0;

        $translate_en = !isset($_POST['translate_en']) || $this->to_bool($_POST['translate_en']);
        $translate_ru = !isset($_POST['translate_ru']) || $this->to_bool($_POST['translate_ru']);

        $target_langs = [];
        if ($translate_ru) {
            $target_langs[] = 'ru';
        }
        if ($translate_en) {
            $target_langs[] = 'en';
        }

        if ($target_langs === []) {
            wp_send_json_error(['message' => __('Виберіть хоча б одну цільову мову (RU або EN).', 'scat-joomla-migrator')], 400);
        }

        @set_time_limit(180);

        $query_args = [
            'post_type'              => 'post',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'orderby'                => ['date' => 'DESC', 'ID' => 'DESC'],
            'lang'                   => 'uk',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => [
                [
                    'taxonomy'         => 'category',
                    'field'            => 'term_id',
                    'terms'            => [$cat_id],
                    'include_children' => false,
                ],
            ],
        ];

        $posts = get_posts($query_args);
        if (!is_array($posts)) {
            $posts = [];
        }

        $total = count($posts);
        if ($total === 0 || $offset >= $total) {
            wp_send_json_success([
                'processed'       => 0,
                'created_count'   => 0,
                'next_offset'     => $total,
                'total'           => $total,
                'percent'         => 100,
                'logs'            => [],
            ]);
        }

        $source_post = $posts[$offset];
        if (!$source_post instanceof \WP_Post) {
            wp_send_json_success([
                'processed'     => 1,
                'created_count' => 0,
                'next_offset'   => $offset + 1,
                'total'         => $total,
                'percent'       => (int) round((($offset + 1) / $total) * 100),
                'logs'          => [],
            ]);
        }

        $source_id = (int) $source_post->ID;
        $existing_translations = pll_get_post_translations($source_id);
        if (!is_array($existing_translations)) {
            $existing_translations = [];
        }
        $existing_translations['uk'] = $source_id;

        $created_count = 0;
        $logs = [];

        foreach ($target_langs as $t_lang) {
            if (!empty($existing_translations[$t_lang])) {
                $existing_tr_id = (int) $existing_translations[$t_lang];
                $logs[] = [
                    'status'    => 'skipped',
                    'joomla_id' => $source_id,
                    'post_id'   => $existing_tr_id,
                    'title'     => '[' . strtoupper($t_lang) . '] ' . $source_post->post_title,
                    'url'       => (string) get_permalink($existing_tr_id),
                    'edit_url'  => admin_url('post.php?post=' . $existing_tr_id . '&action=edit'),
                    'message'   => sprintf('Вже має переклад %s (WP #%d)', strtoupper($t_lang), $existing_tr_id),
                ];
                continue;
            }

            $dict = [
                'title'   => (string) $source_post->post_title,
                'excerpt' => (string) $source_post->post_excerpt,
                'content' => (string) $source_post->post_content,
            ];

            $translated = plu_translate_dictionary($dict, 'uk', $t_lang);
            if (is_wp_error($translated)) {
                $logs[] = [
                    'status'    => 'error',
                    'joomla_id' => $source_id,
                    'post_id'   => 0,
                    'title'     => '[' . strtoupper($t_lang) . '] ' . $source_post->post_title,
                    'url'       => '',
                    'edit_url'  => '',
                    'message'   => 'Помилка AI: ' . $translated->get_error_message(),
                ];
                continue;
            }

            if (!is_array($translated) || empty($translated['title'])) {
                $logs[] = [
                    'status'    => 'error',
                    'joomla_id' => $source_id,
                    'post_id'   => 0,
                    'title'     => '[' . strtoupper($t_lang) . '] ' . $source_post->post_title,
                    'url'       => '',
                    'edit_url'  => '',
                    'message'   => 'Порожня відповідь від plu_translate_dictionary().',
                ];
                continue;
            }

            $tr_title = trim((string) ($translated['title'] ?? $source_post->post_title));
            $tr_excerpt = trim((string) ($translated['excerpt'] ?? $source_post->post_excerpt));
            $tr_content = (string) ($translated['content'] ?? $source_post->post_content);

            $base_slug = preg_replace('/-(uk|ru|en)$/', '', (string) $source_post->post_name);
            $base_slug = is_string($base_slug) && $base_slug !== '' ? $base_slug : 'article-' . $source_id;
            $new_slug = $base_slug . '-' . $t_lang;

            $new_post_id = wp_insert_post(wp_slash([
                'post_type'     => 'post',
                'post_title'    => $tr_title,
                'post_name'     => $new_slug,
                'post_excerpt'  => $tr_excerpt,
                'post_content'  => $tr_content,
                'post_status'   => $source_post->post_status,
                'post_date'     => $source_post->post_date,
                'post_date_gmt' => $source_post->post_date_gmt,
            ]), true);

            if (is_wp_error($new_post_id) || $new_post_id <= 0) {
                $logs[] = [
                    'status'    => 'error',
                    'joomla_id' => $source_id,
                    'post_id'   => 0,
                    'title'     => '[' . strtoupper($t_lang) . '] ' . $tr_title,
                    'url'       => '',
                    'edit_url'  => '',
                    'message'   => is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'Помилка створення запису.',
                ];
                continue;
            }

            $new_post_id = (int) $new_post_id;
            pll_set_post_language($new_post_id, $t_lang);

            $target_cat = $cat_id;
            if (function_exists('pll_get_term')) {
                $tr_cat = (int) pll_get_term($cat_id, $t_lang);
                if ($tr_cat > 0) {
                    $target_cat = $tr_cat;
                }
            }
            wp_set_post_categories($new_post_id, [$target_cat], false);

            $thumb_id = (int) get_post_thumbnail_id($source_id);
            if ($thumb_id > 0) {
                set_post_thumbnail($new_post_id, $thumb_id);
            }

            $joomla_orig_id = (int) get_post_meta($source_id, self::META_JOOMLA_ID, true);
            if ($joomla_orig_id > 0) {
                update_post_meta($new_post_id, self::META_JOOMLA_ID, $joomla_orig_id);
            }
            update_post_meta($new_post_id, self::META_JOOMLA_LANG, $t_lang);

            $existing_translations[$t_lang] = $new_post_id;
            $created_count++;

            $logs[] = [
                'status'    => 'imported',
                'joomla_id' => $source_id,
                'post_id'   => $new_post_id,
                'title'     => '[' . strtoupper($t_lang) . '] ' . $tr_title,
                'url'       => (string) get_permalink($new_post_id),
                'edit_url'  => admin_url('post.php?post=' . $new_post_id . '&action=edit'),
                'message'   => sprintf('🤖 AI-переклад створено WP #%d (%s)', $new_post_id, (string) get_post_field('post_name', $new_post_id)),
            ];
        }

        if (count($existing_translations) > 1) {
            pll_save_post_translations($existing_translations);
        }

        $next_offset = $offset + 1;
        $percent = (int) round(($next_offset / $total) * 100);

        wp_send_json_success([
            'processed'     => 1,
            'created_count' => $created_count,
            'next_offset'   => $next_offset,
            'total'         => $total,
            'percent'       => $percent,
            'logs'          => $logs,
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
        $orig_scan = $this->process_article_html($orig_combined, false);
        $orig_first_webp = $orig_scan['first_webp_rel'];
        $orig_first_img_tag = $orig_scan['first_img_tag'];

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

        $orig_intro = (string) ($row->introtext ?? '');
        $orig_full = (string) ($row->fulltext ?? '');
        $orig_combined = $this->combine_intro_and_fulltext($orig_intro, $orig_full);
        $orig_scan = $this->process_article_html($orig_combined, false);
        $orig_first_webp = $orig_scan['first_webp_rel'];
        $orig_first_img_tag = $orig_scan['first_img_tag'];

        $fields = [
            'title'     => (string) ($row->title ?? ''),
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
        $orig_scan = $this->process_article_html($orig_combined, false);
        $orig_first_webp = $orig_scan['first_webp_rel'];
        $orig_first_img_tag = $orig_scan['first_img_tag'];

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
            $skip_existing,
            $options
        );
    }

    /**
     * Save multiple language versions of a Joomla article and link them via pll_save_post_translations().
     *
     * @param object                               $row
     * @param array<string, array<string, string>> $versions
     * @param string|null                          $orig_first_webp
     * @param string|null                          $orig_first_img_tag
     * @param bool                                 $skip_existing
     * @param array<string, mixed>                 $options
     * @return array{imported: int, skipped: int, logs: array<int, array<string, mixed>>}
     */
    private function persist_and_link_versions(
        object $row,
        array $versions,
        ?string $orig_first_webp,
        ?string $orig_first_img_tag,
        bool $skip_existing,
        array $options
    ): array {
        $joomla_id = (int) $row->id;
        $translations_map = [];
        $imported = 0;
        $skipped = 0;
        $logs = [];

        foreach ($versions as $lang_code => $ver_fields) {
            $existing_id = $this->find_post_by_joomla_id($joomla_id, $lang_code);

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

        $is_multilang_mode = in_array($jf_mode, [self::JF_MODE_HYBRID_FULL_UK, self::JF_MODE_MULTILANG_PLL], true);

        if ($slug_mode === 'date') {
            $desired_slug = 'article-' . gmdate('Y-m-d', $timestamp);
            if ($is_multilang_mode && $lang_code !== 'uk') {
                $desired_slug .= '-' . $lang_code;
            }
        } else {
            $raw_alias = trim($fields['alias']);
            $desired_slug = $raw_alias !== '' ? sanitize_title($raw_alias) : sanitize_title($title);
            if ($desired_slug === '') {
                $desired_slug = 'article-' . $joomla_id;
            }
            if ($is_multilang_mode && $lang_code !== 'uk') {
                $desired_slug .= '-' . $lang_code;
            }
        }

        $combined_html = $this->combine_intro_and_fulltext($fields['introtext'], $fields['fulltext']);
        $processed = $this->process_article_html($combined_html, $set_featured_image && $strip_first_img);

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

        $excerpt_source = trim($fields['introtext']) !== '' ? $fields['introtext'] : $fields['fulltext'];
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
            pll_set_post_language($post_id, $lang_code);
        }

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
            $thumb_id = $this->get_or_register_webp_thumbnail($first_webp_rel);
            if ($thumb_id > 0) {
                set_post_thumbnail($post_id, $thumb_id);
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
     * Find existing WordPress post ID by _joomla_content_id and language.
     */
    private function find_post_by_joomla_id(int $joomla_id, string $lang_code = ''): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value = %s
               AND p.post_status != 'trash'",
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

        foreach ($post_ids as $pid_raw) {
            $pid = (int) $pid_raw;
            if (function_exists('pll_get_post_language')) {
                $pll_lang = pll_get_post_language($pid);
                if (is_string($pll_lang) && $pll_lang === $lang_code) {
                    return $pid;
                }
            }

            $meta_lang = get_post_meta($pid, self::META_JOOMLA_LANG, true);
            if (is_string($meta_lang) && $meta_lang === $lang_code) {
                return $pid;
            }
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
     * Rewrite image src to scat-content-webp, rewrite dmdocuments links, and clean legacy editor attributes.
     *
     * @return array{html: string, first_webp_rel: string|null, first_img_tag: string|null}
     */
    private function process_article_html(string $html, bool $strip_first_local_img): array
    {
        $paths = ImageMigrator::get_paths();
        $first_webp_rel = null;
        $first_img_tag = null;
        $first_img_stripped = false;

        $html = (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use ($paths, &$first_webp_rel, &$first_img_tag, &$first_img_stripped, $strip_first_local_img): string {
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

                $updated_tag = (string) preg_replace(
                    '/\bsrc\s*=\s*(["\'])(.*?)\1/i',
                    'src=' . $quote . esc_url($new_url) . $quote,
                    $img_tag,
                    1
                );

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
            'first_img_tag'  => $first_img_tag,
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
