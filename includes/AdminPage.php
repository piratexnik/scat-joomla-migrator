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
        $has_plu = function_exists('plu_is_translator_ready') && plu_is_translator_ready();

        $token_updated = isset($_GET['token_updated']) && sanitize_text_field(wp_unslash((string) $_GET['token_updated'])) === '1';
        $check_update_url = Updater::get_manual_check_url(
            add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php'))
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('📦 Міграція Joomla -> WP', 'scat-joomla-migrator'); ?></h1>

            <?php if ($token_updated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Налаштування GitHub-токена збережено, кеш оновлень очищено.', 'scat-joomla-migrator'); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 820px; margin-top: 16px;">
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

            <div class="card" style="max-width: 820px; margin-top: 16px;">
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

            <div class="card" style="max-width: 820px; margin-top: 16px;">
                <h2><?php echo esc_html__('3. Етап 2: Міграція контенту', 'scat-joomla-migrator'); ?></h2>
                <p>
                    <?php echo esc_html__('Етап 2: Аналізатор бази даних Joomla 1.5 та імпорт контенту (буде підключено у v1.1+)', 'scat-joomla-migrator'); ?>
                </p>
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
