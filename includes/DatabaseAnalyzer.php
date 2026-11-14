<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class DatabaseAnalyzer
{
    public const AJAX_LOAD_SQL_ACTION = 'scat_migrator_load_sql';
    public const AJAX_DROP_TABLES_ACTION = 'scat_migrator_drop_tables';
    public const AJAX_ANALYZE_DB_ACTION = 'scat_migrator_analyze_db';

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

        // Drop the 6 allowed tables before import to avoid duplicates on re-run
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
     * AJAX handler: analyze Joomla sections, categories, articles, Joom!Fish translations, and migrated WP posts.
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

        if ($has_jf) {
            $jf_subquery = "(SELECT COUNT(DISTINCT jf.reference_id)
                             FROM `jos_jf_content` jf
                             INNER JOIN `jos_content` jc2 ON jc2.id = jf.reference_id
                             WHERE jf.reference_table = 'content'
                               AND jc2.catid = c.catid
                               AND jc2.sectionid = c.sectionid)";
        } else {
            $jf_subquery = '0';
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
                {$jf_subquery} AS jf_translations_count,
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

                $min_date = !empty($row->min_date) ? substr((string) $row->min_date, 0, 10) : '—';
                $max_date = !empty($row->max_date) ? substr((string) $row->max_date, 0, 10) : '—';

                $categories_out[] = [
                    'key'                   => $sec_id . ':' . $cat_id,
                    'section_id'            => $sec_id,
                    'section_title'         => $sec_title,
                    'cat_id'                => $cat_id,
                    'cat_title'             => $cat_title,
                    'published_count'       => (int) $row->published_count,
                    'archived_count'        => (int) $row->archived_count,
                    'draft_count'           => (int) $row->draft_count,
                    'total_count'           => (int) $row->total_count,
                    'min_date'              => $min_date,
                    'max_date'              => $max_date,
                    'jf_translations_count' => (int) $row->jf_translations_count,
                    'migrated_wp_count'     => (int) $row->migrated_wp_count,
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
