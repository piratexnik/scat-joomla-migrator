<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

/**
 * Generates 4 consolidated multilingual WordPress product hub pages (UK + RU + EN)
 * from Joomla 1.5 jos_sections / jos_categories / jos_content / jos_jf_content tables:
 * 1) lotsman-metal-tray   -> metal-cable-trays
 * 2) lotsman-ladder-tray  -> ladder-cable-trays
 * 3) sprut-metal-hose     -> metal-hose-sprut
 * 4) mounting-systems     -> mounting-systems
 */
final class ProductHubGenerator
{
    public const META_HUB_KEY = '_scat_product_hub_key';
    public const META_HUB_LANG = '_scat_product_hub_lang';
    public const META_HUB_RENDERED_HTML = '_scat_hub_rendered_html';
    public const OPTION_DISCONTINUED_CONFIG = 'scat_migrator_hub_discontinued_config';

    public const AJAX_HUB_STATUS_ACTION = 'scat_migrator_hub_status';
    public const AJAX_GENERATE_HUB_ACTION = 'scat_migrator_generate_product_hub';

    private const LANGS = ['uk', 'ru', 'en'];

    private const DIVI_META_KEYS = [
        '_et_pb_use_builder',
        '_et_pb_old_content',
        '_et_pb_built_for_post_type',
        '_et_pb_ab_subjects',
        '_et_builder_version',
        '_divi_dynamic_assets',
        '_et_dynamic_cached_shortcodes',
        '_et_dynamic_cached_attributes',
    ];

    private ?ContentMigrator $content_migrator = null;

    public function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_HUB_STATUS_ACTION, [$this, 'ajax_hub_status']);
        add_action('wp_ajax_' . self::AJAX_GENERATE_HUB_ACTION, [$this, 'ajax_generate_hub']);
        add_action('wp_footer', [$this, 'render_frontend_assets'], 25);
    }

    private function migrator(): ContentMigrator
    {
        if ($this->content_migrator === null) {
            $this->content_migrator = new ContentMigrator();
        }
        return $this->content_migrator;
    }

    /**
     * Static hub definitions (dynamic Joomla sectionid / intro article IDs are resolved at runtime).
     *
     * @return array<string, array{
     *   key: string,
     *   slug: string,
     *   titles: array<string, string>,
     *   default_intro: array<string, string>,
     *   fallback_section_id: int
     * }>
     */
    public static function get_hub_definitions(): array
    {
        return [
            'lotsman-metal-tray' => [
                'key'                 => 'lotsman-metal-tray',
                'slug'                => 'metal-cable-trays',
                'fallback_section_id' => 2,
                'titles'              => [
                    'uk' => 'Металеві кабельні лотки (Система LOTSMAN)',
                    'ru' => 'Металлические кабельные лотки (Система LOTSMAN)',
                    'en' => 'Metal Cable Trays (LOTSMAN System)',
                ],
                'default_intro'       => [
                    'uk' => '<p>Система металевих кабельних лотків <strong>LOTSMAN</strong> призначена для відкритого прокладання силових та сигнальних кабельних трас на промислових, комерційних та інфраструктурних об’єктах.</p>',
                    'ru' => '<p>Система металлических кабельных лотков <strong>LOTSMAN</strong> предназначена для открытой прокладки силовых и сигнальных кабельных трасс на промышленных, коммерческих и инфраструктурных объектах.</p>',
                    'en' => '<p>The <strong>LOTSMAN</strong> metal cable tray system is designed for open routing of power and signal cable lines at industrial, commercial, and infrastructure facilities.</p>',
                ],
            ],
            'lotsman-ladder-tray' => [
                'key'                 => 'lotsman-ladder-tray',
                'slug'                => 'ladder-cable-trays',
                'fallback_section_id' => 0,
                'titles'              => [
                    'uk' => 'Кабельні лотки драбинного типу (Система LOTSMAN)',
                    'ru' => 'Кабельные лотки лестничного типа (Система LOTSMAN)',
                    'en' => 'Ladder Type Cable Trays (LOTSMAN System)',
                ],
                'default_intro'       => [
                    'uk' => '<p>Кабельні лотки драбинного типу <strong>LOTSMAN</strong> забезпечують підвищену несучу здатність та природну вентиляцію важких силових кабелів на великих прольотах.</p>',
                    'ru' => '<p>Кабельные лотки лестничного типа <strong>LOTSMAN</strong> обеспечивают повышенную несущую способность и естественную вентиляцию тяжелых силовых кабелей на больших пролетах.</p>',
                    'en' => '<p><strong>LOTSMAN</strong> ladder type cable trays provide high load-bearing capacity and natural ventilation for heavy power cables over long spans.</p>',
                ],
            ],
            'sprut-metal-hose' => [
                'key'                 => 'sprut-metal-hose',
                'slug'                => 'metal-hose-sprut',
                'fallback_section_id' => 0,
                'titles'              => [
                    'uk' => 'Металорукав (Система SPRUT)',
                    'ru' => 'Металлорукав (Система SPRUT)',
                    'en' => 'Flexible Metal Conduit (SPRUT System)',
                ],
                'default_intro'       => [
                    'uk' => '<p>Система гнучкого металорукава <strong>SPRUT</strong> надійно захищає електричні дроти та кабелі від механічних пошкоджень, вологи, пилу та електромагнітних перешкод.</p>',
                    'ru' => '<p>Система гибкого металлорукава <strong>SPRUT</strong> надежно защищает электрические провода и кабели от механических повреждений, влаги, пыли и электромагнитных помех.</p>',
                    'en' => '<p>The <strong>SPRUT</strong> flexible metal conduit system reliably protects electrical wires and cables against mechanical damage, moisture, dust, and electromagnetic interference.</p>',
                ],
            ],
            'mounting-systems' => [
                'key'                 => 'mounting-systems',
                'slug'                => 'mounting-systems',
                'fallback_section_id' => 0,
                'titles'              => [
                    'uk' => 'Монтажні системи і кріпильні елементи',
                    'ru' => 'Монтажные системы и крепежные элементы',
                    'en' => 'Mounting Systems and Fasteners',
                ],
                'default_intro'       => [
                    'uk' => '<p>Комплексні монтажні системи та кріпильні елементи SCaT (кронштейни, консолі, монтажні профілі, підвіси, кутники та фіксатори) дозволяють реалізувати кріплення кабельних трас будь-якої складності до стін, стелі та металоконструкцій.</p>',
                    'ru' => '<p>Комплексные монтажные системы и крепежные элементы SCaT (кронштейны, консоли, монтажные профили, подвесы, уголки и фиксаторы) позволяют реализовать крепление кабельных трасс любой сложности к стенам, потолку и металлоконструкциям.</p>',
                    'en' => '<p>SCaT mounting systems and fasteners (brackets, cantilevers, strut profiles, ceiling hangers, angles, and clamps) enable reliable installation of cable trays of any complexity to walls, ceilings, and steel structures.</p>',
                ],
            ],
        ];
    }

    /**
     * AJAX: Return full status & anchor lists for all 4 hubs (for AdminPage).
     */
    public function ajax_hub_status(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        try {
            $this->cleanup_legacy_hub_posts_and_terms();
            wp_send_json_success([
                'hubs' => $this->get_all_hubs_status(),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => sprintf(
                    'PHP Error: %s in %s:%d',
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine()
                ),
            ], 500);
        }
    }

    /**
     * AJAX: Generate or update 1 product hub page (UK only, post_type = page).
     */
    public function ajax_generate_hub(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('memory_limit', '512M');

        try {
            $hub_key = isset($_POST['hub_key']) ? sanitize_key((string) wp_unslash($_POST['hub_key'])) : '';
            $post_status = isset($_POST['post_status']) ? sanitize_key((string) wp_unslash($_POST['post_status'])) : 'publish';
            if (!in_array($post_status, ['publish', 'draft'], true)) {
                $post_status = 'publish';
            }

            $lang = 'uk';

            $defs = self::get_hub_definitions();
            if (!isset($defs[$hub_key])) {
                wp_send_json_error(['message' => __('Невідомий ключ зведеної сторінки.', 'scat-joomla-migrator')], 400);
            }

            if (!$this->joomla_tables_ready()) {
                wp_send_json_error([
                    'message' => __('Таблиці jos_sections / jos_categories / jos_content не завантажені в БД. Завантажте SQL у Блоці 2.1.', 'scat-joomla-migrator'),
                ], 400);
            }

            if (isset($_POST['discontinued_hub']) || isset($_POST['discontinued_tabs'])) {
                $raw_hub_flag = isset($_POST['discontinued_hub'])
                    ? sanitize_text_field((string) wp_unslash($_POST['discontinued_hub']))
                    : '0';
                $hub_discontinued = in_array(strtolower($raw_hub_flag), ['1', 'true', 'yes', 'on'], true);

                $tabs_input = [];
                if (isset($_POST['discontinued_tabs'])) {
                    $raw_tabs = wp_unslash($_POST['discontinued_tabs']);
                    if (is_string($raw_tabs)) {
                        $decoded = json_decode($raw_tabs, true);
                        if (is_array($decoded)) {
                            $tabs_input = $decoded;
                        }
                    } elseif (is_array($raw_tabs)) {
                        $tabs_input = $raw_tabs;
                    }
                }

                $this->update_hub_discontinued_config($hub_key, $hub_discontinued, $tabs_input);
            }

            $result = $this->generate_hub($hub_key, $post_status, $lang);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 500);
            }

            wp_send_json_success([
                'hub_key' => $hub_key,
                'lang'    => $lang,
                'result'  => $result,
                'hub'     => $this->get_single_hub_status($hub_key),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => sprintf(
                    'PHP Error: %s in %s:%d',
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine()
                ),
            ], 500);
        }
    }

    /**
     * Get discontinued badge configuration for all 4 hubs (with defaults:
     * enabled for lotsman-metal-tray, lotsman-ladder-tray, mounting-systems; disabled for sprut-metal-hose).
     *
     * @return array<string, array{hub: bool, tabs: array<string, bool>}>
     */
    public function get_discontinued_config(): array
    {
        $saved = get_option(self::OPTION_DISCONTINUED_CONFIG, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $defaults = [
            'lotsman-metal-tray'  => true,
            'lotsman-ladder-tray' => true,
            'sprut-metal-hose'    => false,
            'mounting-systems'    => true,
        ];

        $config = [];
        foreach (array_keys(self::get_hub_definitions()) as $hub_key) {
            $default_hub = $defaults[$hub_key] ?? false;
            $hub_saved = isset($saved[$hub_key]) && is_array($saved[$hub_key]) ? $saved[$hub_key] : [];

            $hub_flag = array_key_exists('hub', $hub_saved)
                ? (bool) $hub_saved['hub']
                : $default_hub;

            $tabs_map = [];
            if (isset($hub_saved['tabs']) && is_array($hub_saved['tabs'])) {
                foreach ($hub_saved['tabs'] as $tab_slug => $flag) {
                    $clean_slug = sanitize_key((string) $tab_slug);
                    if ($clean_slug !== '' && $flag) {
                        $tabs_map[$clean_slug] = true;
                    }
                }
            }

            $config[$hub_key] = [
                'hub'  => $hub_flag,
                'tabs' => $tabs_map,
            ];
        }

        return $config;
    }

    /**
     * Save discontinued badge configuration for a specific hub in wp_options.
     *
     * @param array<int|string, mixed> $discontinued_tabs
     * @return array<string, array{hub: bool, tabs: array<string, bool>}>
     */
    public function update_hub_discontinued_config(string $hub_key, bool $hub_discontinued, array $discontinued_tabs): array
    {
        $config = $this->get_discontinued_config();
        $tabs_map = [];

        foreach ($discontinued_tabs as $k => $v) {
            if (is_int($k)) {
                $slug = sanitize_key((string) $v);
                if ($slug !== '') {
                    $tabs_map[$slug] = true;
                }
            } else {
                $slug = sanitize_key((string) $k);
                if ($slug !== '' && $v) {
                    $tabs_map[$slug] = true;
                }
            }
        }

        $config[$hub_key] = [
            'hub'  => $hub_discontinued,
            'tabs' => $tabs_map,
        ];

        update_option(self::OPTION_DISCONTINUED_CONFIG, $config, false);

        return $config;
    }

    /**
     * Check whether required Joomla tables exist in WordPress DB.
     */
    public function joomla_tables_ready(): bool
    {
        global $wpdb;
        foreach (['jos_sections', 'jos_categories', 'jos_content'] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                return false;
            }
        }
        return true;
    }

    /**
     * Collect status of all 4 product hubs for the admin UI.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_all_hubs_status(): array
    {
        $out = [];
        foreach (array_keys(self::get_hub_definitions()) as $hub_key) {
            $out[$hub_key] = $this->get_single_hub_status($hub_key);
        }
        return $out;
    }

    /**
     * Collect status, resolved Joomla categories, anchors, and existing WP pages (UK/RU/EN) for a single hub.
     *
     * @return array<string, mixed>
     */
    public function get_single_hub_status(string $hub_key): array
    {
        $defs = self::get_hub_definitions();
        $def = $defs[$hub_key];
        $existing_pages = $this->find_existing_hub_pages($hub_key);
        $disc_config = $this->get_discontinued_config();
        $hub_disc = $disc_config[$hub_key] ?? ['hub' => false, 'tabs' => []];

        $tables_ready = $this->joomla_tables_ready();
        $section = $tables_ready ? $this->resolve_joomla_section($hub_key) : null;
        $categories = ($tables_ready && $section !== null)
            ? $this->get_section_categories((int) $section->id, $hub_key, false)
            : [];
        $intro_article = $tables_ready ? $this->resolve_intro_article($hub_key) : null;

        $base_urls = [];
        foreach (self::LANGS as $lang) {
            if (isset($existing_pages[$lang])) {
                $pid = $existing_pages[$lang]['post_id'];
                $base_urls[$lang] = (string) get_permalink($pid);
            } else {
                $base_urls[$lang] = $this->build_predicted_hub_url($def['slug'], $lang);
            }
        }

        $anchors = [];
        foreach ($categories as $cat) {
            $tab_slug = $cat['tab_slug'];
            $anchors[] = [
                'cat_id'        => $cat['id'],
                'tab_slug'      => $tab_slug,
                'title_uk'      => $cat['titles']['uk'],
                'title_ru'      => $cat['titles']['ru'],
                'title_en'      => $cat['titles']['en'],
                'article_count' => $cat['article_count'],
                'discontinued'  => !empty($hub_disc['tabs'][$tab_slug]),
                'urls'          => [
                    'uk' => $base_urls['uk'] . '#' . $tab_slug,
                    'ru' => $base_urls['ru'] . '#' . $tab_slug,
                    'en' => $base_urls['en'] . '#' . $tab_slug,
                ],
            ];
        }

        return [
            'key'               => $hub_key,
            'slug'              => $def['slug'],
            'titles'            => $def['titles'],
            'tables_ready'      => $tables_ready,
            'discontinued_hub'  => (bool) $hub_disc['hub'],
            'section_id'        => $section !== null ? (int) $section->id : 0,
            'section_title'     => $section !== null ? (string) ($section->title ?: $section->name) : '',
            'intro_id'          => $intro_article !== null ? (int) $intro_article->id : 0,
            'intro_title'       => $intro_article !== null ? (string) $intro_article->title : '',
            'categories'        => $anchors,
            'pages'             => $existing_pages,
            'predicted_urls'    => $base_urls,
        ];
    }

    /**
     * Build predicted permalink for a hub before the page is created.
     */
    private function build_predicted_hub_url(string $slug, string $lang): string
    {
        $home = untrailingslashit(home_url('/'));
        if ($lang === 'uk') {
            return $home . '/' . $slug . '/';
        }
        return $home . '/' . $lang . '/' . $slug . '/';
    }

    /**
     * Find existing WP pages for a hub key grouped by language ('uk', 'ru', 'en').
     *
     * @return array<string, array{post_id: int, title: string, status: string, url: string, edit_url: string}>
     */
    public function find_existing_hub_pages(string $hub_key): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value = %s
               AND p.post_type IN ('post', 'page')
               AND p.post_status != 'trash'
             ORDER BY p.ID ASC",
            self::META_HUB_KEY,
            $hub_key
        );

        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $pages = [];
        foreach ($rows as $r) {
            if (!is_object($r)) {
                continue;
            }
            $pid = (int) $r->ID;
            $lang = '';
            if (function_exists('pll_get_post_language')) {
                $pll_lang = pll_get_post_language($pid);
                if (is_string($pll_lang) && $pll_lang !== '') {
                    $lang = $pll_lang;
                }
            }
            if ($lang === '') {
                $meta_lang = get_post_meta($pid, self::META_HUB_LANG, true);
                if (is_string($meta_lang) && $meta_lang !== '') {
                    $lang = $meta_lang;
                }
            }
            if ($lang === '') {
                $lang = 'uk';
            }

            if (!isset($pages[$lang])) {
                $pages[$lang] = [
                    'post_id'  => $pid,
                    'title'    => (string) $r->post_title,
                    'status'   => (string) $r->post_status,
                    'url'      => (string) get_permalink($pid),
                    'edit_url' => (string) admin_url('post.php?post=' . $pid . '&action=edit'),
                ];
            }

            if (function_exists('pll_get_post_translations')) {
                $tr_map = pll_get_post_translations($pid);
                if (is_array($tr_map)) {
                    foreach ($tr_map as $tr_lang => $tr_id) {
                        $tr_id_int = (int) $tr_id;
                        if (is_string($tr_lang) && $tr_lang !== '' && $tr_id_int > 0 && !isset($pages[$tr_lang])) {
                            $tr_post = get_post($tr_id_int);
                            if ($tr_post instanceof \WP_Post && $tr_post->post_status !== 'trash') {
                                $pages[$tr_lang] = [
                                    'post_id'  => $tr_id_int,
                                    'title'    => (string) $tr_post->post_title,
                                    'status'   => (string) $tr_post->post_status,
                                    'url'      => (string) get_permalink($tr_id_int),
                                    'edit_url' => (string) admin_url('post.php?post=' . $tr_id_int . '&action=edit'),
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $pages;
    }

    /**
     * Dynamically resolve the matching Joomla section row from jos_sections for a hub key.
     */
    private function resolve_joomla_section(string $hub_key): ?object
    {
        global $wpdb;

        $sections = $wpdb->get_results("SELECT id, title, name, alias, published FROM `jos_sections` ORDER BY id ASC");
        if (!is_array($sections) || $sections === []) {
            return null;
        }

        $jf_sections = $this->get_joomfish_map_for_table('sections');

        foreach ($sections as $sec) {
            if (!is_object($sec)) {
                continue;
            }
            $sid = (int) $sec->id;
            $haystack = mb_strtolower(
                trim(
                    (string) ($sec->title ?? '') . ' '
                    . (string) ($sec->name ?? '') . ' '
                    . (string) ($sec->alias ?? '') . ' '
                    . ($jf_sections[$sid]['uk']['title'] ?? '') . ' '
                    . ($jf_sections[$sid]['uk']['name'] ?? '') . ' '
                    . ($jf_sections[$sid]['en']['title'] ?? '')
                ),
                'UTF-8'
            );

            if ($hub_key === 'lotsman-metal-tray') {
                if (
                    (strpos($haystack, 'lotsman') !== false || strpos($haystack, 'лоток') !== false)
                    && (strpos($haystack, 'металлический') !== false || strpos($haystack, 'металевий') !== false || strpos($haystack, 'metal') !== false)
                    && strpos($haystack, 'лестнич') === false
                    && strpos($haystack, 'драбин') === false
                    && strpos($haystack, 'ladder') === false
                ) {
                    return $sec;
                }
            } elseif ($hub_key === 'lotsman-ladder-tray') {
                if (
                    strpos($haystack, 'лестнич') !== false
                    || strpos($haystack, 'драбин') !== false
                    || strpos($haystack, 'ladder') !== false
                ) {
                    return $sec;
                }
            } elseif ($hub_key === 'sprut-metal-hose') {
                if (
                    strpos($haystack, 'sprut') !== false
                    || strpos($haystack, 'металлорукав') !== false
                    || strpos($haystack, 'металорукав') !== false
                    || strpos($haystack, 'conduit') !== false
                ) {
                    return $sec;
                }
            } elseif ($hub_key === 'mounting-systems') {
                if (
                    strpos($haystack, 'монтажн') !== false
                    || strpos($haystack, 'крепеж') !== false
                    || strpos($haystack, 'кріпильн') !== false
                    || strpos($haystack, 'mounting') !== false
                ) {
                    return $sec;
                }
            }
        }

        $defs = self::get_hub_definitions();
        $fallback_id = (int) ($defs[$hub_key]['fallback_section_id'] ?? 0);
        if ($fallback_id > 0) {
            foreach ($sections as $sec) {
                if (is_object($sec) && (int) $sec->id === $fallback_id) {
                    return $sec;
                }
            }
        }

        return null;
    }

    /**
     * Dynamically resolve the intro overview article in Joomla "Продукція" category (catid = 10 or matched by title).
     */
    private function resolve_intro_article(string $hub_key): ?object
    {
        global $wpdb;

        $cat_id = $this->resolve_production_overview_cat_id();
        if ($cat_id <= 0) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM `jos_content` WHERE catid = %d AND state >= 0 ORDER BY ordering ASC, id ASC",
            $cat_id
        );
        $articles = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($articles) || $articles === []) {
            return null;
        }

        $art_ids = [];
        foreach ($articles as $art) {
            if (is_object($art) && isset($art->id)) {
                $art_ids[] = (int) $art->id;
            }
        }
        $jf_all = $this->preload_joomfish_for_articles($art_ids);

        foreach ($articles as $art) {
            if (!is_object($art)) {
                continue;
            }
            $aid = (int) $art->id;
            $jf = $jf_all[$aid] ?? [];
            $haystack = mb_strtolower(
                trim(
                    (string) ($art->title ?? '') . ' '
                    . (string) ($art->alias ?? '') . ' '
                    . ($jf['uk']['title'] ?? '') . ' '
                    . ($jf['en']['title'] ?? '')
                ),
                'UTF-8'
            );

            if ($hub_key === 'lotsman-metal-tray') {
                if (
                    (strpos($haystack, 'лоток металлический') !== false
                        || strpos($haystack, 'лоток металевий') !== false
                        || strpos($haystack, 'лотки металлические') !== false
                        || strpos($haystack, 'лотки металеві') !== false
                        || strpos($haystack, 'lotok-metallicheskij') !== false)
                    && strpos($haystack, 'лестнич') === false
                    && strpos($haystack, 'драбин') === false
                ) {
                    return $art;
                }
            } elseif ($hub_key === 'lotsman-ladder-tray') {
                if (
                    strpos($haystack, 'лестнич') !== false
                    || strpos($haystack, 'драбин') !== false
                    || strpos($haystack, 'ladder') !== false
                ) {
                    return $art;
                }
            } elseif ($hub_key === 'sprut-metal-hose') {
                if (
                    strpos($haystack, 'металлорукав') !== false
                    || strpos($haystack, 'металорукав') !== false
                    || strpos($haystack, 'metalorukav') !== false
                    || strpos($haystack, 'sprut') !== false
                ) {
                    return $art;
                }
            } elseif ($hub_key === 'mounting-systems') {
                if (
                    strpos($haystack, 'монтажные системы') !== false
                    || strpos($haystack, 'монтажні системи') !== false
                    || strpos($haystack, 'крепеж') !== false
                    || strpos($haystack, 'кріпильн') !== false
                ) {
                    return $art;
                }
            }
        }

        return null;
    }

    /**
     * Find category ID for "Продукція" (fallback 10).
     */
    private function resolve_production_overview_cat_id(): int
    {
        global $wpdb;

        $cats = $wpdb->get_results("SELECT id, title, name, alias FROM `jos_categories` ORDER BY id ASC");
        if (is_array($cats)) {
            foreach ($cats as $cat) {
                if (!is_object($cat)) {
                    continue;
                }
                if ((int) $cat->id === 10) {
                    return 10;
                }
            }
            foreach ($cats as $cat) {
                if (!is_object($cat)) {
                    continue;
                }
                $hay = mb_strtolower((string) ($cat->title . ' ' . $cat->name . ' ' . $cat->alias), 'UTF-8');
                if (strpos($hay, 'продукц') !== false || strpos($hay, 'production') !== false) {
                    return (int) $cat->id;
                }
            }
        }

        return 10;
    }

    /**
     * Load all published categories for a Joomla section along with UK/RU/EN titles, stable tab slugs, and article counts.
     *
     * @return array<int, array{
     *   id: int,
     *   tab_slug: string,
     *   titles: array<string, string>,
     *   articles: array<int, object>,
     *   article_count: int
     * }>
     */
    private function get_section_categories(int $section_id, string $hub_key, bool $include_articles = true): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT id, title, name, alias, ordering
             FROM `jos_categories`
             WHERE section = %s
               AND published = 1
             ORDER BY ordering ASC, id ASC",
            (string) $section_id
        );
        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $cat_ids = [];
        foreach ($rows as $row) {
            if (is_object($row) && (int) ($row->id ?? 0) > 0) {
                $cat_ids[] = (int) $row->id;
            }
        }
        if ($cat_ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($cat_ids), '%d'));
        $articles_by_cat = [];
        $counts_by_cat = [];

        if ($include_articles) {
            $articles_sql = $wpdb->prepare(
                "SELECT *
                 FROM `jos_content`
                 WHERE catid IN ({$placeholders})
                   AND state = 1
                 ORDER BY ordering ASC, id ASC",
                ...$cat_ids
            );
            $all_articles = is_string($articles_sql) ? $wpdb->get_results($articles_sql) : [];
            if (is_array($all_articles)) {
                foreach ($all_articles as $art) {
                    if (!is_object($art)) {
                        continue;
                    }
                    $art_cid = (int) ($art->catid ?? 0);
                    $articles_by_cat[$art_cid][] = $art;
                }
            }
            foreach ($articles_by_cat as $art_cid => $list) {
                $counts_by_cat[$art_cid] = count($list);
            }
        } else {
            $counts_sql = $wpdb->prepare(
                "SELECT catid, COUNT(*) AS cnt
                 FROM `jos_content`
                 WHERE catid IN ({$placeholders})
                   AND state = 1
                 GROUP BY catid",
                ...$cat_ids
            );
            $count_rows = is_string($counts_sql) ? $wpdb->get_results($counts_sql) : [];
            if (is_array($count_rows)) {
                foreach ($count_rows as $cr) {
                    if (is_object($cr)) {
                        $counts_by_cat[(int) ($cr->catid ?? 0)] = (int) ($cr->cnt ?? 0);
                    }
                }
            }
        }

        $jf_cats = $this->get_joomfish_map_for_table('categories');
        $used_slugs = [];
        $result = [];

        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $cid = (int) $row->id;
            $article_count = $counts_by_cat[$cid] ?? 0;
            if ($article_count <= 0) {
                continue;
            }

            $base_title = trim((string) ($row->title ?: $row->name));

            $title_ru = trim((string) ($jf_cats[$cid]['ru']['title'] ?? $jf_cats[$cid]['ru']['name'] ?? $base_title));
            $title_uk = trim((string) ($jf_cats[$cid]['uk']['title'] ?? $jf_cats[$cid]['uk']['name'] ?? $base_title));
            $title_en = trim((string) ($jf_cats[$cid]['en']['title'] ?? $jf_cats[$cid]['en']['name'] ?? $base_title));

            $tab_slug = $this->build_stable_tab_slug($base_title, (string) ($row->alias ?? ''), $hub_key, $used_slugs);
            $used_slugs[$tab_slug] = true;

            $result[] = [
                'id'            => $cid,
                'tab_slug'      => $tab_slug,
                'titles'        => [
                    'uk' => $title_uk !== '' ? $title_uk : $base_title,
                    'ru' => $title_ru !== '' ? $title_ru : $base_title,
                    'en' => $title_en !== '' ? $title_en : $base_title,
                ],
                'articles'      => $articles_by_cat[$cid] ?? [],
                'article_count' => $article_count,
            ];
        }

        if ($hub_key === 'lotsman-metal-tray' && count($result) > 5) {
            $filtered = [];
            foreach ($result as $cat_item) {
                if ($this->is_allowed_lotsman_metal_tray_category(
                    $cat_item['titles']['uk'],
                    $cat_item['titles']['ru'],
                    $cat_item['titles']['en'],
                    $cat_item['tab_slug']
                )) {
                    $filtered[] = $cat_item;
                }
            }
            if ($filtered !== []) {
                $result = $filtered;
            }
        }

        return $result;
    }

    /**
     * Keep only the 5 real main-menu series for lotsman-metal-tray:
     * Light H=50, Standard H=35, Standard H=50, Standard H=80, Standard H=100.
     * Excludes extra/legacy/archive categories (e.g. H=47, separate covers/accessories/archive).
     */
    private function is_allowed_lotsman_metal_tray_category(
        string $title_uk,
        string $title_ru,
        string $title_en,
        string $tab_slug
    ): bool {
        $hay = mb_strtolower(trim($title_uk . ' ' . $title_ru . ' ' . $title_en . ' ' . $tab_slug), 'UTF-8');

        // Explicitly exclude archive / legacy / standalone accessory categories
        $excluded_markers = [
            'архів',
            'архив',
            'archive',
            'старі',
            'старые',
            'кришк',
            'крышк',
            'аксесуар',
            'аксессуар',
            'accessor',
            'фасон',
            'кріплен',
            'креплени',
            'монтаж',
        ];
        foreach ($excluded_markers as $marker) {
            if (strpos($hay, $marker) !== false) {
                return false;
            }
        }

        // Exclude H=47 explicitly
        if (preg_match('/\bh\s*=?\s*47\b/iu', $hay) === 1) {
            return false;
        }

        // Allow Light H=50
        if (
            (strpos($hay, 'light') !== false || strpos($hay, 'лайт') !== false)
            && preg_match('/\b(?:h\s*=?\s*)?50\b/iu', $hay) === 1
        ) {
            return true;
        }

        // Allow Standard H=35, H=50, H=80, H=100 (or plain H=35 / H=50 / H=80 / H=100)
        if (preg_match('/\bh\s*=?\s*(?:35|50|80|100)\b/iu', $hay) === 1) {
            return true;
        }
        if (preg_match('/(?:standard|стандарт)[^\d]*(?:35|50|80|100)\b/iu', $hay) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Build a clean, human-readable, language-independent #anchor slug for a category tab
     * (e.g. "Light H=50" -> "light-h50", "H=47" -> "h47", "У ПВХ оболонці" -> "pvc-sheath").
     *
     * @param array<string, bool> $used_slugs
     */
    private function build_stable_tab_slug(string $title, string $alias, string $hub_key, array $used_slugs): string
    {
        $raw = trim($title) !== '' ? trim($title) : trim($alias);
        // Normalize H=50, H = 80 -> h50, h80
        $normalized = preg_replace('/\b([hH])\s*=\s*(\d+)\b/u', 'h$2', $raw);
        $normalized = is_string($normalized) ? $normalized : $raw;

        $slug = $this->transliterate_to_slug($normalized);
        if ($slug === '') {
            $slug = $this->transliterate_to_slug($alias);
        }
        if ($slug === '') {
            $slug = 'tab-' . (count($used_slugs) + 1);
        }

        $base_slug = $slug;
        $counter = 2;
        while (isset($used_slugs[$slug])) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Transliterate Cyrillic/Ukrainian/Russian string into clean ASCII kebab-case slug.
     */
    private function transliterate_to_slug(string $text): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd',
            'е' => 'e', 'є' => 'ye', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
            'щ' => 'shch', 'ь' => '', 'ы' => 'y', 'ъ' => '', 'э' => 'e', 'ю' => 'yu',
            'я' => 'ya',
        ];
        $lower = mb_strtolower($text, 'UTF-8');
        $trans = strtr($lower, $map);
        $slug = sanitize_title($trans);
        return is_string($slug) ? $slug : '';
    }

    /**
     * Fetch Joom!Fish translations for sections or categories grouped by [id][lang][field].
     *
     * @return array<int, array<string, array<string, string>>>
     */
    private function get_joomfish_map_for_table(string $reference_table): array
    {
        global $wpdb;

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_jf_content')) !== 'jos_jf_content') {
            return [];
        }

        $langs = DatabaseAnalyzer::get_joomla_languages();
        if ($langs['by_id'] === []) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT reference_id, language_id, reference_field, value
             FROM `jos_jf_content`
             WHERE reference_table = %s
               AND TRIM(value) != ''
             ORDER BY published DESC, id ASC",
            $reference_table
        );
        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            if (!is_object($r)) {
                continue;
            }
            $ref_id = (int) ($r->reference_id ?? 0);
            $lid = (int) ($r->language_id ?? 0);
            $lang = $langs['by_id'][$lid] ?? '';
            $field = (string) ($r->reference_field ?? '');
            if ($ref_id <= 0 || $lang === '' || $field === '') {
                continue;
            }
            if (!isset($map[$ref_id][$lang][$field])) {
                $map[$ref_id][$lang][$field] = (string) $r->value;
            }
        }

        return $map;
    }

    /**
     * Preload Joom!Fish translations for a batch of Joomla content article IDs in a single SQL query.
     *
     * @param array<int, int> $article_ids
     * @return array<int, array<string, array<string, string>>>
     */
    private function preload_joomfish_for_articles(array $article_ids): array
    {
        global $wpdb;

        $ids = [];
        foreach ($article_ids as $id) {
            $int_id = (int) $id;
            if ($int_id > 0) {
                $ids[$int_id] = $int_id;
            }
        }
        if ($ids === []) {
            return [];
        }

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'jos_jf_content')) !== 'jos_jf_content') {
            return [];
        }

        $langs = DatabaseAnalyzer::get_joomla_languages();
        if ($langs['by_id'] === []) {
            return [];
        }

        $id_list = array_values($ids);
        $placeholders = implode(', ', array_fill(0, count($id_list), '%d'));
        $sql = $wpdb->prepare(
            "SELECT reference_id, language_id, reference_field, value
             FROM `jos_jf_content`
             WHERE reference_table = %s
               AND reference_id IN ({$placeholders})
               AND TRIM(value) != ''
             ORDER BY published DESC, id ASC",
            'content',
            ...$id_list
        );
        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            if (!is_object($r)) {
                continue;
            }
            $ref_id = (int) ($r->reference_id ?? 0);
            $lid = (int) ($r->language_id ?? 0);
            $lang = $langs['by_id'][$lid] ?? '';
            $field = (string) ($r->reference_field ?? '');
            if ($ref_id <= 0 || $lang === '' || $field === '') {
                continue;
            }
            if (!isset($map[$ref_id][$lang][$field])) {
                $map[$ref_id][$lang][$field] = (string) $r->value;
            }
        }

        return $map;
    }

    /**
     * Generate or update one hub page across UK, RU, and EN (or a single target language) and link via Polylang.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function generate_hub(string $hub_key, string $post_status = 'publish', string $only_lang = 'all')
    {
        $defs = self::get_hub_definitions();
        if (!isset($defs[$hub_key])) {
            return new \WP_Error('invalid_hub', __('Невідомий ключ хабу.', 'scat-joomla-migrator'));
        }
        $def = $defs[$hub_key];

        $section = $this->resolve_joomla_section($hub_key);
        if ($section === null) {
            return new \WP_Error(
                'section_not_found',
                sprintf(__('Не знайдено розділ Joomla для «%s».', 'scat-joomla-migrator'), $def['titles']['uk'])
            );
        }

        $categories = $this->get_section_categories((int) $section->id, $hub_key, true);
        if ($categories === []) {
            return new \WP_Error(
                'categories_empty',
                sprintf(__('У розділі #%d не знайдено опублікованих категорій зі статтями.', 'scat-joomla-migrator'), (int) $section->id)
            );
        }

        $intro_article = $this->resolve_intro_article($hub_key);
        $existing_pages = $this->find_existing_hub_pages($hub_key);

        $all_article_ids = [];
        if ($intro_article !== null && isset($intro_article->id)) {
            $all_article_ids[] = (int) $intro_article->id;
        }
        $total_items_count = 0;
        foreach ($categories as $cat) {
            $total_items_count += $cat['article_count'];
            foreach ($cat['articles'] as $art) {
                if (is_object($art) && isset($art->id)) {
                    $all_article_ids[] = (int) $art->id;
                }
            }
        }
        $jf_map = $this->preload_joomfish_for_articles($all_article_ids);

        $this->cleanup_legacy_hub_posts_and_terms();
        $existing_pages = $this->find_existing_hub_pages($hub_key);

        $all_discontinued = $this->get_discontinued_config();
        $discontinued_cfg = $all_discontinued[$hub_key] ?? ['hub' => false, 'tabs' => []];

        $lang = 'uk';
        $built = $this->build_hub_page_html($def, $intro_article, $categories, $lang, $jf_map, $discontinued_cfg);
        $page_title = $def['titles'][$lang] ?? $def['titles']['uk'];
        $existing_id = isset($existing_pages[$lang]) ? (int) $existing_pages[$lang]['post_id'] : 0;

        $post_data = [
            'post_type'    => 'page',
            'post_title'   => $page_title,
            'post_name'    => $def['slug'],
            'post_content' => $built['html'],
            'post_excerpt' => $built['excerpt'],
            'post_status'  => $post_status,
        ];

        $had_targeted_link_rel = has_filter('content_save_pre', 'wp_targeted_link_rel');
        if ($had_targeted_link_rel !== false) {
            remove_filter('content_save_pre', 'wp_targeted_link_rel', is_int($had_targeted_link_rel) ? $had_targeted_link_rel : 10);
        }
        $kses_removed = false;
        if (function_exists('kses_remove_filters')) {
            kses_remove_filters();
            $kses_removed = true;
        }

        $this->migrator()->set_same_slug_forced_lang($lang);

        try {
            if ($existing_id > 0) {
                $post_data['ID'] = $existing_id;
                $post_id = wp_update_post(wp_slash($post_data), true);
            } else {
                $post_id = wp_insert_post(wp_slash($post_data), true);
            }

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $post_id = (int) $post_id;

            if (function_exists('pll_set_post_language')) {
                pll_set_post_language($post_id, $lang);
            }

            if (function_exists('wp_set_object_terms')) {
                wp_set_object_terms($post_id, [], 'category');
            }
        } finally {
            $this->migrator()->set_same_slug_forced_lang(null);
            if ($kses_removed && function_exists('kses_init_filters')) {
                kses_init_filters();
            }
            if ($had_targeted_link_rel !== false) {
                add_filter('content_save_pre', 'wp_targeted_link_rel', is_int($had_targeted_link_rel) ? $had_targeted_link_rel : 10);
            }
        }

        update_post_meta($post_id, self::META_HUB_KEY, $hub_key);
        update_post_meta($post_id, self::META_HUB_LANG, $lang);
        if (function_exists('delete_post_meta')) {
            delete_post_meta($post_id, self::META_HUB_RENDERED_HTML);
            foreach (self::DIVI_META_KEYS as $divi_meta_key) {
                delete_post_meta($post_id, $divi_meta_key);
            }
        }

        if ($built['first_webp_rel'] !== null) {
            $existing_thumb_id = (int) get_post_thumbnail_id($post_id);
            if ($existing_thumb_id <= 0 || !get_post($existing_thumb_id)) {
                $thumb_id = $this->migrator()->get_or_register_webp_thumbnail($built['first_webp_rel']);
                if ($thumb_id > 0) {
                    set_post_thumbnail($post_id, $thumb_id);
                }
            }
        }

        if ($built['metadesc'] !== '') {
            update_post_meta($post_id, 'rank_math_description', sanitize_text_field($built['metadesc']));
        }

        $saved_posts = [
            $lang => [
                'post_id'  => $post_id,
                'title'    => $page_title,
                'url'      => (string) get_permalink($post_id),
                'edit_url' => (string) admin_url('post.php?post=' . $post_id . '&action=edit'),
                'updated'  => $existing_id > 0,
            ],
        ];

        return [
            'hub_key'          => $hub_key,
            'lang'             => $lang,
            'tabs_count'       => count($categories),
            'products_count'   => $total_items_count,
            'pages'            => $saved_posts,
        ];
    }

    /**
     * Clean up v1.18 artifacts:
     * - Delete RU/EN hub entries (post or page) completely via wp_delete_post(..., true).
     * - Convert any UK hub entry with post_type='post' back to 'page', detach categories, and remove _scat_hub_rendered_html.
     * - Delete empty temporary categories with slugs products / products-ru / products-en.
     */
    public function cleanup_legacy_hub_posts_and_terms(): void
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_type
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type IN ('post', 'page')
               AND p.post_status != 'trash'
             ORDER BY p.ID ASC",
            self::META_HUB_KEY
        );

        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_object($r) || !isset($r->ID)) {
                    continue;
                }
                $pid = (int) $r->ID;
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
                    $meta_lang = get_post_meta($pid, self::META_HUB_LANG, true);
                    if (is_string($meta_lang) && $meta_lang !== '') {
                        $lang = $meta_lang;
                    }
                }
                if ($lang === '') {
                    $lang = 'uk';
                }

                if ($lang === 'ru' || $lang === 'en') {
                    if (function_exists('wp_delete_post')) {
                        wp_delete_post($pid, true);
                    }
                    continue;
                }

                if ((string) ($r->post_type ?? '') === 'post') {
                    wp_update_post([
                        'ID'        => $pid,
                        'post_type' => 'page',
                    ]);
                }
                if (function_exists('wp_set_object_terms')) {
                    wp_set_object_terms($pid, [], 'category');
                }
                if (function_exists('delete_post_meta')) {
                    delete_post_meta($pid, self::META_HUB_RENDERED_HTML);
                }
            }
        }

        if (function_exists('get_term_by') && function_exists('wp_delete_term')) {
            foreach (['products', 'products-ru', 'products-en'] as $cat_slug) {
                $term = get_term_by('slug', $cat_slug, 'category');
                if ($term instanceof \WP_Term && (int) $term->term_id > 0 && (int) ($term->count ?? 0) === 0) {
                    wp_delete_term((int) $term->term_id, 'category');
                }
            }
        }
    }

    /**
     * Build complete HTML for a hub page in a specific language ('uk', 'ru', 'en').
     *
     * @param array<string, mixed>                                  $def
     * @param array<int, array<string, mixed>>                      $categories
     * @param array<int, array<string, array<string, string>>>|null $jf_map
     * @param array{hub: bool, tabs: array<string, bool>}           $discontinued_cfg
     * @return array{html: string, excerpt: string, metadesc: string, first_webp_rel: string|null}
     */
    private function build_hub_page_html(
        array $def,
        ?object $intro_article,
        array $categories,
        string $lang,
        ?array $jf_map = null,
        array $discontinued_cfg = ['hub' => false, 'tabs' => []]
    ): array {
        $i18n = $this->get_ui_strings($lang);
        $first_webp_rel = null;
        $metadesc = '';
        $is_hub_discontinued = !empty($discontinued_cfg['hub']);
        $discontinued_tabs = isset($discontinued_cfg['tabs']) && is_array($discontinued_cfg['tabs'])
            ? $discontinued_cfg['tabs']
            : [];

        // 1) Intro section
        $intro_html = '';
        if ($intro_article !== null) {
            $intro_id = (int) $intro_article->id;
            $jf = $jf_map !== null
                ? ($jf_map[$intro_id] ?? [])
                : $this->migrator()->get_joomfish_fields_for_article($intro_id);
            $raw_intro = $this->pick_localized_field($jf, $lang, 'introtext', (string) ($intro_article->introtext ?? ''));
            $raw_full = $this->pick_localized_field($jf, $lang, 'fulltext', (string) ($intro_article->fulltext ?? ''));
            $metadesc = trim($this->pick_localized_field($jf, $lang, 'metadesc', (string) ($intro_article->metadesc ?? '')));

            $combined_intro = str_replace(
                '<!--more-->',
                '',
                $this->migrator()->combine_intro_and_fulltext($raw_intro, $raw_full)
            );
            $processed_intro = $this->migrator()->process_article_html(
                $combined_intro,
                false,
                $intro_id,
                $def['titles'][$lang] ?? '',
                false
            );

            $intro_body = $this->clean_product_media_and_tables($processed_intro['html'], $intro_id, false)['html'];
            $intro_html = $intro_body;
            if ($processed_intro['first_webp_rel'] !== null) {
                $first_webp_rel = $processed_intro['first_webp_rel'];
            }
        }

        if (trim(wp_strip_all_tags($intro_html)) === '') {
            $intro_html = $def['default_intro'][$lang] ?? $def['default_intro']['uk'];
        }

        // 2) Calculator CTA URL
        $calc_url = $this->resolve_calculator_catalog_url($lang);

        // 3) Preprocess categories & articles, then deduplicate shared fittings/accessories for LOTSMAN hubs
        $prepared_tabs = [];
        foreach ($categories as $cat) {
            $tab_items = [];
            foreach ($cat['articles'] as $art) {
                $art_id = (int) $art->id;
                $jf_art = $jf_map !== null
                    ? ($jf_map[$art_id] ?? [])
                    : $this->migrator()->get_joomfish_fields_for_article($art_id);

                $art_title = trim($this->pick_localized_field($jf_art, $lang, 'title', (string) ($art->title ?? '')));
                $art_intro = $this->pick_localized_field($jf_art, $lang, 'introtext', (string) ($art->introtext ?? ''));
                $art_full = $this->pick_localized_field($jf_art, $lang, 'fulltext', (string) ($art->fulltext ?? ''));

                $combined = str_replace(
                    '<!--more-->',
                    '',
                    $this->migrator()->combine_intro_and_fulltext($art_intro, $art_full)
                );
                $processed = $this->migrator()->process_article_html($combined, false, $art_id, $art_title, false);

                if ($first_webp_rel === null && $processed['first_webp_rel'] !== null) {
                    $first_webp_rel = $processed['first_webp_rel'];
                }

                $cleaned = $this->clean_product_media_and_tables($processed['html'], $art_id, true);
                $tab_items[] = [
                    'id'         => $art_id,
                    'title'      => $art_title,
                    'base_title' => $this->normalize_product_base_title($art_title),
                    'body_html'  => $cleaned['html'],
                    'thumb_url'  => $cleaned['first_thumb_url'],
                ];
            }

            if ($tab_items === []) {
                continue;
            }

            $prepared_tabs[] = [
                'tab_slug'             => (string) $cat['tab_slug'],
                'title'                => (string) ($cat['titles'][$lang] ?? $cat['titles']['uk']),
                'items'                => $tab_items,
                'has_accessories_link' => false,
            ];
        }

        if (
            in_array($def['key'], ['lotsman-metal-tray', 'lotsman-ladder-tray'], true)
            && count($prepared_tabs) >= 2
        ) {
            $prepared_tabs = $this->deduplicate_shared_accessories_into_tab($prepared_tabs, $i18n);
        }

        // 4) Render tabs navigation + panes
        $nav_buttons = [];
        $tab_panes = [];

        foreach ($prepared_tabs as $cat_index => $tab) {
            $tab_slug = $tab['tab_slug'];
            $tab_title = $tab['title'];
            $is_active = $cat_index === 0;
            $is_tab_discontinued = !empty($discontinued_tabs[$tab_slug]);
            $show_discontinued = $is_hub_discontinued || $is_tab_discontinued;

            $tab_dot_html = $show_discontinued
                ? '<span class="scat-tab-discontinued-dot" title="' . esc_attr($i18n['discontinued']) . '" aria-label="' . esc_attr($i18n['discontinued']) . '"></span>'
                : '';

            $nav_buttons[] = sprintf(
                '<a href="#%1$s" class="scat-hub-tab-btn%2$s%3$s" data-tab="%1$s">%4$s%5$s</a>',
                esc_attr($tab_slug),
                $is_active ? ' is-active' : '',
                $show_discontinued ? ' is-discontinued' : '',
                esc_html($tab_title),
                $tab_dot_html
            );

            $items = $tab['items'];
            $use_accordion = count($items) > 2;

            $pane_inner = '';
            if ($show_discontinued) {
                $pane_inner .= '<div class="scat-tab-discontinued-header"><span class="scat-discontinued-badge">⚠️ ' . esc_html($i18n['discontinued']) . '</span></div>';
            }

            if ($use_accordion) {
                $pane_inner .= '<div class="scat-hub-pane-toolbar">'
                    . '<div class="scat-hub-filter-wrap">'
                    . '<div class="scat-hub-search-mount" data-placeholder="' . esc_attr($i18n['filter_placeholder']) . '"></div>'
                    . '</div>'
                    . '<div class="scat-hub-expand-btns">'
                    . '<a href="#expand-all" class="scat-hub-action-btn scat-hub-expand-all" data-action="expand">' . esc_html($i18n['expand_all']) . '</a>'
                    . '<a href="#collapse-all" class="scat-hub-action-btn scat-hub-collapse-all" data-action="collapse">' . esc_html($i18n['collapse_all']) . '</a>'
                    . '</div>'
                    . '</div>';
            }

            $pill_html = $show_discontinued
                ? ' <span class="scat-discontinued-pill">' . esc_html($i18n['discontinued']) . '</span>'
                : '';

            $cards_html = [];
            foreach ($items as $art_idx => $item) {
                $art_id = (int) $item['id'];
                $art_title = (string) $item['title'];
                $body_html = (string) $item['body_html'];
                $thumb_url = (string) $item['thumb_url'];

                if (!$use_accordion) {
                    $cards_html[] = '<div class="scat-prod-expanded-block">'
                        . '<h3 class="scat-prod-expanded-title">' . esc_html($art_title) . $pill_html . '</h3>'
                        . '<div class="scat-prod-expanded-body">' . $body_html . '</div>'
                        . '</div>';
                } else {
                    $is_open = $art_idx < 2;
                    $excerpt_line = $this->extract_first_sentence($body_html, 155);
                    $thumb_markup = $thumb_url !== ''
                        ? '<span class="scat-prod-card-thumb"><img src="' . esc_url($thumb_url) . '" alt="' . esc_attr($art_title) . '" loading="lazy" /></span>'
                        : '';
                    $excerpt_markup = $excerpt_line !== ''
                        ? '<span class="scat-prod-card-excerpt">' . esc_html($excerpt_line) . '</span>'
                        : '';

                    $header_markup = '<div class="scat-prod-card-header" role="button" tabindex="0" aria-expanded="' . ($is_open ? 'true' : 'false') . '">'
                        . $thumb_markup
                        . '<span class="scat-prod-card-meta">'
                        . '<span class="scat-prod-card-title">' . esc_html($art_title) . $pill_html . '</span>'
                        . $excerpt_markup
                        . '</span>'
                        . '<span class="scat-prod-card-toggle">' . esc_html($i18n['details_toggle']) . ' <span class="scat-prod-chevron">▾</span></span>'
                        . '</div>';

                    $cards_html[] = '<article class="scat-prod-card' . ($is_open ? ' is-open' : '') . '" data-prod-id="' . $art_id . '">'
                        . $header_markup
                        . '<div class="scat-prod-card-body">' . $body_html . '</div>'
                        . '</article>';
                }
            }

            $pane_inner .= '<div class="scat-hub-cards-list">' . implode('', $cards_html) . '</div>';

            if (!empty($tab['has_accessories_link'])) {
                $pane_inner .= '<div class="scat-hub-accessories-link-wrap">'
                    . '<a href="#aksesuary" class="scat-hub-accessories-link" data-switch-tab="aksesuary">'
                    . esc_html($i18n['accessories_link_label'])
                    . '</a>'
                    . '</div>';
            }

            $tab_panes[] = sprintf(
                '<section id="%1$s" class="scat-hub-tab-pane%2$s" data-tab-pane="%1$s">%3$s</section>',
                esc_attr($tab_slug),
                $is_active ? ' is-active' : '',
                $pane_inner
            );
        }

        $cta_markup = '<div class="scat-hub-cta">'
            . '<div class="scat-hub-cta-text">' . esc_html($i18n['cta_hint']) . '</div>'
            . '<a href="' . esc_url($calc_url) . '" class="scat-hub-cta-btn">' . esc_html($i18n['cta_button']) . '</a>'
            . '</div>';

        $hub_discontinued_banner = $is_hub_discontinued
            ? '<div class="scat-hub-discontinued-banner"><span class="scat-discontinued-badge">⚠️ ' . esc_html($i18n['discontinued']) . '</span></div>'
            : '';

        $hub_title = $def['titles'][$lang] ?? $def['titles']['uk'];

        $hub_html = '<div class="scat-product-hub" data-hub-key="' . esc_attr($def['key']) . '">'
            . '<h1 class="scat-hub-main-title">' . esc_html($hub_title) . '</h1>'
            . $hub_discontinued_banner
            . '<div class="scat-hub-intro">' . $intro_html . $cta_markup . '</div>'
            . '<nav class="scat-hub-tabs-nav" aria-label="' . esc_attr($hub_title) . '">' . implode('', $nav_buttons) . '</nav>'
            . '<div class="scat-hub-tabs-content">' . implode('', $tab_panes) . '</div>'
            . '</div>';

        $excerpt_plain = wp_strip_all_tags($intro_html);
        $excerpt_plain = trim((string) preg_replace('/\s+/u', ' ', $excerpt_plain));
        if (mb_strlen($excerpt_plain, 'UTF-8') > 270) {
            $excerpt_plain = mb_substr($excerpt_plain, 0, 267, 'UTF-8') . '…';
        }

        return [
            'html'           => $hub_html,
            'excerpt'        => $excerpt_plain,
            'metadesc'       => $metadesc !== '' ? $metadesc : $excerpt_plain,
            'first_webp_rel' => $first_webp_rel,
        ];
    }

    /**
     * Deduplicate repeated fittings, covers, connectors, and accessories across height tabs
     * into one consolidated #aksesuary tab with merged SKU-deduplicated specification tables.
     *
     * @param array<int, array{tab_slug: string, title: string, items: array<int, array<string, mixed>>, has_accessories_link: bool}> $tabs
     * @param array<string, string> $i18n
     * @return array<int, array{tab_slug: string, title: string, items: array<int, array<string, mixed>>, has_accessories_link: bool}>
     */
    private function deduplicate_shared_accessories_into_tab(array $tabs, array $i18n): array
    {
        $base_tab_counts = [];
        foreach ($tabs as $tab_idx => $tab) {
            foreach ($tab['items'] as $item) {
                $base = (string) ($item['base_title'] ?? '');
                if ($base === '' || $this->is_core_tray_or_divider_title($base)) {
                    continue;
                }
                $base_tab_counts[$base][$tab_idx] = true;
            }
        }

        $shared_bases = [];
        foreach ($base_tab_counts as $base => $tab_map) {
            if (count($tab_map) >= 2) {
                $shared_bases[$base] = true;
            }
        }

        if ($shared_bases === []) {
            return $tabs;
        }

        $shared_groups = [];
        $updated_tabs = [];

        foreach ($tabs as $tab) {
            $kept_items = [];
            $moved_any = false;

            foreach ($tab['items'] as $item) {
                $base = (string) ($item['base_title'] ?? '');
                if ($base !== '' && isset($shared_bases[$base])) {
                    $shared_groups[$base][] = $item;
                    $moved_any = true;
                } else {
                    $kept_items[] = $item;
                }
            }

            if ($kept_items !== []) {
                $tab['items'] = $kept_items;
                $tab['has_accessories_link'] = $moved_any;
                $updated_tabs[] = $tab;
            }
        }

        $consolidated_items = [];
        foreach ($shared_groups as $group_items) {
            $primary = $group_items[0];
            $primary['title'] = $this->clean_shared_display_title((string) $primary['title']);

            if (count($group_items) > 1) {
                $extra_htmls = [];
                for ($i = 1, $len = count($group_items); $i < $len; $i++) {
                    $extra_htmls[] = (string) $group_items[$i]['body_html'];
                }
                $primary['body_html'] = $this->merge_product_table_rows((string) $primary['body_html'], $extra_htmls);
            }

            $consolidated_items[] = $primary;
        }

        if ($consolidated_items !== []) {
            $updated_tabs[] = [
                'tab_slug'             => 'aksesuary',
                'title'                => $i18n['accessories_tab_title'],
                'items'                => $consolidated_items,
                'has_accessories_link' => false,
            ];
        }

        return $updated_tabs !== [] ? $updated_tabs : $tabs;
    }

    /**
     * Normalize a product title by stripping height/series suffixes (H=35, H=50, H=80, H=100, Standard, Light)
     * so identical fittings/covers/connectors across height tabs can be grouped.
     */
    private function normalize_product_base_title(string $title): string
    {
        $s = mb_strtolower(trim($title), 'UTF-8');
        $s = (string) preg_replace('/\b(?:сер[іи][яї]\s*)?(?:standard|стандарт|light|лайт)\b/u', ' ', $s);
        $s = (string) preg_replace('/(?:^|[\s,\-\(\/])(?:[hн]\s*=?\s*(?:35|47|50|60|80|100|120)|висот(?:а|ою)\s*\d+)(?:\s*(?:мм|mm))?(?=$|[\s,\-\)\/\.])/u', ' ', $s);
        $s = (string) preg_replace('/\(\s*\)/u', ' ', $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim($s);
    }

    /**
     * Clean height/series suffix from a consolidated shared product display title.
     */
    private function clean_shared_display_title(string $title): string
    {
        $clean = (string) preg_replace('/[\s,\-\(]*(?:сер[іи][яї]\s*)?(?:standard|стандарт|light|лайт)?\s*[hн]\s*=?\s*(?:35|47|50|60|80|100|120)(?:\s*(?:мм|mm))?\)?/iu', '', $title);
        $clean = trim((string) preg_replace('/\s{2,}/u', ' ', $clean), " \t\n\r\0\x0B,-–—");
        return $clean !== '' ? $clean : trim($title);
    }

    /**
     * Determine whether a normalized product title is a core height-dependent tray or divider
     * (stays inside each height tab) vs a fitting/cover/connector/accessory.
     */
    private function is_core_tray_or_divider_title(string $base_title): bool
    {
        if (preg_match('/(?:кришк|крышк|з[\'’ʼ]?єднувач|соедин|гвинт|винт|гайк|шайб|пластин|підвіс|подвес|скоб|заглушк|секці|секци|кут(?:ов)?|угл|т под|т обр|х под|х обр|відвід|отвод|поворот|перехід|переход|підйом|подъем|спуск|трійник|тройник|хрестовин|крестовин|кронштейн|консол|шпильк|болт|анкер|метиз|аксесуар|аксессуар|притиск|прижим|фіксатор|фиксатор|тримач|держател)/u', $base_title) === 1) {
            return false;
        }

        return preg_match('/(?:лоток|лотк|перегородк|розділювач|разделител|tray|divider)/u', $base_title) === 1;
    }

    /**
     * Merge specification table data rows (<tr> with <td>) from additional height-variant HTML blocks
     * into the primary product HTML's first table, skipping duplicate rows by SKU / normalized row text.
     *
     * @param array<int, string> $additional_htmls
     */
    private function merge_product_table_rows(string $primary_html, array $additional_htmls): string
    {
        if ($additional_htmls === [] || stripos($primary_html, '<table') === false) {
            return $primary_html;
        }

        if (!preg_match('~<table\b[^>]*>.*?</table>~is', $primary_html, $primary_table_match)) {
            return $primary_html;
        }

        $primary_table = $primary_table_match[0];
        $seen_keys = [];

        if (preg_match_all('~<tr\b[^>]*>.*?</tr>~is', $primary_table, $primary_rows)) {
            foreach ($primary_rows[0] as $tr) {
                $key = $this->extract_table_row_dedup_key($tr);
                if ($key !== '') {
                    $seen_keys[$key] = true;
                }
            }
        }

        $extra_rows_html = '';
        foreach ($additional_htmls as $extra_html) {
            if (stripos($extra_html, '<table') === false) {
                continue;
            }
            if (!preg_match_all('~<tr\b[^>]*>.*?</tr>~is', $extra_html, $rows_match)) {
                continue;
            }
            foreach ($rows_match[0] as $tr) {
                if (stripos($tr, '<td') === false) {
                    continue;
                }
                $key = $this->extract_table_row_dedup_key($tr);
                if ($key === '' || isset($seen_keys[$key])) {
                    continue;
                }
                $seen_keys[$key] = true;
                $extra_rows_html .= $tr;
            }
        }

        if ($extra_rows_html === '') {
            return $primary_html;
        }

        $replaced_table = stripos($primary_table, '</tbody>') !== false
            ? (string) preg_replace('~</tbody>~i', $extra_rows_html . '</tbody>', $primary_table, 1)
            : (string) preg_replace('~</table>~i', $extra_rows_html . '</table>', $primary_table, 1);

        $pos = strpos($primary_html, $primary_table);
        if ($pos === false) {
            return $primary_html;
        }

        return substr($primary_html, 0, $pos)
            . $replaced_table
            . substr($primary_html, $pos + strlen($primary_table));
    }

    /**
     * Build a deduplication key for a specification table row (prefers first <td> SKU when present).
     */
    private function extract_table_row_dedup_key(string $tr_html): string
    {
        if (stripos($tr_html, '<td') === false) {
            return '';
        }

        $full_text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($tr_html))), 'UTF-8');
        if ($full_text === '') {
            return '';
        }

        if (preg_match('~<td\b[^>]*>(.*?)</td>~is', $tr_html, $m) === 1) {
            $first_cell = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($m[1]))), 'UTF-8');
            if (mb_strlen($first_cell, 'UTF-8') >= 3 && preg_match('/\d/', $first_cell) === 1) {
                return 'sku:' . $first_cell;
            }
        }

        return 'row:' . $full_text;
    }

    /**
     * Pick localized field from Joom!Fish array with fallback to jos_content original value.
     *
     * @param array<string, array<string, string>> $jf_fields
     */
    private function pick_localized_field(array $jf_fields, string $lang, string $field, string $original): string
    {
        if (isset($jf_fields[$lang][$field]) && trim($jf_fields[$lang][$field]) !== '') {
            return $jf_fields[$lang][$field];
        }
        return $original;
    }

    /**
     * Resolve URL of the WordPress page with slug `scat-program` (or containing `[scat_app]`) + `#catalog`
     * for the target language, falling back to `/{lang}/scat-program/#catalog` (never `/#catalog`).
     */
    private function resolve_calculator_catalog_url(string $lang): string
    {
        global $wpdb;

        $queries = [
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type = 'page'
                   AND post_name = %s
                 ORDER BY ID ASC
                 LIMIT 10",
                'scat-program'
            ),
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type = 'page'
                   AND post_content LIKE %s
                 ORDER BY ID ASC
                 LIMIT 10",
                '%[scat_app%'
            ),
        ];

        foreach ($queries as $sql) {
            $ids = is_string($sql) ? $wpdb->get_col($sql) : [];
            if (!is_array($ids) || $ids === []) {
                continue;
            }

            $calc_page_id = 0;
            if (function_exists('pll_get_post_language')) {
                foreach ($ids as $candidate_id) {
                    $cid = (int) $candidate_id;
                    if ($cid > 0 && pll_get_post_language($cid) === $lang) {
                        $calc_page_id = $cid;
                        break;
                    }
                }
            }

            if ($calc_page_id <= 0) {
                $base_id = (int) $ids[0];
                $calc_page_id = $base_id;
                if (function_exists('pll_get_post')) {
                    $tr_id = (int) pll_get_post($base_id, $lang);
                    if ($tr_id > 0) {
                        $calc_page_id = $tr_id;
                    }
                }
            }

            if ($calc_page_id > 0) {
                $link = get_permalink($calc_page_id);
                if (is_string($link) && $link !== '') {
                    $clean_base = explode('#', $link, 2)[0];
                    if ($clean_base !== '') {
                        return untrailingslashit($clean_base) . '/#catalog';
                    }
                }
            }
        }

        if ($lang === 'uk') {
            return untrailingslashit((string) home_url('/scat-program/')) . '/#catalog';
        }

        return untrailingslashit((string) home_url('/' . $lang . '/scat-program/')) . '/#catalog';
    }

    /**
     * Clean product HTML:
     * 1) Remove old "Додати до кошторису" / "Добавить в смету" / "Add to estimate" table columns
     *    via DOMDocument (preserving rowspan/colspan integrity), strip <script>/<form>/onclick,
     *    and wrap tables in <div class="scat-prod-table-wrap"><table class="scat-prod-spec-table">.
     * 2) Remove legacy zoom icons (zoom.png, lupa.png, yoo_effects) and make product drawings/images
     *    clickable lightbox items (.scat-gallery-item.scat-prod-drawing-item).
     *
     * @return array{html: string, first_thumb_url: string}
     */
    public function clean_product_media_and_tables(string $html, int $product_id = 0, bool $make_drawings_clickable = true): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'first_thumb_url' => ''];
        }

        $working = $html;

        // Strip <script> and <noscript> tags early
        $working = (string) preg_replace('~<(script|noscript)\b[^>]*>.*?</\1>~is', '', $working);

        // Remove inline JS event handlers (onclick="...", onchange="...", onsubmit="...")
        $working = (string) preg_replace('/\s+on(?:click|change|submit|mouseover|mouseout)\s*=\s*(["\']).*?\1/is', '', $working);

        // Remove legacy zoom/magnifier icons (zoom.png, lupa.png, magnifier, etc.)
        $working = (string) preg_replace(
            '~<img\b[^>]*\bsrc\s*=\s*(["\'])[^"\']*(?:zoom|lupa|magnif|yoo_effects)[^"\']*\1[^>]*>~is',
            '',
            $working
        );

        // Clean HTML tables via DOMDocument (preserving rowspan/colspan)
        if (stripos($working, '<table') !== false) {
            $working = $this->clean_estimate_columns_in_tables($working);
        }

        // Unwrap empty <form> wrappers left after removing table estimate forms
        $working = (string) preg_replace('~</?form\b[^>]*>~is', '', $working);

        // Make product images (outside tables) clickable lightbox items
        $first_thumb_url = '';
        if ($make_drawings_clickable && stripos($working, '<img') !== false) {
            $working = $this->enhance_product_drawings_for_lightbox($working, $product_id, $first_thumb_url);
        } elseif (preg_match('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/i', $working, $im) === 1) {
            $first_thumb_url = html_entity_decode(trim($im[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $working = $this->compress_cleaned_article_html($working);

        return [
            'html'            => $working,
            'first_thumb_url' => $first_thumb_url,
        ];
    }

    /**
     * Strip legacy Joomla inline formatting attributes (style, width, height, border, cellpadding,
     * cellspacing, align, valign, bgcolor, face, size, color), <font> wrappers, empty paragraphs/spans,
     * and redundant whitespace/newlines to keep hub HTML compact (<100 KB) and Divi 5-safe.
     */
    public function compress_cleaned_article_html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Remove legacy presentational attributes inside HTML tags
        $html = (string) preg_replace_callback(
            '/<([a-z][a-z0-9]*)(\s+[^>]*)>/i',
            static function (array $m): string {
                $tag = $m[1];
                $attrs = $m[2];
                $attrs = (string) preg_replace(
                    '/\s+(?:style|width|height|border|cellpadding|cellspacing|align|valign|bgcolor|face|size|color|frame|rules|nowrap)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
                    '',
                    $attrs
                );
                $attrs = (string) preg_replace('/\s+class\s*=\s*(["\'])\s*(?:MsoNormal|MsoTableGrid)?\s*\1/i', '', $attrs);
                $attrs = (string) preg_replace('/\s{2,}/', ' ', $attrs);
                return '<' . $tag . rtrim($attrs) . '>';
            },
            $html
        );

        // Unwrap legacy <font> tags
        $html = (string) preg_replace('~</?font\b[^>]*>~i', '', $html);

        // Remove empty paragraphs/spans/divs containing only whitespace or &nbsp;
        for ($pass = 0; $pass < 2; $pass++) {
            $html = (string) preg_replace(
                '~<(p|span|strong|em|b|i|u)\b[^>]*>(?:\s|&nbsp;|&#160;|\x{00A0}|<br\s*/?>)*</\1>~iu',
                '',
                $html
            );
        }

        // Collapse excessive whitespace and inter-tag newlines
        $html = (string) preg_replace('/\r\n|\r|\n/', ' ', $html);
        $html = (string) preg_replace('/>\s+</', '><', $html);
        $html = (string) preg_replace('/\s{2,}/', ' ', $html);

        return trim($html);
    }

    /**
     * Parse every <table> using DOMDocument, detect and remove the legacy estimate/cart column
     * ("Додати до кошторису" / "Добавить в смету" / "Add to estimate" / <input> / addtovar),
     * adjust colspan/rowspan safely, and wrap in .scat-prod-table-wrap > table.scat-prod-spec-table.
     * Falls back to safe regex cleanup if DOMDocument/DOMXPath is unavailable or throws.
     */
    private function clean_estimate_columns_in_tables(string $html): string
    {
        if (!class_exists('\DOMDocument') || !class_exists('\DOMXPath')) {
            return $this->clean_estimate_columns_regex_fallback($html);
        }

        $has_libxml = function_exists('libxml_use_internal_errors');
        $prev_errors = $has_libxml ? libxml_use_internal_errors(true) : false;

        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');

            if (function_exists('mb_encode_numericentity')) {
                $encoded_html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
                $wrapped_html = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="scat-dom-root">'
                    . $encoded_html
                    . '</div></body></html>';
            } else {
                $wrapped_html = '<?xml encoding="UTF-8">'
                    . '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="scat-dom-root">'
                    . $html
                    . '</div></body></html>';
            }

            $load_flags = (defined('LIBXML_NOWARNING') ? LIBXML_NOWARNING : 0)
                | (defined('LIBXML_NOERROR') ? LIBXML_NOERROR : 0);

            $loaded = $dom->loadHTML($wrapped_html, $load_flags);
            if (!$loaded) {
                return $this->clean_estimate_columns_regex_fallback($html);
            }

            $xpath = new \DOMXPath($dom);
            $tables = $xpath->query('//table');
            if ($tables instanceof \DOMNodeList) {
                $table_list = [];
                foreach ($tables as $tbl) {
                    if ($tbl instanceof \DOMElement) {
                        $table_list[] = $tbl;
                    }
                }

                foreach ($table_list as $table) {
                    if (!$table instanceof \DOMElement || $table->parentNode === null) {
                        continue;
                    }

                    $this->strip_estimate_column_from_table_element($dom, $table);

                    // Clean legacy attributes on table
                    foreach (['width', 'border', 'cellpadding', 'cellspacing', 'bgcolor'] as $attr) {
                        if ($table->hasAttribute($attr)) {
                            $table->removeAttribute($attr);
                        }
                    }

                    $existing_cls = trim((string) $table->getAttribute('class'));
                    if (strpos($existing_cls, 'scat-prod-spec-table') === false) {
                        $table->setAttribute('class', trim($existing_cls . ' scat-prod-spec-table'));
                    }

                    // Wrap in <div class="scat-prod-table-wrap"> if not already wrapped
                    $parent = $table->parentNode;
                    $already_wrapped = $parent instanceof \DOMElement
                        && strpos((string) $parent->getAttribute('class'), 'scat-prod-table-wrap') !== false;

                    if (!$already_wrapped && $parent !== null) {
                        $wrapper = $dom->createElement('div');
                        $wrapper->setAttribute('class', 'scat-prod-table-wrap');
                        $parent->insertBefore($wrapper, $table);
                        $wrapper->appendChild($table);
                    }
                }
            }

            $root_nodes = $xpath->query('//*[@id="scat-dom-root"]');
            $root = ($root_nodes instanceof \DOMNodeList && $root_nodes->length > 0)
                ? $root_nodes->item(0)
                : $dom->getElementById('scat-dom-root');

            if (!$root instanceof \DOMElement) {
                return $this->clean_estimate_columns_regex_fallback($html);
            }

            $out = '';
            foreach ($root->childNodes as $child) {
                $chunk = $dom->saveHTML($child);
                if (is_string($chunk)) {
                    $out .= $chunk;
                }
            }

            if ($out === '') {
                return $this->clean_estimate_columns_regex_fallback($html);
            }

            if (function_exists('mb_decode_numericentity')) {
                $out = mb_decode_numericentity($out, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
            }

            return $out;
        } catch (\Throwable $e) {
            return $this->clean_estimate_columns_regex_fallback($html);
        } finally {
            if ($has_libxml) {
                libxml_clear_errors();
                libxml_use_internal_errors($prev_errors);
            }
        }
    }

    /**
     * Regex fallback cleaner for legacy estimate columns and table wrapping when DOMDocument is unavailable or fails.
     */
    public function clean_estimate_columns_regex_fallback(string $html): string
    {
        $working = $html;

        // Strip estimate header cells (<th>...кошторис/смет/estimate...</th>)
        $stripped_th = preg_replace(
            '~<th\b[^>]*>(?:(?!</th>).)*(?:кошторис|смет|estimate|add\s*to|кошик|корзин)(?:(?!</th>).)*</th>~isu',
            '',
            $working
        );
        if (is_string($stripped_th)) {
            $working = $stripped_th;
        }

        // Strip estimate data cells (<td>...<input>/<button>/addtovar/кошторис...</td>)
        $stripped_td = preg_replace(
            '~<td\b[^>]*>(?:(?!</td>).)*(?:<input\b|<button\b|addtovar|kosht|smet|кошторис|смет|estimate)(?:(?!</td>).)*</td>~isu',
            '',
            $working
        );
        if (is_string($stripped_td)) {
            $working = $stripped_td;
        }

        // Remove empty table rows left after stripping cells
        $stripped_tr = preg_replace('~<tr\b[^>]*>\s*</tr>~is', '', $working);
        if (is_string($stripped_tr)) {
            $working = $stripped_tr;
        }

        // Clean legacy attributes on <table>, add scat-prod-spec-table class, and wrap in .scat-prod-table-wrap
        $wrapped = preg_replace_callback(
            '~(<div\b[^>]*\bscat-prod-table-wrap\b[^>]*>\s*)?(<table\b[^>]*>)(.*?</table>)~is',
            static function (array $m): string {
                $existing_wrap = $m[1] ?? '';
                $table_open = $m[2];
                $table_rest = $m[3];

                $cleaned_open = preg_replace('/\s+(?:width|border|cellpadding|cellspacing|bgcolor)\s*=\s*(["\']).*?\1/is', '', $table_open);
                $table_open = is_string($cleaned_open) ? $cleaned_open : $table_open;

                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $table_open, $cm) === 1) {
                    $quote = $cm[1];
                    $cls = trim($cm[2]);
                    if (strpos($cls, 'scat-prod-spec-table') === false) {
                        $new_cls = trim($cls . ' scat-prod-spec-table');
                        $updated = preg_replace_callback(
                            '/\bclass\s*=\s*(["\']).*?\1/is',
                            static function () use ($quote, $new_cls): string {
                                return 'class=' . $quote . esc_attr($new_cls) . $quote;
                            },
                            $table_open,
                            1
                        );
                        if (is_string($updated)) {
                            $table_open = $updated;
                        }
                    }
                } else {
                    $updated = preg_replace('/^<table\b/i', '<table class="scat-prod-spec-table"', $table_open, 1);
                    if (is_string($updated)) {
                        $table_open = $updated;
                    }
                }

                if ($existing_wrap !== '') {
                    return $existing_wrap . $table_open . $table_rest;
                }

                return '<div class="scat-prod-table-wrap">' . $table_open . $table_rest . '</div>';
            },
            $working
        );

        return is_string($wrapped) ? $wrapped : $working;
    }

    /**
     * Build a 2D rowspan/colspan matrix for a single <table> and remove all cells belonging to
     * "Додати до кошторису" / "Добавить в смету" / "Add to estimate" or containing <input>/<button>.
     */
    private function strip_estimate_column_from_table_element(\DOMDocument $dom, \DOMElement $table): void
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            if ($tr instanceof \DOMElement && $this->is_direct_table_row($tr, $table)) {
                $rows[] = $tr;
            }
        }
        if ($rows === []) {
            return;
        }

        $occupied = [];
        $cell_positions = [];
        $cols_to_remove = [];

        foreach ($rows as $r_idx => $tr) {
            $col_cursor = 0;
            $cells = [];
            foreach ($tr->childNodes as $child) {
                if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                    $cells[] = $child;
                }
            }

            foreach ($cells as $cell) {
                while (isset($occupied[$r_idx][$col_cursor]) && $col_cursor < 500) {
                    $col_cursor++;
                }

                $colspan = min(60, max(1, (int) $cell->getAttribute('colspan')));
                $rowspan = min(300, max(1, (int) $cell->getAttribute('rowspan')));

                for ($rr = $r_idx; $rr < $r_idx + $rowspan; $rr++) {
                    for ($cc = $col_cursor; $cc < $col_cursor + $colspan; $cc++) {
                        $occupied[$rr][$cc] = true;
                    }
                }

                $cell_positions[] = [
                    'row'     => $tr,
                    'cell'    => $cell,
                    'start'   => $col_cursor,
                    'colspan' => $colspan,
                ];

                if ($this->is_estimate_cell($cell, $dom)) {
                    for ($cc = $col_cursor; $cc < $col_cursor + $colspan; $cc++) {
                        $cols_to_remove[$cc] = true;
                    }
                }

                $col_cursor += $colspan;
            }
        }

        if ($cols_to_remove === []) {
            return;
        }

        foreach ($cell_positions as $info) {
            $cell = $info['cell'];
            if (!$cell instanceof \DOMElement) {
                continue;
            }
            $start = (int) $info['start'];
            $colspan = (int) $info['colspan'];

            $kept_cols = 0;
            for ($cc = $start; $cc < $start + $colspan; $cc++) {
                if (!isset($cols_to_remove[$cc])) {
                    $kept_cols++;
                }
            }

            if ($kept_cols === 0) {
                if ($cell->parentNode !== null) {
                    $cell->parentNode->removeChild($cell);
                }
            } elseif ($kept_cols < $colspan) {
                if ($kept_cols === 1) {
                    if ($cell->hasAttribute('colspan')) {
                        $cell->removeAttribute('colspan');
                    }
                } else {
                    $cell->setAttribute('colspan', (string) $kept_cols);
                }
            }
        }

        // Clean any empty <tr> rows
        foreach ($rows as $tr) {
            if (!$tr instanceof \DOMElement || $tr->parentNode === null) {
                continue;
            }
            $has_cell = false;
            foreach ($tr->childNodes as $child) {
                if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                    $has_cell = true;
                    break;
                }
            }
            if (!$has_cell && $tr->parentNode !== null) {
                $tr->parentNode->removeChild($tr);
            }
        }
    }

    private function is_direct_table_row(\DOMElement $tr, \DOMElement $table): bool
    {
        $node = $tr->parentNode;
        while ($node instanceof \DOMElement) {
            if ($node === $table) {
                return true;
            }
            if (strtolower($node->tagName) === 'table') {
                return false;
            }
            $node = $node->parentNode;
        }
        return false;
    }

    private function is_estimate_cell(\DOMElement $cell, \DOMDocument $dom): bool
    {
        $inner_html = (string) $dom->saveHTML($cell);
        $text = mb_strtolower(trim((string) $cell->textContent), 'UTF-8');

        if (
            strpos($text, 'кошторис') !== false
            || strpos($text, 'смет') !== false
            || strpos($text, 'estimate') !== false
            || strpos($text, 'add to') !== false
            || strpos($text, 'кошик') !== false
            || strpos($text, 'корзин') !== false
        ) {
            return true;
        }

        if (
            stripos($inner_html, '<input') !== false
            || stripos($inner_html, '<button') !== false
            || stripos($inner_html, 'addtovar') !== false
            || stripos($inner_html, 'kosht') !== false
            || stripos($inner_html, 'smet') !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Wrap product drawings/photos (outside .scat-prod-table-wrap) in clickable lightbox anchors
     * with classes `scat-gallery-item scat-prod-drawing-item` and `data-scat-lightbox="prod-{id}"`.
     */
    private function enhance_product_drawings_for_lightbox(string $html, int $product_id, string &$first_thumb_url): string
    {
        $lightbox_group = 'prod-' . ($product_id > 0 ? $product_id : 'hub');

        // Protect .scat-prod-table-wrap blocks so images inside spec tables are not touched
        $table_holds = [];
        $working = preg_replace_callback(
            '~<div\b[^>]*\bscat-prod-table-wrap\b[^>]*>.*?</table>\s*</div>~is',
            static function (array $m) use (&$table_holds): string {
                $key = '<!--SCAT_TBL_HOLD_' . count($table_holds) . '-->';
                $table_holds[$key] = $m[0];
                return $key;
            },
            $html
        );
        if (!is_string($working)) {
            $working = $html;
        }

        // 1) Update existing <a>...<img ...>...</a> anchors
        $anchor_holds = [];
        $working = preg_replace_callback(
            '~(<a\b[^>]*>)((?:\s|\xC2\xA0|&nbsp;|</?(?:span|strong|em|b|i)\b[^>]*>)*<img\b[^>]*>(?:\s|\xC2\xA0|&nbsp;|</?(?:span|strong|em|b|i)\b[^>]*>)*)(</a>)~is',
            function (array $m) use (&$anchor_holds, &$first_thumb_url, $lightbox_group): string {
                $a_open = $m[1];
                $a_inner = $m[2];
                $a_close = $m[3];

                if (preg_match('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/i', $a_inner, $im) === 1) {
                    $img_src = html_entity_decode(trim($im[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($img_src !== '' && stripos($img_src, 'data:') !== 0) {
                        if ($first_thumb_url === '') {
                            $first_thumb_url = $img_src;
                        }

                        // Normalize href if it was javascript:/#/legacy or already an image
                        if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $a_open, $hm) === 1) {
                            $href_val = trim($hm[2]);
                            if (
                                $href_val === ''
                                || $href_val === '#'
                                || stripos($href_val, 'javascript:') === 0
                                || preg_match('/\.(?:webp|jpe?g|png|gif|bmp)(?:[?#].*)?$/i', $href_val) === 1
                            ) {
                                $target_href = preg_match('/\.(?:webp|jpe?g|png|gif|bmp)(?:[?#].*)?$/i', $href_val) === 1
                                    ? $href_val
                                    : $img_src;
                                $updated_open = preg_replace_callback(
                                    '/\bhref\s*=\s*(["\']).*?\1/i',
                                    static function () use ($target_href): string {
                                        return 'href="' . esc_url($target_href) . '"';
                                    },
                                    $a_open,
                                    1
                                );
                                if (is_string($updated_open)) {
                                    $a_open = $updated_open;
                                }
                            }
                        }

                        $a_open = $this->add_classes_and_lightbox_to_anchor(
                            $a_open,
                            ['scat-gallery-item', 'scat-prod-drawing-item'],
                            $lightbox_group
                        );
                    }
                }

                $akey = '<!--SCAT_PA_HOLD_' . count($anchor_holds) . '-->';
                $anchor_holds[$akey] = $a_open . $a_inner . $a_close;
                return $akey;
            },
            $working
        );
        if (!is_string($working)) {
            $working = $html;
        }

        // 2) Wrap standalone <img> tags outside anchors
        $working = preg_replace_callback(
            '~<img\b[^>]*>~is',
            function (array $m) use (&$first_thumb_url, $lightbox_group): string {
                $img_tag = $m[0];
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $img_tag, $sm) !== 1) {
                    return $img_tag;
                }
                $src = html_entity_decode(trim($sm[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($src === '' || stripos($src, 'data:') === 0) {
                    return $img_tag;
                }

                if ($first_thumb_url === '') {
                    $first_thumb_url = $src;
                }

                return '<a href="' . esc_url($src) . '" class="scat-gallery-item scat-prod-drawing-item" data-scat-lightbox="' . esc_attr($lightbox_group) . '">' . $img_tag . '</a>';
            },
            $working
        );
        if (!is_string($working)) {
            $working = $html;
        }

        if ($anchor_holds !== []) {
            $working = strtr($working, $anchor_holds);
        }
        if ($table_holds !== []) {
            $working = strtr($working, $table_holds);
        }

        return $working;
    }

    /**
     * @param array<int, string> $required_classes
     */
    private function add_classes_and_lightbox_to_anchor(string $a_open, array $required_classes, string $lightbox_group): string
    {
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $a_open, $cm) === 1) {
            $quote = $cm[1];
            $existing = trim($cm[2]);
            $classes = $existing !== '' ? (preg_split('/\s+/', $existing) ?: []) : [];
            foreach ($required_classes as $req) {
                if (!in_array($req, $classes, true)) {
                    $classes[] = $req;
                }
            }
            $class_str = implode(' ', $classes);
            $updated = preg_replace_callback(
                '/\bclass\s*=\s*(["\']).*?\1/is',
                static function () use ($quote, $class_str): string {
                    return 'class=' . $quote . esc_attr($class_str) . $quote;
                },
                $a_open,
                1
            );
            if (is_string($updated)) {
                $a_open = $updated;
            }
        } else {
            $class_str = implode(' ', $required_classes);
            $updated = preg_replace_callback(
                '/^<a\b/i',
                static function () use ($class_str): string {
                    return '<a class="' . esc_attr($class_str) . '"';
                },
                $a_open,
                1
            );
            if (is_string($updated)) {
                $a_open = $updated;
            }
        }

        if (stripos($a_open, 'data-scat-lightbox') === false) {
            $updated = preg_replace_callback(
                '/>$/',
                static function () use ($lightbox_group): string {
                    return ' data-scat-lightbox="' . esc_attr($lightbox_group) . '">';
                },
                $a_open,
                1
            );
            if (is_string($updated)) {
                $a_open = $updated;
            }
        }

        return $a_open;
    }

    /**
     * Extract first sentence from HTML description for accordion card header preview.
     */
    private function extract_first_sentence(string $html, int $max_len = 155): string
    {
        $no_tables = (string) preg_replace('~<table\b[^>]*>.*?</table>~is', ' ', $html);
        $plain = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode($no_tables, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if ($plain === '') {
            return '';
        }

        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $plain, $m) === 1 && mb_strlen($m[1], 'UTF-8') >= 20) {
            $sentence = trim($m[1]);
        } else {
            $sentence = $plain;
        }

        if (mb_strlen($sentence, 'UTF-8') > $max_len) {
            $sentence = mb_substr($sentence, 0, $max_len - 1, 'UTF-8') . '…';
        }

        return $sentence;
    }

    /**
     * @return array<string, string>
     */
    private function get_ui_strings(string $lang): array
    {
        if ($lang === 'ru') {
            return [
                'cta_hint'               => 'Нужен быстрый подбор сечений, нагрузок и расчёт спецификации?',
                'cta_button'             => '📐 Перейти к Инженерному калькулятору и каталогу',
                'filter_placeholder'     => 'Быстрый поиск по названию или артикулу...',
                'expand_all'             => 'Развернуть все',
                'collapse_all'           => 'Свернуть все',
                'details_toggle'         => 'Подробнее (чертежи и размеры)',
                'discontinued'           => 'Временно не производится',
                'accessories_tab_title'  => 'Фасонные элементы, крышки и аксессуары',
                'accessories_link_label' => '👉 Перейти к фасонным элементам, крышкам и соединителям для этой серии',
            ];
        }

        if ($lang === 'en') {
            return [
                'cta_hint'               => 'Need quick sizing, load calculation, and bill of materials?',
                'cta_button'             => '📐 Go to Engineering Calculator & Catalog',
                'filter_placeholder'     => 'Quick filter by name or SKU...',
                'expand_all'             => 'Expand all',
                'collapse_all'           => 'Collapse all',
                'details_toggle'         => 'Details (drawings & specs)',
                'discontinued'           => 'Temporarily out of production',
                'accessories_tab_title'  => 'Fittings, Covers & Accessories',
                'accessories_link_label' => '👉 View fittings, covers & connectors for this series',
            ];
        }

        return [
            'cta_hint'               => 'Потрібен швидкий підбір перерізів, навантажень та розрахунок специфікації?',
            'cta_button'             => '📐 Перейти до Інженерного калькулятора та каталогу',
            'filter_placeholder'     => 'Швидкий пошук за назвою або артикулом...',
            'expand_all'             => 'Розгорнути всі',
            'collapse_all'           => 'Згорнути всі',
            'details_toggle'         => 'Детальніше (креслення та розміри)',
            'discontinued'           => 'Тимчасово не виробляється',
            'accessories_tab_title'  => 'Фасонні елементи, кришки та аксесуари',
            'accessories_link_label' => '👉 Переглянути фасонні елементи, кришки та з\'єднувачі для цієї серії',
        ];
    }

    /**
     * Render frontend CSS & JS for .scat-product-hub pages (tabs, #hash deep-linking, accordions, live filter).
     */
    public function render_frontend_assets(): void
    {
        if (is_admin()) {
            return;
        }

        global $post;
        $has_hub = false;
        if ($post instanceof \WP_Post) {
            $raw_content = (string) $post->post_content;
            if (
                strpos($raw_content, 'scat-product-hub') !== false
                || get_post_meta($post->ID, self::META_HUB_KEY, true) !== ''
            ) {
                $has_hub = true;
            }
        }
        if (!$has_hub) {
            return;
        }
        ?>
        <style id="scat-product-hub-css">
            .scat-product-hub {
                --scat-primary: #006d77;
                --scat-primary-dark: #005259;
                --scat-accent: #e29578;
                --scat-accent-warm: #f4a261;
                --scat-surface: #ffffff;
                --scat-muted-bg: #f8fafc;
                --scat-border: #e2e8f0;
                --scat-text: #1e293b;
                --scat-text-muted: #64748b;
                font-family: inherit;
                color: var(--scat-text);
                max-width: 1180px;
                width: 100%;
                margin: 28px auto;
                padding: 24px 28px;
                background: #ffffff;
                border-radius: 10px;
                box-shadow: 0 2px 14px rgba(0, 0, 0, 0.06);
                box-sizing: border-box;
            }
            .et_pb_module .scat-product-hub,
            .et_pb_column .scat-product-hub {
                max-width: 100%;
                margin: 0;
                padding: 0;
                box-shadow: none;
                background: transparent;
            }
            .scat-hub-main-title {
                margin: 0 0 18px;
                font-size: 30px;
                line-height: 1.25;
                font-weight: 700;
                color: #0f172a;
            }
            .scat-product-hub *,
            .scat-product-hub *::before,
            .scat-product-hub *::after {
                box-sizing: border-box;
            }
            .scat-product-hub p:empty,
            .scat-hub-tabs-nav br,
            .scat-hub-pane-toolbar br,
            .scat-prod-card-header br {
                display: none !important;
            }
            .scat-hub-discontinued-banner,
            .scat-tab-discontinued-header {
                margin: 0 0 14px;
                max-width: 100%;
            }
            .scat-discontinued-badge {
                background: #fef2f2;
                border: 2px solid #dc2626;
                color: #b91c1c;
                font-weight: 700;
                font-size: 16px;
                padding: 10px 18px;
                border-radius: 8px;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                margin: 0;
                max-width: 100%;
                line-height: 1.35;
            }
            .scat-discontinued-pill {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                background: #fef2f2;
                border: 1px solid #dc2626;
                color: #b91c1c;
                font-weight: 700;
                font-size: 11.5px;
                padding: 2px 8px;
                border-radius: 999px;
                margin-left: 8px;
                vertical-align: middle;
                white-space: nowrap;
                line-height: 1.4;
            }
            .scat-hub-tab-btn.is-discontinued {
                border-color: #fca5a5;
            }
            .scat-tab-discontinued-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #dc2626;
                margin-left: 7px;
                flex-shrink: 0;
            }
            .scat-hub-tab-btn.is-active .scat-tab-discontinued-dot {
                background: #fecaca;
                border: 1px solid #ffffff;
            }
            .scat-hub-intro {
                background: var(--scat-surface);
                border: 1px solid var(--scat-border);
                border-radius: 12px;
                padding: 22px 24px;
                margin-bottom: 24px;
                box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
            }
            .scat-hub-intro img {
                max-width: 100%;
                height: auto;
                border-radius: 8px;
            }
            .scat-hub-cta {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 14px;
                margin-top: 18px;
                padding: 16px 20px;
                background: linear-gradient(135deg, #f0fdfa 0%, #fff7ed 100%);
                border: 1px solid rgba(0, 109, 119, 0.22);
                border-left: 4px solid var(--scat-primary);
                border-radius: 10px;
            }
            .scat-hub-cta-text {
                font-size: 15px;
                font-weight: 600;
                color: var(--scat-primary-dark);
            }
            .scat-hub-cta-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 11px 20px;
                background: var(--scat-primary);
                color: #ffffff !important;
                text-decoration: none !important;
                font-weight: 600;
                font-size: 14px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 109, 119, 0.24);
                transition: background 0.2s ease, transform 0.15s ease;
            }
            .scat-hub-cta-btn:hover {
                background: var(--scat-primary-dark);
                transform: translateY(-1px);
            }
            .scat-hub-tabs-nav {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 18px;
                padding-bottom: 12px;
                border-bottom: 2px solid var(--scat-border);
            }
            .scat-hub-tab-btn {
                display: inline-flex;
                align-items: center;
                padding: 10px 18px;
                background: var(--scat-muted-bg);
                border: 1px solid var(--scat-border);
                border-radius: 8px;
                color: var(--scat-text) !important;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none !important;
                cursor: pointer;
                transition: all 0.18s ease;
            }
            .scat-hub-tab-btn:hover {
                border-color: var(--scat-primary);
                color: var(--scat-primary) !important;
                background: #f0fdfa;
            }
            .scat-hub-tab-btn.is-active {
                background: var(--scat-primary);
                border-color: var(--scat-primary);
                color: #ffffff !important;
                box-shadow: 0 4px 10px rgba(0, 109, 119, 0.22);
            }
            .scat-hub-tab-pane {
                display: none;
            }
            .scat-hub-tab-pane.is-active {
                display: block;
                animation: scatHubFadeIn 0.2s ease;
            }
            @keyframes scatHubFadeIn {
                from { opacity: 0; transform: translateY(4px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .scat-hub-pane-toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 16px;
                padding: 12px 14px;
                background: var(--scat-muted-bg);
                border: 1px solid var(--scat-border);
                border-radius: 8px;
            }
            .scat-hub-filter-wrap {
                flex: 1 1 280px;
            }
            .scat-hub-filter-input {
                width: 100%;
                max-width: 420px;
                padding: 8px 12px;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                font-size: 14px;
                background: #ffffff;
            }
            .scat-hub-filter-input:focus {
                outline: none;
                border-color: var(--scat-primary);
                box-shadow: 0 0 0 2px rgba(0, 109, 119, 0.15);
            }
            .scat-hub-expand-btns {
                display: flex;
                gap: 8px;
            }
            .scat-hub-action-btn,
            .scat-hub-expand-all,
            .scat-hub-collapse-all {
                display: inline-flex;
                align-items: center;
                padding: 7px 14px;
                background: #ffffff;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                color: var(--scat-text);
                text-decoration: none;
                cursor: pointer;
                transition: border-color 0.15s ease, color 0.15s ease;
            }
            .scat-hub-action-btn:hover,
            .scat-hub-expand-all:hover,
            .scat-hub-collapse-all:hover {
                border-color: var(--scat-primary);
                color: var(--scat-primary);
                text-decoration: none;
            }
            .scat-hub-cards-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            .scat-prod-card {
                background: var(--scat-surface);
                border: 1px solid var(--scat-border);
                border-radius: 10px;
                overflow: hidden;
                transition: box-shadow 0.2s ease, border-color 0.2s ease;
            }
            .scat-prod-card.is-open {
                border-color: rgba(0, 109, 119, 0.35);
                box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            }
            .scat-prod-card.is-hidden-by-filter {
                display: none !important;
            }
            .scat-prod-card-header {
                display: flex;
                align-items: center;
                gap: 14px;
                padding: 14px 18px;
                background: var(--scat-muted-bg);
                cursor: pointer;
                user-select: none;
                transition: background 0.15s ease;
            }
            .scat-prod-card.is-open .scat-prod-card-header {
                background: #f0fdfa;
                border-bottom: 1px solid var(--scat-border);
            }
            .scat-prod-card-header:hover {
                background: #f1f5f9;
            }
            .scat-prod-card-thumb {
                flex: 0 0 64px;
                width: 64px;
                height: 64px;
                background: #ffffff;
                border: 1px solid var(--scat-border);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                padding: 4px;
            }
            .scat-prod-card-thumb img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                display: block;
            }
            .scat-prod-card-meta {
                flex: 1 1 auto;
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .scat-prod-card-title {
                font-size: 16px;
                font-weight: 700;
                color: var(--scat-primary-dark);
                line-height: 1.3;
            }
            .scat-prod-card-excerpt {
                font-size: 13px;
                color: var(--scat-text-muted);
                line-height: 1.4;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .scat-prod-card-toggle {
                flex: 0 0 auto;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 12px;
                border-radius: 6px;
                background: #ffffff;
                border: 1px solid #cbd5e1;
                font-size: 12.5px;
                font-weight: 600;
                color: var(--scat-primary);
            }
            .scat-prod-chevron {
                display: inline-block;
                transition: transform 0.2s ease;
            }
            .scat-prod-card.is-open .scat-prod-chevron {
                transform: rotate(180deg);
            }
            .scat-prod-card-body {
                display: none;
                padding: 18px 20px;
            }
            .scat-prod-card.is-open .scat-prod-card-body {
                display: block;
            }
            .scat-prod-expanded-block {
                background: var(--scat-surface);
                border: 1px solid var(--scat-border);
                border-radius: 10px;
                padding: 20px 22px;
                margin-bottom: 16px;
            }
            .scat-prod-expanded-title {
                margin: 0 0 14px;
                font-size: 20px;
                color: var(--scat-primary-dark);
                border-bottom: 2px solid #f0fdfa;
                padding-bottom: 8px;
            }
            .scat-prod-drawing-item {
                display: inline-block;
                position: relative;
                margin: 4px 8px 8px 0;
                border: 1px solid var(--scat-border);
                border-radius: 8px;
                overflow: hidden;
                background: #ffffff;
                padding: 4px;
                transition: transform 0.18s ease, box-shadow 0.18s ease;
            }
            .scat-prod-drawing-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 14px rgba(0, 109, 119, 0.16);
                border-color: var(--scat-primary);
            }
            .scat-prod-drawing-item img {
                max-height: 240px;
                width: auto;
                max-width: 100%;
                display: block;
                object-fit: contain;
            }
            .scat-prod-table-wrap {
                width: 100%;
                overflow-x: auto;
                margin: 14px 0 4px;
                border: 1px solid var(--scat-border);
                border-radius: 8px;
            }
            .scat-prod-spec-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13.5px;
                line-height: 1.4;
                margin: 0 !important;
            }
            .scat-prod-spec-table th,
            .scat-prod-spec-table td {
                padding: 8px 11px;
                border: 1px solid var(--scat-border);
                text-align: center;
                vertical-align: middle;
            }
            .scat-prod-spec-table thead th,
            .scat-prod-spec-table tr:first-child th,
            .scat-prod-spec-table tr:first-child td {
                background: #f0fdfa;
                color: var(--scat-primary-dark);
                font-weight: 700;
            }
            .scat-prod-spec-table tbody tr:nth-child(even) td {
                background: #f8fafc;
            }
            .scat-prod-spec-table tbody tr:hover td {
                background: #fff7ed;
            }
            .scat-hub-accessories-link-wrap {
                margin-top: 18px;
                padding: 14px 18px;
                background: #f0fdfa;
                border: 1px dashed rgba(0, 109, 119, 0.35);
                border-radius: 10px;
                text-align: center;
            }
            .scat-hub-accessories-link {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: var(--scat-primary-dark);
                font-weight: 700;
                font-size: 14.5px;
                text-decoration: none;
                padding: 8px 18px;
                border-radius: 999px;
                background: #ffffff;
                border: 1px solid rgba(0, 109, 119, 0.35);
                box-shadow: 0 2px 6px rgba(0, 109, 119, 0.08);
                transition: all 0.18s ease;
            }
            .scat-hub-accessories-link:hover {
                background: var(--scat-primary);
                color: #ffffff;
                border-color: var(--scat-primary);
                text-decoration: none;
                transform: translateY(-1px);
            }
            @media (max-width: 768px) {
                .scat-prod-card-header {
                    flex-wrap: wrap;
                }
                .scat-prod-card-excerpt {
                    white-space: normal;
                }
                .scat-prod-card-toggle {
                    width: 100%;
                    justify-content: center;
                }
                .scat-hub-cta {
                    flex-direction: column;
                    align-items: flex-start;
                }
            }
        </style>
        <script id="scat-product-hub-js">
        (function() {
            var hubs = document.querySelectorAll('.scat-product-hub');
            if (!hubs.length) {
                return;
            }

            function activateTab(hub, tabSlug, shouldScroll) {
                if (!tabSlug) {
                    return false;
                }
                var targetBtn = hub.querySelector('.scat-hub-tab-btn[data-tab="' + tabSlug.replace(/"/g, '\\"') + '"]');
                var targetPane = hub.querySelector('.scat-hub-tab-pane[data-tab-pane="' + tabSlug.replace(/"/g, '\\"') + '"]');
                if (!targetBtn || !targetPane) {
                    return false;
                }

                hub.querySelectorAll('.scat-hub-tab-btn').forEach(function(btn) {
                    btn.classList.toggle('is-active', btn === targetBtn);
                });
                hub.querySelectorAll('.scat-hub-tab-pane').forEach(function(pane) {
                    pane.classList.toggle('is-active', pane === targetPane);
                });

                if (shouldScroll) {
                    var nav = hub.querySelector('.scat-hub-tabs-nav') || targetPane;
                    var rect = nav.getBoundingClientRect();
                    var offsetTop = window.pageYOffset + rect.top - 90;
                    window.scrollTo({ top: Math.max(0, offsetTop), behavior: 'smooth' });
                }
                return true;
            }

            function syncFromHash(shouldScroll) {
                var hash = (window.location.hash || '').replace(/^#/, '').trim();
                if (!hash) {
                    return;
                }
                hubs.forEach(function(hub) {
                    activateTab(hub, hash, shouldScroll);
                });
            }

            hubs.forEach(function(hub) {
                hub.querySelectorAll('.scat-hub-tab-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var tabSlug = btn.getAttribute('data-tab');
                        if (activateTab(hub, tabSlug, false) && window.history && window.history.replaceState) {
                            window.history.replaceState(null, null, '#' + tabSlug);
                        }
                    });
                });

                hub.querySelectorAll('[data-switch-tab]').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        var targetSlug = link.getAttribute('data-switch-tab');
                        if (activateTab(hub, targetSlug, true) && window.history && window.history.replaceState) {
                            window.history.replaceState(null, null, '#' + targetSlug);
                        }
                    });
                });

                hub.querySelectorAll('.scat-prod-card-header').forEach(function(header) {
                    function toggleCard() {
                        var card = header.closest('.scat-prod-card');
                        if (!card) {
                            return;
                        }
                        var isOpen = card.classList.toggle('is-open');
                        header.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    }

                    header.addEventListener('click', toggleCard);
                    header.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            toggleCard();
                        }
                    });
                });

                hub.querySelectorAll('.scat-hub-expand-all').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var pane = btn.closest('.scat-hub-tab-pane');
                        if (!pane) return;
                        pane.querySelectorAll('.scat-prod-card').forEach(function(card) {
                            card.classList.add('is-open');
                            var hdr = card.querySelector('.scat-prod-card-header');
                            if (hdr) hdr.setAttribute('aria-expanded', 'true');
                        });
                    });
                });

                hub.querySelectorAll('.scat-hub-collapse-all').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var pane = btn.closest('.scat-hub-tab-pane');
                        if (!pane) return;
                        pane.querySelectorAll('.scat-prod-card').forEach(function(card) {
                            card.classList.remove('is-open');
                            var hdr = card.querySelector('.scat-prod-card-header');
                            if (hdr) hdr.setAttribute('aria-expanded', 'false');
                        });
                    });
                });

                hub.querySelectorAll('.scat-hub-search-mount').forEach(function(mount) {
                    if (mount.querySelector('.scat-hub-filter-input')) {
                        return;
                    }
                    var placeholder = mount.getAttribute('data-placeholder') || '';
                    var input = document.createElement('input');
                    input.type = 'search';
                    input.className = 'scat-hub-filter-input';
                    input.placeholder = placeholder;
                    input.setAttribute('aria-label', placeholder);
                    mount.appendChild(input);
                });

                hub.querySelectorAll('.scat-hub-filter-input').forEach(function(input) {
                    input.addEventListener('input', function() {
                        var q = input.value.trim().toLowerCase();
                        var pane = input.closest('.scat-hub-tab-pane');
                        if (!pane) return;
                        pane.querySelectorAll('.scat-prod-card').forEach(function(card) {
                            if (!q) {
                                card.classList.remove('is-hidden-by-filter');
                                return;
                            }
                            var text = (card.textContent || '').toLowerCase();
                            var matched = text.indexOf(q) !== -1;
                            card.classList.toggle('is-hidden-by-filter', !matched);
                            if (matched) {
                                card.classList.add('is-open');
                            }
                        });
                    });
                });
            });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    syncFromHash(true);
                });
            } else {
                syncFromHash(true);
            }

            window.addEventListener('hashchange', function() {
                syncFromHash(true);
            });
        })();
        </script>
        <?php
    }
}
