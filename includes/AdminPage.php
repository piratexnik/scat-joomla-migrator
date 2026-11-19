<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class AdminPage
{
    public const MENU_SLUG = 'scat-joomla-migrator';
    public const TOKEN_OPTION = 'scat_migrator_github_token';
    public const SAVE_TOKEN_ACTION = 'scat_migrator_save_token';
    public const SAVE_TOKEN_NONCE = 'scat_migrator_save_token_nonce';

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::SAVE_TOKEN_ACTION, [$this, 'handle_save_token']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('📦 Міграція Joomla -> WP', 'scat-joomla-migrator'),
            __('📦 Міграція Joomla -> WP', 'scat-joomla-migrator'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-database-import',
            65
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        $allowed_pages = [self::MENU_SLUG, ArchiveBrowser::MENU_SLUG];

        $is_allowed_hook = in_array(
            $hook_suffix,
            [
                'toplevel_page_' . self::MENU_SLUG,
                'media_page_' . ArchiveBrowser::MENU_SLUG,
            ],
            true
        );

        if (!$is_allowed_hook && !in_array($page, $allowed_pages, true)) {
            return;
        }

        $version = Updater::get_local_version();
        $css_path = SCAT_MIGRATOR_DIR . 'assets/admin.css';
        $js_path = SCAT_MIGRATOR_DIR . 'assets/admin.js';
        $css_ver = $version . (file_exists($css_path) ? '.' . (string) filemtime($css_path) : '');
        $js_ver = $version . (file_exists($js_path) ? '.' . (string) filemtime($js_path) : '');

        wp_enqueue_style(
            'scat-joomla-migrator-admin',
            SCAT_MIGRATOR_URL . 'assets/admin.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'scat-joomla-migrator-admin',
            SCAT_MIGRATOR_URL . 'assets/admin.js',
            [],
            $js_ver,
            true
        );

        wp_localize_script(
            'scat-joomla-migrator-admin',
            'scatMigratorData',
            [
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'migratorNonce' => wp_create_nonce(ImageMigrator::NONCE_ACTION),
                'archiveNonce'  => wp_create_nonce(ArchiveBrowser::NONCE_ACTION),
                'archiveUrl'    => admin_url('upload.php?page=' . ArchiveBrowser::MENU_SLUG),
            ]
        );
    }

    public function handle_save_token(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'scat-joomla-migrator'), 403);
        }

        check_admin_referer(self::SAVE_TOKEN_ACTION, self::SAVE_TOKEN_NONCE);

        $raw_token = isset($_POST[self::TOKEN_OPTION])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::TOKEN_OPTION]))
            : '';

        if ($raw_token === '') {
            delete_option(self::TOKEN_OPTION);
        } else {
            update_option(self::TOKEN_OPTION, $raw_token, false);
        }

        Updater::flush_transients();

        $redirect_url = add_query_arg(
            [
                'page'          => self::MENU_SLUG,
                'token_updated' => '1',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'scat-joomla-migrator'), 403);
        }

        $local_version = Updater::get_local_version();
        $token_info = Updater::resolve_token();
        $own_saved_token = get_option(self::TOKEN_OPTION, '');
        $own_saved_token = is_string($own_saved_token) ? $own_saved_token : '';

        $php_version = PHP_VERSION;
        $wp_version = get_bloginfo('version');
        $has_gd = extension_loaded('gd');
        $has_webp = function_exists('imagewebp');
        $has_polylang = function_exists('pll_set_post_language');
        $polylang_langs = $this->get_polylang_languages();

        $db_env = DatabaseAnalyzer::get_environment_status();
        $wp_categories = DatabaseAnalyzer::get_wp_categories_list();

        $token_updated = isset($_GET['token_updated']) && sanitize_text_field(wp_unslash((string) $_GET['token_updated'])) === '1';
        $check_update_url = Updater::get_manual_check_url(
            add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php'))
        );
        $archive_browser_url = admin_url('upload.php?page=' . ArchiveBrowser::MENU_SLUG);
        ?>
        <div class="wrap scat-migrator-wrap">
            <div class="scat-page-header">
                <h1><?php echo esc_html__('📦 Міграція Joomla -> WP', 'scat-joomla-migrator'); ?></h1>
                <a href="<?php echo esc_url($archive_browser_url); ?>" class="button button-primary">
                    <?php echo esc_html__('📁 Відкрити провідник архіву в Медіатеці', 'scat-joomla-migrator'); ?>
                </a>
            </div>

            <?php if ($token_updated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Налаштування GitHub-токена збережено, кеш оновлень очищено.', 'scat-joomla-migrator'); ?></p>
                </div>
            <?php endif; ?>

            <div class="card scat-card">
                <h2><?php echo esc_html__('1. Діагностика середовища (System Check)', 'scat-joomla-migrator'); ?></h2>
                <table class="widefat striped" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td><strong><?php echo esc_html__('Версія PHP', 'scat-joomla-migrator'); ?></strong></td>
                            <td>
                                <code><?php echo esc_html($php_version); ?></code>
                                <?php echo version_compare($php_version, '8.1', '>=') ? '✅' : '⚠️'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php echo esc_html__('Версія WordPress', 'scat-joomla-migrator'); ?></strong></td>
                            <td>
                                <code><?php echo esc_html((string) $wp_version); ?></code>
                                <?php echo version_compare((string) $wp_version, '6.5', '>=') ? '✅' : '⚠️'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php echo esc_html__('Бібліотека GD / WebP (imagewebp)', 'scat-joomla-migrator'); ?></strong></td>
                            <td>
                                <?php if ($has_gd && $has_webp) : ?>
                                    <span>✅ <?php echo esc_html__('GD та imagewebp() доступні', 'scat-joomla-migrator'); ?></span>
                                <?php elseif ($has_gd) : ?>
                                    <span>⚠️ <?php echo esc_html__('GD є, але imagewebp() недоступна', 'scat-joomla-migrator'); ?></span>
                                <?php else : ?>
                                    <span>❌ <?php echo esc_html__('Розширення GD не встановлено', 'scat-joomla-migrator'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php echo esc_html__('Плагін Polylang', 'scat-joomla-migrator'); ?></strong></td>
                            <td>
                                <?php if ($has_polylang) : ?>
                                    <span>✅ <?php echo esc_html__('Активний', 'scat-joomla-migrator'); ?></span>
                                    <?php if ($polylang_langs !== []) : ?>
                                        <br><small><?php echo esc_html__('Активні мови:', 'scat-joomla-migrator'); ?>
                                            <code><?php echo esc_html(implode(', ', $polylang_langs)); ?></code>
                                        </small>
                                    <?php else : ?>
                                        <br><small><?php echo esc_html__('Мови ще не налаштовані.', 'scat-joomla-migrator'); ?></small>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span>❌ <?php echo esc_html__('Не активний (pll_set_post_language не знайдено)', 'scat-joomla-migrator'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php echo esc_html__('AI-перекладач Polyland-Up', 'scat-joomla-migrator'); ?></strong></td>
                            <td>
                                <?php if ($has_plu) : ?>
                                    <span>✅ <?php echo esc_html__('Готовий до роботи', 'scat-joomla-migrator'); ?></span>
                                <?php else : ?>
                                    <span>⚠️ <?php echo esc_html__('Не активний або не налаштований', 'scat-joomla-migrator'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card scat-card">
                <h2><?php echo esc_html__('2. Налаштування оновлень з GitHub', 'scat-joomla-migrator'); ?></h2>
                <p>
                    <?php echo esc_html__('Поточна версія (з диска):', 'scat-joomla-migrator'); ?>
                    <strong><code><?php echo esc_html($local_version); ?></code></strong>
                </p>
                <p>
                    <?php echo esc_html__('Джерело токена / режим:', 'scat-joomla-migrator'); ?>
                    <strong><?php echo esc_html($this->format_token_source_label($token_info['source'])); ?></strong>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_TOKEN_ACTION); ?>">
                    <?php wp_nonce_field(self::SAVE_TOKEN_ACTION, self::SAVE_TOKEN_NONCE); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr(self::TOKEN_OPTION); ?>">
                                    <?php echo esc_html__('Персональний GitHub Token', 'scat-joomla-migrator'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="password"
                                    id="<?php echo esc_attr(self::TOKEN_OPTION); ?>"
                                    name="<?php echo esc_attr(self::TOKEN_OPTION); ?>"
                                    value="<?php echo esc_attr($own_saved_token); ?>"
                                    class="regular-text"
                                    autocomplete="off"
                                    placeholder="ghp_..."
                                >
                                <p class="description">
                                    <?php echo esc_html__('Залиште порожнім для Public-репозиторію або для використання спільного токена (SCAT_GITHUB_TOKEN / scat_github_token).', 'scat-joomla-migrator'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit" style="display: flex; gap: 10px; align-items: center;">
                        <button type="submit" class="button button-primary">
                            <?php echo esc_html__('Зберегти токен', 'scat-joomla-migrator'); ?>
                        </button>
                        <a href="<?php echo esc_url($check_update_url); ?>" class="button button-secondary">
                            <?php echo esc_html__('🔄 Проверить обновления', 'scat-joomla-migrator'); ?>
                        </a>
                    </p>
                </form>
            </div>

            <div class="card scat-card" id="scat-webp-converter-card">
                <h2><?php echo esc_html__('🖼️ Крок 1: Архів файлів Joomla та конвертація зображень у WebP', 'scat-joomla-migrator'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Пакетна конвертація вихідних зображень з /wp-content/uploads/joomla-legacy/images/ у /wp-content/uploads/scat-content-webp/ зі збереженням структури підпапок без засмічення Медіатеки WP.', 'scat-joomla-migrator'); ?>
                </p>

                <div class="scat-stats-grid">
                    <div class="scat-stat-box">
                        <span class="scat-stat-label"><?php echo esc_html__('Вихідні зображення (joomla-legacy/images)', 'scat-joomla-migrator'); ?></span>
                        <strong class="scat-stat-value" id="scat-stat-source-count">—</strong>
                        <small class="scat-stat-sub" id="scat-stat-source-size"><?php echo esc_html__('Натисніть «Сканувати архів»', 'scat-joomla-migrator'); ?></small>
                    </div>

                    <div class="scat-stat-box scat-stat-success">
                        <span class="scat-stat-label"><?php echo esc_html__('Готові WebP (scat-content-webp)', 'scat-joomla-migrator'); ?></span>
                        <strong class="scat-stat-value" id="scat-stat-webp-count">—</strong>
                        <small class="scat-stat-sub" id="scat-stat-webp-size">—</small>
                    </div>

                    <div class="scat-stat-box scat-stat-warning">
                        <span class="scat-stat-label"><?php echo esc_html__('Залишилось конвертувати', 'scat-joomla-migrator'); ?></span>
                        <strong class="scat-stat-value" id="scat-stat-pending-count">—</strong>
                        <small class="scat-stat-sub" id="scat-stat-skipped-info">—</small>
                    </div>

                    <div class="scat-stat-box scat-stat-info">
                        <span class="scat-stat-label"><?php echo esc_html__('Документи (dmdocuments)', 'scat-joomla-migrator'); ?></span>
                        <strong class="scat-stat-value" id="scat-stat-docs-count">—</strong>
                        <small class="scat-stat-sub" id="scat-stat-docs-size">—</small>
                    </div>
                </div>

                <div class="scat-converter-settings">
                    <div class="scat-field">
                        <label for="scat-max-width"><?php echo esc_html__('Макс. ширина/висота (px)', 'scat-joomla-migrator'); ?></label>
                        <input type="number" id="scat-max-width" value="1920" min="100" max="8000" step="10">
                    </div>
                    <div class="scat-field">
                        <label for="scat-webp-quality"><?php echo esc_html__('Якість WebP (10-100)', 'scat-joomla-migrator'); ?></label>
                        <input type="number" id="scat-webp-quality" value="82" min="10" max="100" step="1">
                    </div>
                    <div class="scat-field">
                        <label for="scat-batch-size"><?php echo esc_html__('Розмір пакету (файлів за крок)', 'scat-joomla-migrator'); ?></label>
                        <input type="number" id="scat-batch-size" value="25" min="1" max="200" step="1">
                    </div>
                </div>

                <div class="scat-converter-actions">
                    <button type="button" id="scat-btn-scan" class="button button-secondary">
                        <?php echo esc_html__('🔍 Сканувати архів файлів', 'scat-joomla-migrator'); ?>
                    </button>
                    <button type="button" id="scat-btn-convert" class="button button-primary" disabled>
                        <?php echo esc_html__('🚀 Запустити конвертацію в WebP', 'scat-joomla-migrator'); ?>
                    </button>
                    <button type="button" id="scat-btn-stop" class="button" disabled>
                        <?php echo esc_html__('⏹ Зупинити', 'scat-joomla-migrator'); ?>
                    </button>
                    <a href="<?php echo esc_url($archive_browser_url); ?>" class="button">
                        <?php echo esc_html__('📁 Відкрити провідник архіву в Медіатеці', 'scat-joomla-migrator'); ?>
                    </a>
                </div>

                <div id="scat-progress-wrap" class="scat-progress-wrap" style="display: none;">
                    <div class="scat-progress-header">
                        <span id="scat-progress-label"><?php echo esc_html__('Підготовка...', 'scat-joomla-migrator'); ?></span>
                        <strong id="scat-progress-percent">0%</strong>
                    </div>
                    <div class="scat-progress-bar-bg">
                        <div id="scat-progress-bar-fill" class="scat-progress-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <details id="scat-log-details" class="scat-log-details" style="display: none;">
                    <summary>
                        <?php echo esc_html__('⚠️ Лог помилок та пропущених файлів', 'scat-joomla-migrator'); ?>
                        (<span id="scat-log-count">0</span>)
                    </summary>
                    <pre id="scat-log-output" class="scat-log-output"></pre>
                </details>
            </div>

            <div class="card scat-card" id="scat-content-migrator-card">
                <h2><?php echo esc_html__('4. Етап 2: Міграція контенту (Joomla 1.5 -> WordPress)', 'scat-joomla-migrator'); ?></h2>

                <!-- Блок 2.1: База даних Joomla -->
                <div class="scat-subsection">
                    <h3><?php echo esc_html__('2.1. База даних Joomla (ya615591_scat2.sql)', 'scat-joomla-migrator'); ?></h3>
                    <p>
                        <?php echo esc_html__('SQL-дамп у /wp-content/uploads/joomla-legacy/:', 'scat-joomla-migrator'); ?>
                        <?php if ($db_env['sql_found']) : ?>
                            <strong><code><?php echo esc_html($db_env['sql_filename']); ?></code></strong>
                            (<?php echo esc_html($db_env['sql_size_human']); ?>) ✅
                        <?php else : ?>
                            <span style="color: #b32d2e; font-weight: 600;">
                                ❌ <?php echo esc_html__('SQL-файл не знайдено у папці joomla-legacy/', 'scat-joomla-migrator'); ?>
                            </span>
                        <?php endif; ?>
                    </p>

                    <div class="scat-tables-badges" id="scat-db-tables-status">
                        <?php foreach ($db_env['tables'] as $tbl_name => $tbl_info) : ?>
                            <span class="scat-table-badge <?php echo $tbl_info['exists'] ? 'is-loaded' : 'is-missing'; ?>" data-table="<?php echo esc_attr($tbl_name); ?>">
                                <?php echo $tbl_info['exists'] ? '✅' : '⚪'; ?>
                                <code><?php echo esc_html($tbl_name); ?></code>:
                                <strong><?php echo $tbl_info['exists'] ? esc_html((string) $tbl_info['rows']) . ' рядків' : esc_html__('не завантажена', 'scat-joomla-migrator'); ?></strong>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="scat-converter-actions" style="margin-top: 12px;">
                        <button type="button" id="scat-btn-load-sql" class="button button-primary" <?php disabled(!$db_env['sql_found']); ?>>
                            <?php echo esc_html__('📥 Завантажити таблиці контенту з SQL-файлу в БД', 'scat-joomla-migrator'); ?>
                        </button>
                        <button type="button" id="scat-btn-analyze-db" class="button button-secondary">
                            <?php echo esc_html__('🔍 Проаналізувати категорії та статті', 'scat-joomla-migrator'); ?>
                        </button>
                        <button type="button" id="scat-btn-drop-tables" class="button scat-btn-danger">
                            <?php echo esc_html__('🗑️ Видалити тимчасові таблиці jos_*', 'scat-joomla-migrator'); ?>
                        </button>
                    </div>

                    <div class="scat-fix-existing-panel">
                        <div class="scat-fix-existing-controls">
                            <div class="scat-target-post-picker" id="scat-target-post-picker">
                                <input type="hidden" id="scat-target-post-id" value="0">
                                <input
                                    type="text"
                                    id="scat-target-post-input"
                                    class="regular-text scat-target-post-input"
                                    autocomplete="off"
                                    placeholder="<?php echo esc_attr__('Усі записи WP (або введіть ID / назву / slug сторінки, напр. Сертифікати або 1305)', 'scat-joomla-migrator'); ?>"
                                >
                                <button
                                    type="button"
                                    id="scat-target-post-clear"
                                    class="scat-target-post-clear"
                                    title="<?php echo esc_attr__('Скинути вибір (усі записи WP)', 'scat-joomla-migrator'); ?>"
                                    style="display: none;"
                                >✖</button>
                                <div id="scat-target-post-dropdown" class="scat-target-post-dropdown" style="display: none;"></div>
                            </div>

                            <label class="scat-target-post-pll-label">
                                <input type="checkbox" id="scat-target-post-include-pll" value="1" checked>
                                <?php echo esc_html__('Разом із перекладами Polylang (UK/RU/EN)', 'scat-joomla-migrator'); ?>
                            </label>

                            <button type="button" id="scat-btn-fix-existing-posts" class="button button-secondary">
                                <?php echo esc_html__('🛠️ Оновити посилання DocMan та компактні фото-ряди у всіх записах WP (UK / RU / EN)', 'scat-joomla-migrator'); ?>
                            </button>
                        </div>
                    </div>

                    <div id="scat-db-action-status" class="scat-inline-status" style="display: none;"></div>
                    <div id="scat-fix-existing-log-wrap" class="scat-articles-log-wrap" style="display: none; margin-top: 12px;">
                        <h4>
                            <?php echo esc_html__('🛠️ Лог оновлення існуючих записів WP', 'scat-joomla-migrator'); ?>
                            (<span id="scat-fix-existing-log-count">0</span>)
                        </h4>
                        <div id="scat-fix-existing-log-list" class="scat-articles-log-list"></div>
                    </div>
                </div>

                <!-- Блок 2.2: Таблиця розділів та категорій Joomla 1.5 -->
                <div class="scat-subsection" id="scat-joomla-cats-section">
                    <div class="scat-section-header-row">
                        <h3><?php echo esc_html__('2.2. Таблиця розділів та категорій Joomla 1.5', 'scat-joomla-migrator'); ?></h3>
                        <div class="scat-select-helpers">
                            <button type="button" id="scat-btn-select-all-cats" class="button button-small">
                                <?php echo esc_html__('Вибрати всі', 'scat-joomla-migrator'); ?>
                            </button>
                            <button type="button" id="scat-btn-unselect-all-cats" class="button button-small">
                                <?php echo esc_html__('Зняти всі', 'scat-joomla-migrator'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="scat-table-scroll">
                        <table class="widefat striped scat-cats-table">
                            <thead>
                                <tr>
                                    <th style="width: 32px;"><input type="checkbox" id="scat-cats-master-cb"></th>
                                    <th style="width: 55px;"><?php echo esc_html__('ID', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('Розділ (Section)', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('Категорія (Category)', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('Опубл. / Архів / Чернетки', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('Період дат (від — до)', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('Переклади Joom!Fish', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('Вже перенесено в WP', 'scat-joomla-migrator'); ?></th>
                                    <th style="width: 110px;"><?php echo esc_html__('Перегляд', 'scat-joomla-migrator'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="scat-joomla-cats-tbody">
                                <tr>
                                    <td colspan="9" style="text-align: center; color: #646970;">
                                        <?php echo esc_html__('Натисніть «🔍 Проаналізувати категорії та статті», щоб відобразити структуру контенту Joomla.', 'scat-joomla-migrator'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Блок 2.3: Налаштування імпорту вибраних категорій -->
                <div class="scat-subsection">
                    <h3><?php echo esc_html__('2.3. Налаштування імпорту вибраних категорій / статей', 'scat-joomla-migrator'); ?></h3>

                    <div id="scat-selected-articles-banner" class="scat-selected-articles-banner" style="display: none;">
                        <span>
                            🎯 <?php echo esc_html__('Вибрано конкретних статей для імпорту:', 'scat-joomla-migrator'); ?>
                            <strong id="scat-selected-articles-count">0</strong> <?php echo esc_html__('шт.', 'scat-joomla-migrator'); ?>
                        </span>
                        <button type="button" id="scat-btn-clear-selected-articles" class="button button-small">
                            <?php echo esc_html__('Скинути', 'scat-joomla-migrator'); ?>
                        </button>
                    </div>

                    <div class="scat-import-form-grid">
                        <div class="scat-form-row" style="grid-column: 1 / -1;">
                            <label for="scat-target-wp-cat"><strong><?php echo esc_html__('Цільова рубрика WordPress (основна UK)', 'scat-joomla-migrator'); ?></strong></label>
                            <div class="scat-inline-controls">
                                <select id="scat-target-wp-cat">
                                    <option value="0"><?php echo esc_html__('— Без прив\'язки до рубрики (за замовчуванням) —', 'scat-joomla-migrator'); ?></option>
                                    <?php foreach ($wp_categories as $wp_cat) : ?>
                                        <option value="<?php echo esc_attr((string) $wp_cat['term_id']); ?>">
                                            <?php
                                            $label = $wp_cat['name'] . ' (' . $wp_cat['slug'] . ')';
                                            if ($wp_cat['lang'] !== '') {
                                                $label .= ' [' . strtoupper($wp_cat['lang']) . ']';
                                            }
                                            echo esc_html($label);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="scat-multilang-cat-creator">
                                <div class="scat-multilang-cat-inputs">
                                    <div class="scat-field">
                                        <label for="scat-new-cat-uk">🇺🇦 Назва UK</label>
                                        <input type="text" id="scat-new-cat-uk" value="Архів новин" placeholder="Архів новин">
                                    </div>
                                    <div class="scat-field">
                                        <label for="scat-new-cat-ru">🇷🇺 Назва RU</label>
                                        <input type="text" id="scat-new-cat-ru" value="Архив новостей" placeholder="Архив новостей">
                                    </div>
                                    <div class="scat-field">
                                        <label for="scat-new-cat-en">🇬🇧 Назва EN</label>
                                        <input type="text" id="scat-new-cat-en" value="News Archive" placeholder="News Archive">
                                    </div>
                                    <button type="button" id="scat-btn-create-archive-cat" class="button button-secondary">
                                        <?php echo esc_html__('➕ Створити зв\'язані рубрики (UK + RU + EN)', 'scat-joomla-migrator'); ?>
                                    </button>
                                </div>

                                <div class="scat-cat-chips">
                                    <span class="scat-chips-label"><?php echo esc_html__('Швидкі пресети (3 мови):', 'scat-joomla-migrator'); ?></span>
                                    <button
                                        type="button"
                                        class="scat-chip-btn"
                                        data-name-uk="Архів новин" data-slug-uk="archive-news-uk"
                                        data-name-ru="Архив новостей" data-slug-ru="archive-news-ru"
                                        data-name-en="News Archive" data-slug-en="news-archive-en"
                                    >Архів новин</button>
                                    <button
                                        type="button"
                                        class="scat-chip-btn"
                                        data-name-uk="Фотоархів" data-slug-uk="photo-archive-uk"
                                        data-name-ru="Фотоархив" data-slug-ru="photo-archive-ru"
                                        data-name-en="Photo Archive" data-slug-en="photo-archive-en"
                                    >Фотоархів</button>
                                    <button
                                        type="button"
                                        class="scat-chip-btn"
                                        data-name-uk="Статті в ЗМІ" data-slug-uk="media-articles-uk"
                                        data-name-ru="Статьи в СМИ" data-slug-ru="media-articles-ru"
                                        data-name-en="Articles in Media" data-slug-en="media-articles-en"
                                    >Статті в ЗМІ</button>
                                    <button
                                        type="button"
                                        class="scat-chip-btn"
                                        data-name-uk="Корисна інформація" data-slug-uk="useful-info-uk"
                                        data-name-ru="Полезная информация" data-slug-ru="useful-info-ru"
                                        data-name-en="Useful Information" data-slug-en="useful-info-en"
                                    >Корисна інформація</button>
                                </div>
                            </div>
                        </div>

                        <div class="scat-form-row" style="grid-column: 1 / -1;">
                            <label for="scat-jf-mode"><strong><?php echo esc_html__('Режим мов та перекладів Joom!Fish', 'scat-joomla-migrator'); ?></strong></label>
                            <select id="scat-jf-mode" style="max-width: 100%;">
                                <option value="uk_full_plus_existing" selected>
                                    <?php echo esc_html__('🌟 Повний архів: створити UK-запис для кожної статті + зберегти і прив\'язати всі існуючі переклади (RU / EN) з бази у зв\'язані рубрики Polylang (Рекомендовано)', 'scat-joomla-migrator'); ?>
                                </option>
                                <option value="uk_priority">
                                    <?php echo esc_html__('🇺🇦 1 мова (UK): брати укр. переклад з Joom!Fish, якщо є, інакше оригінал (без створення RU/EN версій)', 'scat-joomla-migrator'); ?>
                                </option>
                                <option value="multilang_pll">
                                    <?php echo esc_html__('🌐 Мультимовний Polylang (UK + RU + EN): створити та зв\'язати всі наявні переклади з бази (за фактичною мовою оригіналу)', 'scat-joomla-migrator'); ?>
                                </option>
                                <option value="raw_original">
                                    <?php echo esc_html__('📄 Тільки оригінал з jos_content (ігнорувати Joom!Fish)', 'scat-joomla-migrator'); ?>
                                </option>
                            </select>
                        </div>

                        <div class="scat-form-row">
                            <label for="scat-post-type"><strong><?php echo esc_html__('Тип записів WordPress', 'scat-joomla-migrator'); ?></strong></label>
                            <select id="scat-post-type">
                                <option value="post" selected><?php echo esc_html__('Записи (post — рубрики / новини / статті)', 'scat-joomla-migrator'); ?></option>
                                <option value="page"><?php echo esc_html__('Сторінки (page — статичні сторінки)', 'scat-joomla-migrator'); ?></option>
                            </select>
                        </div>

                        <div class="scat-form-row">
                            <label for="scat-import-post-status"><strong><?php echo esc_html__('Статус записів у WP', 'scat-joomla-migrator'); ?></strong></label>
                            <select id="scat-import-post-status">
                                <option value="publish" selected><?php echo esc_html__('🟢 Опубліковано (publish — видно на сайті та в Google)', 'scat-joomla-migrator'); ?></option>
                                <option value="draft"><?php echo esc_html__('📝 Чернетка (draft — приховано від роботів та відвідувачів)', 'scat-joomla-migrator'); ?></option>
                            </select>
                        </div>

                        <div class="scat-form-row">
                            <label for="scat-target-lang"><strong><?php echo esc_html__('Мова записів (Polylang)', 'scat-joomla-migrator'); ?></strong></label>
                            <input type="text" id="scat-target-lang" value="uk" class="small-text">
                        </div>

                        <div class="scat-form-row">
                            <label for="scat-slug-mode"><strong><?php echo esc_html__('Формат URL (Slug)', 'scat-joomla-migrator'); ?></strong></label>
                            <select id="scat-slug-mode">
                                <option value="date" selected><?php echo esc_html__('По даті (article-YYYY-MM-DD) — рекомендовано для архіву', 'scat-joomla-migrator'); ?></option>
                                <option value="alias"><?php echo esc_html__('Оригінальний alias з Joomla', 'scat-joomla-migrator'); ?></option>
                            </select>
                        </div>

                        <div class="scat-form-row">
                            <label for="scat-excerpt-length"><strong><?php echo esc_html__('Довжина уривка (post_excerpt, символів)', 'scat-joomla-migrator'); ?></strong></label>
                            <input type="number" id="scat-excerpt-length" value="270" min="50" max="1000" step="10" class="small-text">
                        </div>

                        <div class="scat-form-row">
                            <label for="scat-articles-batch-size"><strong><?php echo esc_html__('Статей за 1 пакет', 'scat-joomla-migrator'); ?></strong></label>
                            <input type="number" id="scat-articles-batch-size" value="20" min="1" max="100" step="5" class="small-text">
                        </div>
                    </div>

                    <div class="scat-checkbox-group">
                        <label>
                            <input type="checkbox" id="scat-set-featured-image" checked>
                            <?php echo esc_html__('Встановлювати перше зображення як Обкладинку запису (Featured Image з WebP)', 'scat-joomla-migrator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="scat-strip-first-img">
                            <?php echo esc_html__('Прибирати перше зображення з тексту статті (якщо воно стало обкладинкою)', 'scat-joomla-migrator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="scat-skip-existing" checked>
                            <?php echo esc_html__('Пропускати вже імпортовані статті (за _joomla_content_id)', 'scat-joomla-migrator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="scat-only-published" checked>
                            <?php echo esc_html__('Переносити лише опубліковані та архівні статті (state = 1 та state = -1)', 'scat-joomla-migrator'); ?>
                        </label>
                    </div>

                    <div class="scat-converter-actions" style="margin-top: 16px;">
                        <button type="button" id="scat-btn-import-articles" class="button button-primary">
                            <?php echo esc_html__('🚀 Імпортувати вибрані статті в WordPress', 'scat-joomla-migrator'); ?>
                        </button>
                        <button type="button" id="scat-btn-stop-articles" class="button" disabled>
                            <?php echo esc_html__('⏹ Зупинити', 'scat-joomla-migrator'); ?>
                        </button>
                    </div>

                    <div id="scat-articles-progress-wrap" class="scat-progress-wrap" style="display: none;">
                        <div class="scat-progress-header">
                            <span id="scat-articles-progress-label"><?php echo esc_html__('Підготовка...', 'scat-joomla-migrator'); ?></span>
                            <strong id="scat-articles-progress-percent">0%</strong>
                        </div>
                        <div class="scat-progress-bar-bg">
                            <div id="scat-articles-progress-fill" class="scat-progress-bar-fill" style="width: 0%;"></div>
                        </div>
                    </div>

                    <div id="scat-articles-log-wrap" class="scat-articles-log-wrap" style="display: none;">
                        <h4>
                            <?php echo esc_html__('📋 Лог імпорту статей', 'scat-joomla-migrator'); ?>
                            (<span id="scat-articles-log-count">0</span>)
                        </h4>
                        <div id="scat-articles-log-list" class="scat-articles-log-list"></div>
                    </div>
                </div>
            </div>

            <!-- Модальне вікно перегляду та вибору статей категорії Joomla -->
            <div id="scat-category-preview-modal" class="scat-modal-backdrop" style="display: none;" role="dialog" aria-modal="true">
                <div class="scat-modal-dialog">
                    <div class="scat-modal-header">
                        <h3 id="scat-modal-title"><?php echo esc_html__('Статті категорії', 'scat-joomla-migrator'); ?></h3>
                        <button type="button" id="scat-modal-close" class="scat-modal-close-btn" aria-label="Close">✕</button>
                    </div>

                    <div class="scat-modal-toolbar">
                        <div class="scat-select-helpers">
                            <button type="button" id="scat-modal-select-all" class="button button-small">
                                <?php echo esc_html__('Вибрати всі', 'scat-joomla-migrator'); ?>
                            </button>
                            <button type="button" id="scat-modal-unselect-all" class="button button-small">
                                <?php echo esc_html__('Зняти всі', 'scat-joomla-migrator'); ?>
                            </button>
                        </div>
                        <span id="scat-modal-summary" class="scat-counter-badge">0 статей</span>
                    </div>

                    <div class="scat-modal-body">
                        <table class="widefat striped scat-modal-table">
                            <thead>
                                <tr>
                                    <th style="width: 32px;"><input type="checkbox" id="scat-modal-master-cb"></th>
                                    <th style="width: 55px;"><?php echo esc_html__('ID', 'scat-joomla-migrator'); ?></th>
                                    <th style="width: 95px;"><?php echo esc_html__('Дата', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('Заголовок в jos_content', 'scat-joomla-migrator'); ?></th>
                                    <th><?php echo esc_html__('🇺🇦 Заголовок у Joom!Fish (UK)', 'scat-joomla-migrator'); ?></th>
                                    <th style="width: 110px;"><?php echo esc_html__('Мови (UK/EN)', 'scat-joomla-migrator'); ?></th>
                                    <th style="width: 130px;"><?php echo esc_html__('Статус у WP', 'scat-joomla-migrator'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="scat-modal-articles-tbody">
                                <tr>
                                    <td colspan="7" style="text-align: center;"><?php echo esc_html__('Завантаження...', 'scat-joomla-migrator'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="scat-modal-footer">
                        <button type="button" id="scat-modal-cancel" class="button">
                            <?php echo esc_html__('Закрити', 'scat-joomla-migrator'); ?>
                        </button>
                        <button type="button" id="scat-modal-apply" class="button button-primary">
                            <?php echo esc_html__('✅ Використати вибрані статті (0 шт.) для імпорту', 'scat-joomla-migrator'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 5. Етап 3: Генератор 4 зведених сторінок Продукції (для меню сайту) -->
            <div class="card scat-card" id="scat-product-hubs-card">
                <h2><?php echo esc_html__('5. Етап 3: Генератор 4 зведених сторінок Продукції (для меню сайту)', 'scat-joomla-migrator'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Замість імпорту 200+ дрібних статей Joomla модуль автоматично збирає 4 сучасні зведені сторінки (post_type = page) на 3 мовах Polylang (UK + RU + EN) з вкладками (#якорями для меню), картками-акордеонами виробів, WebP-кресленнями у лайтбоксі, очищеними інженерними таблицями (без колонки «Додати до кошторису»), кнопкою переходу до калькулятора (/scat-program/#catalog) та гнучкими позначками «Тимчасово не виробляється» для цілих сторінок або окремих серій.', 'scat-joomla-migrator'); ?>
                </p>

                <div class="scat-hub-admin-toolbar">
                    <div class="scat-field">
                        <label for="scat-hub-post-status"><?php echo esc_html__('Статус сторінок у WP:', 'scat-joomla-migrator'); ?></label>
                        <select id="scat-hub-post-status">
                            <option value="publish"><?php echo esc_html__('🟢 Опубліковано (publish)', 'scat-joomla-migrator'); ?></option>
                            <option value="draft"><?php echo esc_html__('📝 Чернетка (draft)', 'scat-joomla-migrator'); ?></option>
                        </select>
                    </div>
                    <div class="scat-hub-admin-actions">
                        <button type="button" id="scat-btn-generate-all-hubs" class="button button-primary">
                            <?php echo esc_html__('🚀 Згенерувати всі 4 сторінки Продукції', 'scat-joomla-migrator'); ?>
                        </button>
                        <button type="button" id="scat-btn-refresh-hubs" class="button button-secondary">
                            <?php echo esc_html__('🔄 Оновити статус', 'scat-joomla-migrator'); ?>
                        </button>
                    </div>
                </div>

                <div id="scat-hub-action-status" class="scat-inline-status" style="display: none;"></div>

                <div id="scat-product-hubs-grid" class="scat-product-hubs-grid">
                    <div class="scat-hub-loading-note">
                        <?php echo esc_html__('⏳ Завантаження даних про розділи та категорії Joomla...', 'scat-joomla-migrator'); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<int, string>
     */
    private function get_polylang_languages(): array
    {
        if (!function_exists('pll_languages_list')) {
            return [];
        }

        $langs = pll_languages_list(['fields' => 'slug']);
        if (!is_array($langs)) {
            return [];
        }

        return array_values(array_map('strval', $langs));
    }

    private function format_token_source_label(string $source): string
    {
        switch ($source) {
            case 'SCAT_MIGRATOR_GITHUB_TOKEN':
                return __('Використовується власний токен із константи SCAT_MIGRATOR_GITHUB_TOKEN (Private)', 'scat-joomla-migrator');
            case 'scat_migrator_github_token':
                return __('Використовується власний токен із налаштувань плагіна (Private)', 'scat-joomla-migrator');
            case 'SCAT_GITHUB_TOKEN':
                return __('Підхоплено спільний токен із константи SCAT_GITHUB_TOKEN (Private)', 'scat-joomla-migrator');
            case 'scat_github_token':
                return __('Підхоплено спільний токен з опції БД scat_github_token (Private)', 'scat-joomla-migrator');
            default:
                return __('Режим Public без токена', 'scat-joomla-migrator');
        }
    }
}
