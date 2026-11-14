<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class DatabaseAnalyzer
{
    public const AJAX_LOAD_SQL_ACTION = 'scat_migrator_load_sql';
    public const AJAX_DROP_TABLES_ACTION = 'scat_migrator_drop_tables';
    public const AJAX_ANALYZE_DB_ACTION = 'scat_migrator_analyze_db';
    public const AJAX_PREVIEW_CATEGORY_ACTION = 'scat_migrator_preview_category';

    /**
     * Only these 6 Joomla tables are imported from the 98 MB SQL dump.
     */
    public const ALLOWED_TABLES = [
        'jos_sections',
        'jos_categories',
        'jos_content',
        'jos_languages',
        'jos_jf_content',
        'jos_docman',
    ];

    public function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_LOAD_SQL_ACTION, [$this, 'ajax_load_sql']);
        add_action('wp_ajax_' . self::AJAX_DROP_TABLES_ACTION, [$this, 'ajax_drop_tables']);
        add_action('wp_ajax_' . self::AJAX_ANALYZE_DB_ACTION, [$this, 'ajax_analyze_db']);
        add_action('wp_ajax_' . self::AJAX_PREVIEW_CATEGORY_ACTION, [$this, 'ajax_preview_category']);
    }

    /**
     * Read jos_languages and map language_id <-> normalized language code ('uk', 'ru', 'en').
     *
     * @return array{
     *   by_id: array<int, string>,
     *   by_code: array<string, int>
     * }
     */
    public static function get_joomla_languages(): array
    {
        global $wpdb;

        $by_id = [];
        $by_code = [];

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_languages'));
        if ($exists !== 'jos_languages') {
            return ['by_id' => $by_id, 'by_code' => $by_code];
        }

        $rows = $wpdb->get_results('SELECT * FROM `jos_languages` ORDER BY id ASC');
        if (!is_array($rows)) {
            return ['by_id' => $by_id, 'by_code' => $by_code];
        }

        foreach ($rows as $row) {
            if (!is_object($row) || empty($row->id)) {
                continue;
            }

            $lang_id = (int) $row->id;
            $code = self::normalize_joomla_lang_code(
                (string) ($row->shortcode ?? ''),
                (string) ($row->code ?? ''),
                (string) ($row->iso ?? ''),
                (string) ($row->name ?? '')
            );

            if ($code !== '') {
                $by_id[$lang_id] = $code;
                if (!isset($by_code[$code])) {
                    $by_code[$code] = $lang_id;
                }
            }
        }

        return [
            'by_id'   => $by_id,
            'by_code' => $by_code,
        ];
    }

    /**
     * Normalize Joomla / Joom!Fish language identifiers into standard 'uk', 'ru', 'en'.
     */
    public static function normalize_joomla_lang_code(
        string $shortcode,
        string $code,
        string $iso = '',
        string $name = ''
    ): string {
        $haystack = strtolower(trim($shortcode . ' ' . $code . ' ' . $iso . ' ' . $name));

        if (
            preg_match('/\b(uk|ua|uk-ua|ukr|ukrainian)\b/i', $haystack) === 1
            || strpos($haystack, 'укр') !== false
        ) {
            return 'uk';
        }

        if (
            preg_match('/\b(ru|ru-ru|rus|russian)\b/i', $haystack) === 1
            || strpos($haystack, 'рус') !== false
        ) {
            return 'ru';
        }

        if (
            preg_match('/\b(en|en-gb|en-us|eng|english)\b/i', $haystack) === 1
            || strpos($haystack, 'англ') !== false
        ) {
            return 'en';
        }

        $clean = strtolower(trim($shortcode));
        if ($clean !== '') {
            return substr($clean, 0, 2);
        }

        return '';
    }

    /**
     * Find SQL dump file in /wp-content/uploads/joomla-legacy/ and inspect table status.
     *
     * @return array{
     *   sql_found: bool,
     *   sql_filename: string,
     *   sql_path: string,
     *   sql_size_bytes: int,
     *   sql_size_human: string,
     *   tables: array<string, array{exists: bool, rows: int}>
     * }
     */
    public static function get_environment_status(): array
    {
        $uploads = wp_upload_dir();
        $basedir = untrailingslashit((string) ($uploads['basedir'] ?? ''));
        $legacy_dir = $basedir . '/joomla-legacy';

        $sql_path = '';
        $sql_filename = '';
        $sql_size = 0;

        $preferred = $legacy_dir . '/ya615591_scat2.sql';
        if (is_file($preferred) && is_readable($preferred)) {
            $sql_path = $preferred;
            $sql_filename = 'ya615591_scat2.sql';
            $sql_size = (int) @filesize($preferred);
        } elseif (is_dir($legacy_dir)) {
            $files = @scandir($legacy_dir);
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (strtolower((string) pathinfo($f, PATHINFO_EXTENSION)) === 'sql') {
                        $candidate = $legacy_dir . '/' . $f;
                        if (is_file($candidate) && is_readable($candidate)) {
                            $sql_path = $candidate;
                            $sql_filename = $f;
                            $sql_size = (int) @filesize($candidate);
                            break;
                        }
                    }
                }
            }
        }

        return [
            'sql_found'      => $sql_path !== '',
            'sql_filename'   => $sql_filename,
            'sql_path'       => $sql_path,
            'sql_size_bytes' => $sql_size,
            'sql_size_human' => $sql_size > 0 ? (string) size_format($sql_size, 1) : '0 Б',
            'tables'         => self::get_tables_status(),
        ];
    }

    /**
     * Check existence and row counts for the 6 allowed Joomla tables.
     *
     * @return array<string, array{exists: bool, rows: int}>
     */
    public static function get_tables_status(): array
    {
        global $wpdb;

        $status = [];
        foreach (self::ALLOWED_TABLES as $table) {
            $exists_val = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $exists = ($exists_val === $table);
            $rows = 0;

            if ($exists) {
                $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            }

            $status[$table] = [
                'exists' => $exists,
                'rows'   => $rows,
            ];
        }

        return $status;
    }

    /**
     * AJAX handler: stream-parse the SQL file line by line and import only the 6 allowed tables.
     */
    public function ajax_load_sql(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $env = self::get_environment_status();
        if (!$env['sql_found'] || !is_readable($env['sql_path'])) {
            wp_send_json_error([
                'message' => __('SQL-файл не знайдено у папці /wp-content/uploads/joomla-legacy/.', 'scat-joomla-migrator'),
            ], 400);
        }

        @set_time_limit(300);

        $handle = @fopen($env['sql_path'], 'rb');
        if ($handle === false) {
            wp_send_json_error([
                'message' => __('Не вдалося відкрити SQL-файл для потокового читання.', 'scat-joomla-migrator'),
            ], 500);
        }

        global $wpdb;
        $wpdb->hide_errors();

        foreach (self::ALLOWED_TABLES as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        $allowed_lookup = array_fill_keys(self::ALLOWED_TABLES, true);
        $capturing = false;
        $buffer = '';
        $in_single_quote = false;
        $in_double_quote = false;
        $escaped = false;

        $executed_queries = 0;
        $failed_queries = 0;
        $last_error = '';

        while (($line = fgets($handle)) !== false) {
            if (!$capturing) {
                $trimmed = ltrim($line);
                if (
                    $trimmed === ''
                    || strpos($trimmed, '--') === 0
                    || strpos($trimmed, '#') === 0
                    || strpos($trimmed, '/*') === 0
                ) {
                    continue;
                }

                if (
                    preg_match(
                        '/^(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO|ALTER\s+TABLE)\s+`?([a-zA-Z0-9_]+)`?/i',
                        $trimmed,
                        $matches
                    ) === 1
                ) {
                    $table_name = strtolower($matches[1]);
                    if (isset($allowed_lookup[$table_name])) {
                        $capturing = true;
                        $buffer = '';
                        $in_single_quote = false;
                        $in_double_quote = false;
                        $escaped = false;
                    }
                }
            }

            if ($capturing) {
                $buffer .= $line;
                $len = strlen($line);

                for ($i = 0; $i < $len; $i++) {
                    $ch = $line[$i];

                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }

                    if ($ch === '\\') {
                        if ($in_single_quote || $in_double_quote) {
                            $escaped = true;
                        }
                        continue;
                    }

                    if ($ch === "'" && !$in_double_quote) {
                        $in_single_quote = !$in_single_quote;
                    } elseif ($ch === '"' && !$in_single_quote) {
                        $in_double_quote = !$in_double_quote;
                    }
                }

                if (!$in_single_quote && !$in_double_quote) {
                    $rtrimmed = rtrim($line);
                    if (substr($rtrimmed, -1) === ';') {
                        $res = $wpdb->query($buffer);
                        if ($res === false) {
                            $failed_queries++;
                            if ($last_error === '' && $wpdb->last_error !== '') {
                                $last_error = $wpdb->last_error;
                            }
                        } else {
                            $executed_queries++;
                        }

                        $capturing = false;
                        $buffer = '';
                    }
                }
            }
        }

        fclose($handle);

        $tables_status = self::get_tables_status();

        wp_send_json_success([
            'executed_queries' => $executed_queries,
            'failed_queries'   => $failed_queries,
            'last_error'       => $last_error,
            'tables'           => $tables_status,
        ]);
    }

    /**
     * AJAX handler: drop temporary jos_* tables from WordPress database.
     */
    public function ajax_drop_tables(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        global $wpdb;
        foreach (self::ALLOWED_TABLES as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        wp_send_json_success([
            'tables' => self::get_tables_status(),
        ]);
    }

    /**
     * AJAX handler: analyze Joomla sections, categories, articles, Joom!Fish UK/EN translations, and migrated WP posts.
     */
    public function ajax_analyze_db(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $tables = self::get_tables_status();
        if (
            empty($tables['jos_sections']['exists'])
            || empty($tables['jos_categories']['exists'])
            || empty($tables['jos_content']['exists'])
        ) {
            wp_send_json_error([
                'message' => __('Таблиці jos_sections, jos_categories або jos_content ще не завантажені в БД. Спочатку натисніть «Завантажити таблиці контенту з SQL-файлу».', 'scat-joomla-migrator'),
                'tables'  => $tables,
            ], 400);
        }

        global $wpdb;

        $has_jf = !empty($tables['jos_jf_content']['exists']);
        $langs_map = self::get_joomla_languages();
        $uk_lang_id = (int) ($langs_map['by_code']['uk'] ?? 0);
        $en_lang_id = (int) ($langs_map['by_code']['en'] ?? 0);

        $section_uk_titles = [];
        $category_uk_titles = [];

        if ($has_jf && $uk_lang_id > 0) {
            $jf_terms_sql = $wpdb->prepare(
                "SELECT reference_table, reference_id, value
                 FROM `jos_jf_content`
                 WHERE language_id = %d
                   AND reference_table IN ('sections', 'categories')
                   AND reference_field = 'title'
                   AND TRIM(value) != ''",
                $uk_lang_id
            );
            $jf_term_rows = is_string($jf_terms_sql) ? $wpdb->get_results($jf_terms_sql) : [];
            if (is_array($jf_term_rows)) {
                foreach ($jf_term_rows as $tr) {
                    if (!is_object($tr)) {
                        continue;
                    }
                    $ref_id = (int) $tr->reference_id;
                    $val = trim(wp_strip_all_tags((string) $tr->value));
                    if ($val === '') {
                        continue;
                    }
                    if ($tr->reference_table === 'sections') {
                        $section_uk_titles[$ref_id] = $val;
                    } elseif ($tr->reference_table === 'categories') {
                        $category_uk_titles[$ref_id] = $val;
                    }
                }
            }
        }

        if ($has_jf && $uk_lang_id > 0) {
            $jf_uk_subquery = $wpdb->prepare(
                "(SELECT COUNT(DISTINCT jf_uk.reference_id)
                  FROM `jos_jf_content` jf_uk
                  INNER JOIN `jos_content` jc_uk ON jc_uk.id = jf_uk.reference_id
                  WHERE jf_uk.reference_table = 'content'
                    AND jf_uk.language_id = %d
                    AND TRIM(jf_uk.value) != ''
                    AND jc_uk.catid = c.catid
                    AND jc_uk.sectionid = c.sectionid)",
                $uk_lang_id
            );
        } else {
            $jf_uk_subquery = '0';
        }

        if ($has_jf && $en_lang_id > 0) {
            $jf_en_subquery = $wpdb->prepare(
                "(SELECT COUNT(DISTINCT jf_en.reference_id)
                  FROM `jos_jf_content` jf_en
                  INNER JOIN `jos_content` jc_en ON jc_en.id = jf_en.reference_id
                  WHERE jf_en.reference_table = 'content'
                    AND jf_en.language_id = %d
                    AND TRIM(jf_en.value) != ''
                    AND jc_en.catid = c.catid
                    AND jc_en.sectionid = c.sectionid)",
                $en_lang_id
            );
        } else {
            $jf_en_subquery = '0';
        }

        $wp_migrated_subquery = $wpdb->prepare(
            "(SELECT COUNT(DISTINCT jc3.id)
              FROM `jos_content` jc3
              INNER JOIN {$wpdb->postmeta} pm ON pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) = jc3.id
              INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status != 'trash'
              WHERE jc3.catid = c.catid AND jc3.sectionid = c.sectionid)",
            ContentMigrator::META_JOOMLA_ID
        );

        $sql = "
            SELECT
                c.sectionid AS section_id,
                COALESCE(s.title, '') AS section_title,
                c.catid AS cat_id,
                COALESCE(cat.title, '') AS cat_title,
                SUM(CASE WHEN c.state = 1 THEN 1 ELSE 0 END) AS published_count,
                SUM(CASE WHEN c.state = -1 THEN 1 ELSE 0 END) AS archived_count,
                SUM(CASE WHEN c.state = 0 THEN 1 ELSE 0 END) AS draft_count,
                COUNT(c.id) AS total_count,
                MIN(NULLIF(c.created, '0000-00-00 00:00:00')) AS min_date,
                MAX(NULLIF(c.created, '0000-00-00 00:00:00')) AS max_date,
                {$jf_uk_subquery} AS jf_uk_count,
                {$jf_en_subquery} AS jf_en_count,
                {$wp_migrated_subquery} AS migrated_wp_count
            FROM `jos_content` c
            LEFT JOIN `jos_sections` s ON s.id = c.sectionid
            LEFT JOIN `jos_categories` cat ON cat.id = c.catid
            GROUP BY c.sectionid, c.catid, s.title, cat.title
            ORDER BY c.sectionid ASC, c.catid ASC
        ";

        $rows = $wpdb->get_results($sql);
        $categories_out = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_object($row)) {
                    continue;
                }

                $sec_id = (int) $row->section_id;
                $cat_id = (int) $row->cat_id;
                $sec_title = trim((string) $row->section_title);
                $cat_title = trim((string) $row->cat_title);

                if ($sec_id === 0 && $cat_id === 0) {
                    $sec_title = $sec_title !== '' ? $sec_title : 'Статичні матеріали (Uncategorised)';
                    $cat_title = $cat_title !== '' ? $cat_title : 'Без категорії (catid=0)';
                } else {
                    if ($sec_title === '') {
                        $sec_title = 'Розділ #' . $sec_id;
                    }
                    if ($cat_title === '') {
                        $cat_title = 'Категорія #' . $cat_id;
                    }
                }

                $sec_uk = $section_uk_titles[$sec_id] ?? '';
                if ($sec_uk !== '' && $sec_uk !== $sec_title) {
                    $sec_display = $sec_title . ' (🇺🇦 ' . $sec_uk . ')';
                } else {
                    $sec_display = $sec_title;
                }

                $cat_uk = $category_uk_titles[$cat_id] ?? '';
                if ($cat_uk !== '' && $cat_uk !== $cat_title) {
                    $cat_display = $cat_title . ' (🇺🇦 ' . $cat_uk . ')';
                } else {
                    $cat_display = $cat_title;
                }

                $min_date = !empty($row->min_date) ? substr((string) $row->min_date, 0, 10) : '—';
                $max_date = !empty($row->max_date) ? substr((string) $row->max_date, 0, 10) : '—';

                $categories_out[] = [
                    'key'               => $sec_id . ':' . $cat_id,
                    'section_id'        => $sec_id,
                    'section_title'     => $sec_display,
                    'cat_id'            => $cat_id,
                    'cat_title'         => $cat_display,
                    'published_count'   => (int) $row->published_count,
                    'archived_count'    => (int) $row->archived_count,
                    'draft_count'       => (int) $row->draft_count,
                    'total_count'       => (int) $row->total_count,
                    'min_date'          => $min_date,
                    'max_date'          => $max_date,
                    'jf_uk_count'       => (int) $row->jf_uk_count,
                    'jf_en_count'       => (int) $row->jf_en_count,
                    'migrated_wp_count' => (int) $row->migrated_wp_count,
                ];
            }
        }

        wp_send_json_success([
            'tables'        => $tables,
            'categories'    => $categories_out,
            'wp_categories' => self::get_wp_categories_list(),
        ]);
    }

    /**
     * AJAX handler: preview all articles in a specific Joomla category for the modal selector.
     */
    public function ajax_preview_category(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $cat_id = isset($_POST['catid']) ? max(0, (int) wp_unslash($_POST['catid'])) : 0;

        global $wpdb;

        $exists_content = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_content'));
        if ($exists_content !== 'jos_content') {
            wp_send_json_error(['message' => __('Таблицю jos_content не знайдено в БД.', 'scat-joomla-migrator')], 400);
        }

        $sql = $wpdb->prepare(
            'SELECT id, title, alias, created, state, hits
             FROM `jos_content`
             WHERE catid = %d
             ORDER BY created DESC, id DESC',
            $cat_id
        );

        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows) || $rows === []) {
            wp_send_json_success([
                'cat_id'   => $cat_id,
                'articles' => [],
            ]);
        }

        $article_ids = [];
        foreach ($rows as $r) {
            if (is_object($r) && !empty($r->id)) {
                $article_ids[] = (int) $r->id;
            }
        }

        // Batch fetch Joom!Fish UK titles and UK/EN availability
        $uk_titles = [];
        $has_uk_map = [];
        $has_en_map = [];

        $has_jf = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_jf_content')) === 'jos_jf_content';
        if ($has_jf && $article_ids !== []) {
            $langs = self::get_joomla_languages();
            $uk_lang_id = (int) ($langs['by_code']['uk'] ?? 0);
            $en_lang_id = (int) ($langs['by_code']['en'] ?? 0);

            $id_placeholders = implode(', ', array_fill(0, count($article_ids), '%d'));
            $jf_sql = $wpdb->prepare(
                "SELECT reference_id, language_id, reference_field, value
                 FROM `jos_jf_content`
                 WHERE reference_table = 'content'
                   AND reference_id IN ({$id_placeholders})
                   AND TRIM(value) != ''",
                ...$article_ids
            );

            $jf_rows = is_string($jf_sql) ? $wpdb->get_results($jf_sql) : [];
            if (is_array($jf_rows)) {
                foreach ($jf_rows as $jf) {
                    if (!is_object($jf)) {
                        continue;
                    }
                    $ref_id = (int) $jf->reference_id;
                    $lid = (int) $jf->language_id;

                    if ($uk_lang_id > 0 && $lid === $uk_lang_id) {
                        $has_uk_map[$ref_id] = true;
                        if ($jf->reference_field === 'title') {
                            $uk_titles[$ref_id] = trim(wp_strip_all_tags((string) $jf->value));
                        }
                    } elseif ($en_lang_id > 0 && $lid === $en_lang_id) {
                        $has_en_map[$ref_id] = true;
                    }
                }
            }
        }

        // Batch fetch WP migration status
        $wp_map = [];
        if ($article_ids !== []) {
            $str_ids = array_map('strval', $article_ids);
            $pm_placeholders = implode(', ', array_fill(0, count($str_ids), '%s'));
            $pm_args = array_merge([ContentMigrator::META_JOOMLA_ID], $str_ids);

            $pm_sql = $wpdb->prepare(
                "SELECT pm.meta_value AS joomla_id, p.ID AS post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status != 'trash'
                 WHERE pm.meta_key = %s
                   AND pm.meta_value IN ({$pm_placeholders})",
                ...$pm_args
            );

            $pm_rows = is_string($pm_sql) ? $wpdb->get_results($pm_sql) : [];
            if (is_array($pm_rows)) {
                foreach ($pm_rows as $pm) {
                    if (!is_object($pm) || empty($pm->joomla_id) || empty($pm->post_id)) {
                        continue;
                    }
                    $jid = (int) $pm->joomla_id;
                    $pid = (int) $pm->post_id;
                    if (!isset($wp_map[$jid])) {
                        $wp_map[$jid] = [
                            'post_id'  => $pid,
                            'url'      => (string) get_permalink($pid),
                            'edit_url' => admin_url('post.php?post=' . $pid . '&action=edit'),
                        ];
                    }
                }
            }
        }

        $articles_out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || empty($row->id)) {
                continue;
            }

            $aid = (int) $row->id;
            $created_str = (string) ($row->created ?? '');
            $date_short = ($created_str !== '' && strpos($created_str, '0000-00-00') !== 0)
                ? substr($created_str, 0, 10)
                : '—';

            $wp_info = $wp_map[$aid] ?? null;

            $articles_out[] = [
                'id'          => $aid,
                'created'     => $date_short,
                'state'       => (int) ($row->state ?? 0),
                'hits'        => (int) ($row->hits ?? 0),
                'title'       => trim(wp_strip_all_tags((string) $row->title)),
                'uk_title'    => $uk_titles[$aid] ?? '',
                'has_uk'      => !empty($has_uk_map[$aid]),
                'has_en'      => !empty($has_en_map[$aid]),
                'migrated'    => $wp_info !== null,
                'wp_post_id'  => $wp_info['post_id'] ?? 0,
                'wp_url'      => $wp_info['url'] ?? '',
                'wp_edit_url' => $wp_info['edit_url'] ?? '',
            ];
        }

        wp_send_json_success([
            'cat_id'   => $cat_id,
            'articles' => $articles_out,
        ]);
    }

    /**
     * Get list of existing WordPress categories (across all languages).
     *
     * @return array<int, array{term_id: int, name: string, slug: string, lang: string}>
     */
    public static function get_wp_categories_list(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'lang'       => '',
        ]);

        if (!is_array($terms)) {
            return [];
        }

        $list = [];
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }

            $lang = '';
            if (function_exists('pll_get_term_language')) {
                $pll_lang = pll_get_term_language((int) $term->term_id);
                if (is_string($pll_lang)) {
                    $lang = $pll_lang;
                }
            }

            $list[] = [
                'term_id' => (int) $term->term_id,
                'name'    => $term->name,
                'slug'    => $term->slug,
                'lang'    => $lang,
            ];
        }

        return $list;
    }
}
