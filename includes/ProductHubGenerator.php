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

    public const AJAX_HUB_STATUS_ACTION = 'scat_migrator_hub_status';
    public const AJAX_GENERATE_HUB_ACTION = 'scat_migrator_generate_product_hub';

    private const LANGS = ['uk', 'ru', 'en'];

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

        wp_send_json_success([
            'hubs' => $this->get_all_hubs_status(),
        ]);
    }

    /**
     * AJAX: Generate or update 1 product hub page in 3 languages (UK + RU + EN).
     */
    public function ajax_generate_hub(): void
    {
        check_ajax_referer(ImageMigrator::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        @set_time_limit(180);

        $hub_key = isset($_POST['hub_key']) ? sanitize_key((string) wp_unslash($_POST['hub_key'])) : '';
        $post_status = isset($_POST['post_status']) ? sanitize_key((string) wp_unslash($_POST['post_status'])) : 'publish';
        if (!in_array($post_status, ['publish', 'draft'], true)) {
            $post_status = 'publish';
        }

        $defs = self::get_hub_definitions();
        if (!isset($defs[$hub_key])) {
            wp_send_json_error(['message' => __('Невідомий ключ зведеної сторінки.', 'scat-joomla-migrator')], 400);
        }

        if (!$this->joomla_tables_ready()) {
            wp_send_json_error([
                'message' => __('Таблиці jos_sections / jos_categories / jos_content не завантажені в БД. Завантажте SQL у Блоці 2.1.', 'scat-joomla-migrator'),
            ], 400);
        }

        $result = $this->generate_hub($hub_key, $post_status);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        wp_send_json_success([
            'hub_key' => $hub_key,
            'result'  => $result,
            'hub'     => $this->get_single_hub_status($hub_key),
        ]);
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

        $tables_ready = $this->joomla_tables_ready();
        $section = $tables_ready ? $this->resolve_joomla_section($hub_key) : null;
        $categories = ($tables_ready && $section !== null) ? $this->get_section_categories((int) $section->id, $hub_key) : [];
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
            $anchors[] = [
                'cat_id'        => $cat['id'],
                'tab_slug'      => $cat['tab_slug'],
                'title_uk'      => $cat['titles']['uk'],
                'title_ru'      => $cat['titles']['ru'],
                'title_en'      => $cat['titles']['en'],
                'article_count' => $cat['article_count'],
                'urls'          => [
                    'uk' => $base_urls['uk'] . '#' . $cat['tab_slug'],
                    'ru' => $base_urls['ru'] . '#' . $cat['tab_slug'],
                    'en' => $base_urls['en'] . '#' . $cat['tab_slug'],
                ],
            ];
        }

        return [
            'key'             => $hub_key,
            'slug'            => $def['slug'],
            'titles'          => $def['titles'],
            'tables_ready'    => $tables_ready,
            'section_id'      => $section !== null ? (int) $section->id : 0,
            'section_title'   => $section !== null ? (string) ($section->title ?: $section->name) : '',
            'intro_id'        => $intro_article !== null ? (int) $intro_article->id : 0,
            'intro_title'     => $intro_article !== null ? (string) $intro_article->title : '',
            'categories'      => $anchors,
            'pages'           => $existing_pages,
            'predicted_urls'  => $base_urls,
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
               AND p.post_type = 'page'
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

        foreach ($articles as $art) {
            if (!is_object($art)) {
                continue;
            }
            $aid = (int) $art->id;
            $jf = $this->migrator()->get_joomfish_fields_for_article($aid);
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
    private function get_section_categories(int $section_id, string $hub_key): array
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

        $jf_cats = $this->get_joomfish_map_for_table('categories');
        $used_slugs = [];
        $result = [];

        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $cid = (int) $row->id;
            $base_title = trim((string) ($row->title ?: $row->name));

            $title_ru = trim((string) ($jf_cats[$cid]['ru']['title'] ?? $jf_cats[$cid]['ru']['name'] ?? $base_title));
            $title_uk = trim((string) ($jf_cats[$cid]['uk']['title'] ?? $jf_cats[$cid]['uk']['name'] ?? $base_title));
            $title_en = trim((string) ($jf_cats[$cid]['en']['title'] ?? $jf_cats[$cid]['en']['name'] ?? $base_title));

            $articles_sql = $wpdb->prepare(
                "SELECT *
                 FROM `jos_content`
                 WHERE catid = %d
                   AND state = 1
                 ORDER BY ordering ASC, id ASC",
                $cid
            );
            $articles = is_string($articles_sql) ? $wpdb->get_results($articles_sql) : [];
            if (!is_array($articles) || $articles === []) {
                continue;
            }

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
                'articles'      => $articles,
                'article_count' => count($articles),
            ];
        }

        return $result;
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
     * Generate or update one hub page across UK, RU, and EN and link via Polylang.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function generate_hub(string $hub_key, string $post_status = 'publish')
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

        $categories = $this->get_section_categories((int) $section->id, $hub_key);
        if ($categories === []) {
            return new \WP_Error(
                'categories_empty',
                sprintf(__('У розділі #%d не знайдено опублікованих категорій зі статтями.', 'scat-joomla-migrator'), (int) $section->id)
            );
        }

        $intro_article = $this->resolve_intro_article($hub_key);
        $existing_pages = $this->find_existing_hub_pages($hub_key);

        $saved_posts = [];
        $translations_map = [];
        $total_items_count = 0;

        foreach ($categories as $cat) {
            $total_items_count += $cat['article_count'];
        }

        foreach (self::LANGS as $lang) {
            $built = $this->build_hub_page_html($def, $intro_article, $categories, $lang);
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

            $this->migrator()->set_same_slug_forced_lang($lang);

            if ($existing_id > 0) {
                $post_data['ID'] = $existing_id;
                $post_id = wp_update_post(wp_slash($post_data), true);
            } else {
                $post_id = wp_insert_post(wp_slash($post_data), true);
            }

            if (is_wp_error($post_id)) {
                $this->migrator()->set_same_slug_forced_lang(null);
                return $post_id;
            }

            $post_id = (int) $post_id;

            if (function_exists('pll_set_post_language')) {
                pll_set_post_language($post_id, $lang);
            }

            $this->migrator()->set_same_slug_forced_lang(null);

            update_post_meta($post_id, self::META_HUB_KEY, $hub_key);
            update_post_meta($post_id, self::META_HUB_LANG, $lang);

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

            $translations_map[$lang] = $post_id;
            $saved_posts[$lang] = [
                'post_id'  => $post_id,
                'title'    => $page_title,
                'url'      => (string) get_permalink($post_id),
                'edit_url' => (string) admin_url('post.php?post=' . $post_id . '&action=edit'),
                'updated'  => $existing_id > 0,
            ];
        }

        if (function_exists('pll_save_post_translations') && count($translations_map) > 1) {
            pll_save_post_translations($translations_map);
        }

        return [
            'hub_key'          => $hub_key,
            'tabs_count'       => count($categories),
            'products_count'   => $total_items_count,
            'pages'            => $saved_posts,
        ];
    }

    /**
     * Build complete HTML for a hub page in a specific language ('uk', 'ru', 'en').
     *
     * @param array<string, mixed>              $def
     * @param array<int, array<string, mixed>>  $categories
     * @return array{html: string, excerpt: string, metadesc: string, first_webp_rel: string|null}
     */
    private function build_hub_page_html(array $def, ?object $intro_article, array $categories, string $lang): array
    {
        $i18n = $this->get_ui_strings($lang);
        $first_webp_rel = null;
        $metadesc = '';

        // 1) Intro section
        $intro_html = '';
        if ($intro_article !== null) {
            $intro_id = (int) $intro_article->id;
            $jf = $this->migrator()->get_joomfish_fields_for_article($intro_id);
            $raw_intro = $this->pick_localized_field($jf, $lang, 'introtext', (string) ($intro_article->introtext ?? ''));
            $raw_full = $this->pick_localized_field($jf, $lang, 'fulltext', (string) ($intro_article->fulltext ?? ''));
            $metadesc = trim($this->pick_localized_field($jf, $lang, 'metadesc', (string) ($intro_article->metadesc ?? '')));

            $combined_intro = $this->migrator()->combine_intro_and_fulltext($raw_intro, $raw_full);
            $processed_intro = $this->migrator()->process_article_html(
                $combined_intro,
                false,
                $intro_id,
                $def['titles'][$lang] ?? ''
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

        // 3) Tabs navigation + Panes
        $nav_buttons = [];
        $tab_panes = [];

        foreach ($categories as $cat_index => $cat) {
            $tab_slug = $cat['tab_slug'];
            $tab_title = $cat['titles'][$lang] ?? $cat['titles']['uk'];
            $is_active = $cat_index === 0;

            $nav_buttons[] = sprintf(
                '<a href="#%1$s" class="scat-hub-tab-btn%2$s" data-tab="%1$s">%3$s</a>',
                esc_attr($tab_slug),
                $is_active ? ' is-active' : '',
                esc_html($tab_title)
            );

            $articles = $cat['articles'];
            $use_accordion = count($articles) > 2;

            $pane_inner = '';
            if ($use_accordion) {
                $pane_inner .= '<div class="scat-hub-pane-toolbar">'
                    . '<div class="scat-hub-filter-wrap">'
                    . '<input type="search" class="scat-hub-filter-input" placeholder="' . esc_attr($i18n['filter_placeholder']) . '" aria-label="' . esc_attr($i18n['filter_placeholder']) . '" />'
                    . '</div>'
                    . '<div class="scat-hub-expand-btns">'
                    . '<button type="button" class="scat-hub-expand-all">' . esc_html($i18n['expand_all']) . '</button>'
                    . '<button type="button" class="scat-hub-collapse-all">' . esc_html($i18n['collapse_all']) . '</button>'
                    . '</div>'
                    . '</div>';
            }

            $cards_html = [];
            foreach ($articles as $art_idx => $art) {
                $art_id = (int) $art->id;
                $jf_art = $this->migrator()->get_joomfish_fields_for_article($art_id);

                $art_title = trim($this->pick_localized_field($jf_art, $lang, 'title', (string) ($art->title ?? '')));
                $art_intro = $this->pick_localized_field($jf_art, $lang, 'introtext', (string) ($art->introtext ?? ''));
                $art_full = $this->pick_localized_field($jf_art, $lang, 'fulltext', (string) ($art->fulltext ?? ''));

                $combined = $this->migrator()->combine_intro_and_fulltext($art_intro, $art_full);
                $processed = $this->migrator()->process_article_html($combined, false, $art_id, $art_title);

                if ($first_webp_rel === null && $processed['first_webp_rel'] !== null) {
                    $first_webp_rel = $processed['first_webp_rel'];
                }

                $cleaned = $this->clean_product_media_and_tables($processed['html'], $art_id, true);
                $body_html = $cleaned['html'];
                $thumb_url = $cleaned['first_thumb_url'];

                if (!$use_accordion) {
                    $cards_html[] = '<div class="scat-prod-expanded-block">'
                        . '<h3 class="scat-prod-expanded-title">' . esc_html($art_title) . '</h3>'
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
                        . '<span class="scat-prod-card-title">' . esc_html($art_title) . '</span>'
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

        $hub_html = '<div class="scat-product-hub" data-hub-key="' . esc_attr($def['key']) . '">'
            . '<div class="scat-hub-intro">' . $intro_html . $cta_markup . '</div>'
            . '<nav class="scat-hub-tabs-nav" aria-label="' . esc_attr($def['titles'][$lang] ?? '') . '">' . implode('', $nav_buttons) . '</nav>'
            . '<div class="scat-hub-tabs-content">' . implode('', $tab_panes) . '</div>'
            . '</div>';

        $excerpt_plain = wp_strip_all_tags($intro_html);
        $excerpt_plain = trim((string) preg_replace('/\s+/u', ' ', $excerpt_plain));
        if (mb_strlen($excerpt_plain, 'UTF-8') > 240) {
            $excerpt_plain = mb_substr($excerpt_plain, 0, 237, 'UTF-8') . '…';
        }

        return [
            'html'           => $hub_html,
            'excerpt'        => $excerpt_plain,
            'metadesc'       => $metadesc !== '' ? $metadesc : $excerpt_plain,
            'first_webp_rel' => $first_webp_rel,
        ];
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
     * Resolve URL of the WordPress page containing [scat_app] shortcode + #catalog for the target language.
     */
    private function resolve_calculator_catalog_url(string $lang): string
    {
        if (function_exists('scat_get_urls')) {
            $urls = scat_get_urls();
            if (is_array($urls)) {
                foreach (['catalog', 'calculator', 'app', 'base'] as $k) {
                    if (!empty($urls[$k]) && is_string($urls[$k])) {
                        $base = strtok($urls[$k], '#');
                        return $base . '#catalog';
                    }
                }
            }
        }

        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('page', 'post')
               AND post_content LIKE %s
             ORDER BY ID ASC
             LIMIT 10",
            '%[scat_app%'
        );
        $ids = is_string($sql) ? $wpdb->get_col($sql) : [];
        if (is_array($ids) && $ids !== []) {
            $base_id = (int) $ids[0];
            if (function_exists('pll_get_post')) {
                $tr_id = (int) pll_get_post($base_id, $lang);
                if ($tr_id > 0) {
                    $base_id = $tr_id;
                }
            }
            $link = get_permalink($base_id);
            if (is_string($link) && $link !== '') {
                return untrailingslashit($link) . '/#catalog';
            }
        }

        return $this->build_predicted_hub_url('calculator', $lang) . '#catalog';
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

        return [
            'html'            => trim($working),
            'first_thumb_url' => $first_thumb_url,
        ];
    }

    /**
     * Parse every <table> using DOMDocument, detect and remove the legacy estimate/cart column
     * ("Додати до кошторису" / "Добавить в смету" / "Add to estimate" / <input> / addtovar),
     * adjust colspan/rowspan safely, and wrap in .scat-prod-table-wrap > table.scat-prod-spec-table.
     */
    private function clean_estimate_columns_in_tables(string $html): string
    {
        if (!class_exists('\DOMDocument')) {
            return $html;
        }

        $prev_errors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="scat-dom-root">'
            . $html
            . '</div></body></html>';

        $loaded = $dom->loadHTML($wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev_errors);

        if (!$loaded) {
            return $html;
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

        $root = $dom->getElementById('scat-dom-root');
        if (!$root instanceof \DOMElement) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= (string) $dom->saveHTML($child);
        }

        return $out !== '' ? $out : $html;
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
                while (isset($occupied[$r_idx][$col_cursor])) {
                    $col_cursor++;
                }

                $colspan = max(1, (int) $cell->getAttribute('colspan'));
                $rowspan = max(1, (int) $cell->getAttribute('rowspan'));

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
            /** @var \DOMElement $cell */
            $cell = $info['cell'];
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
                    $cell->removeAttribute('colspan');
                } else {
                    $cell->setAttribute('colspan', (string) $kept_cols);
                }
            }
        }

        // Clean any empty <tr> rows
        foreach ($rows as $tr) {
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
                                $a_open = (string) preg_replace(
                                    '/\bhref\s*=\s*(["\']).*?\1/i',
                                    'href="' . esc_url($target_href) . '"',
                                    $a_open,
                                    1
                                );
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
            $a_open = (string) preg_replace(
                '/\bclass\s*=\s*(["\']).*?\1/is',
                'class=' . $quote . esc_attr(implode(' ', $classes)) . $quote,
                $a_open,
                1
            );
        } else {
            $a_open = (string) preg_replace(
                '/^<a\b/i',
                '<a class="' . esc_attr(implode(' ', $required_classes)) . '"',
                $a_open,
                1
            );
        }

        if (stripos($a_open, 'data-scat-lightbox') === false) {
            $a_open = (string) preg_replace(
                '/>$/',
                ' data-scat-lightbox="' . esc_attr($lightbox_group) . '">',
                $a_open,
                1
            );
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
                'cta_hint'           => 'Нужен быстрый подбор сечений, нагрузок и расчёт спецификации?',
                'cta_button'         => '📐 Перейти к Инженерному калькулятору и каталогу',
                'filter_placeholder' => 'Быстрый поиск по названию или артикулу...',
                'expand_all'         => 'Развернуть все',
                'collapse_all'       => 'Свернуть все',
                'details_toggle'     => 'Подробнее (чертежи и размеры)',
            ];
        }

        if ($lang === 'en') {
            return [
                'cta_hint'           => 'Need quick sizing, load calculation, and bill of materials?',
                'cta_button'         => '📐 Go to Engineering Calculator & Catalog',
                'filter_placeholder' => 'Quick filter by name or SKU...',
                'expand_all'         => 'Expand all',
                'collapse_all'       => 'Collapse all',
                'details_toggle'     => 'Details (drawings & specs)',
            ];
        }

        return [
            'cta_hint'           => 'Потрібен швидкий підбір перерізів, навантажень та розрахунок специфікації?',
            'cta_button'         => '📐 Перейти до Інженерного калькулятора та каталогу',
            'filter_placeholder' => 'Швидкий пошук за назвою або артикулом...',
            'expand_all'         => 'Розгорнути всі',
            'collapse_all'       => 'Згорнути всі',
            'details_toggle'     => 'Детальніше (креслення та розміри)',
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
        if (!$post instanceof \WP_Post || strpos((string) $post->post_content, 'scat-product-hub') === false) {
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
                margin: 12px 0 36px;
            }
            .scat-product-hub p:empty,
            .scat-hub-tabs-nav br,
            .scat-hub-pane-toolbar br,
            .scat-prod-card-header br {
                display: none !important;
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
            .scat-hub-expand-all,
            .scat-hub-collapse-all {
                padding: 7px 14px;
                background: #ffffff;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                color: var(--scat-text);
                cursor: pointer;
                transition: border-color 0.15s ease, color 0.15s ease;
            }
            .scat-hub-expand-all:hover,
            .scat-hub-collapse-all:hover {
                border-color: var(--scat-primary);
                color: var(--scat-primary);
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
                    btn.addEventListener('click', function() {
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
                    btn.addEventListener('click', function() {
                        var pane = btn.closest('.scat-hub-tab-pane');
                        if (!pane) return;
                        pane.querySelectorAll('.scat-prod-card').forEach(function(card) {
                            card.classList.remove('is-open');
                            var hdr = card.querySelector('.scat-prod-card-header');
                            if (hdr) hdr.setAttribute('aria-expanded', 'false');
                        });
                    });
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
