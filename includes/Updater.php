<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class Updater
{
    public const GITHUB_OWNER = 'piratexnik';
    public const GITHUB_REPO = 'scat-joomla-migrator';
    public const GITHUB_BRANCH = 'main';
    public const PLUGIN_SLUG = 'scat-joomla-migrator';

    public const TRANSIENT_VERSION = 'scat_migrator_remote_version';
    public const TRANSIENT_ERROR = 'scat_migrator_update_error';
    public const TRANSIENT_NOTICE_PREFIX = 'scat_migrator_check_notice_';
    public const NONCE_ACTION = 'scat_migrator_check_update';

    public function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('site_transient_update_plugins', [$this, 'inject_cached_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);

        add_filter('http_request_args', [$this, 'filter_http_request_args'], 9999, 2);
        add_action('http_api_curl', [$this, 'filter_http_api_curl'], 9999, 3);

        add_filter('upgrader_source_selection', [$this, 'rename_github_zip_folder'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);

        if (is_admin()) {
            add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
            add_action('admin_init', [$this, 'handle_manual_update_check']);
            add_action('admin_notices', [$this, 'render_admin_notice']);
        }
    }

    /**
     * Read local plugin version directly from disk (first 4 KB) to bypass get_plugin_data() and RAM cache.
     */
    public static function get_local_version(): string
    {
        if (!is_readable(SCAT_MIGRATOR_FILE)) {
            return SCAT_MIGRATOR_VERSION;
        }

        $header = file_get_contents(SCAT_MIGRATOR_FILE, false, null, 0, 4096);
        if (!is_string($header) || $header === '') {
            return SCAT_MIGRATOR_VERSION;
        }

        if (preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $header, $matches) === 1) {
            $version = trim($matches[1]);
            if ($version !== '') {
                return $version;
            }
        }

        return SCAT_MIGRATOR_VERSION;
    }

    /**
     * Resolve GitHub token by priority order.
     *
     * @return array{token: string, source: string}
     */
    public static function resolve_token(): array
    {
        if (defined('SCAT_MIGRATOR_GITHUB_TOKEN') && is_string(SCAT_MIGRATOR_GITHUB_TOKEN)) {
            $token = trim(SCAT_MIGRATOR_GITHUB_TOKEN);
            if ($token !== '') {
                return ['token' => $token, 'source' => 'SCAT_MIGRATOR_GITHUB_TOKEN'];
            }
        }

        if (defined('SCAT_GITHUB_TOKEN') && is_string(SCAT_GITHUB_TOKEN)) {
            $token = trim(SCAT_GITHUB_TOKEN);
            if ($token !== '') {
                return ['token' => $token, 'source' => 'SCAT_GITHUB_TOKEN'];
            }
        }

        $own_option = get_option('scat_migrator_github_token', '');
        if (is_string($own_option) && trim($own_option) !== '') {
            return ['token' => trim($own_option), 'source' => 'scat_migrator_github_token'];
        }

        $shared_option = get_option('scat_github_token', '');
        if (is_string($shared_option) && trim($shared_option) !== '') {
            return ['token' => trim($shared_option), 'source' => 'scat_github_token'];
        }

        return ['token' => '', 'source' => 'public'];
    }

    /**
     * Clear all update-related transients.
     */
    public static function flush_transients(): void
    {
        delete_transient(self::TRANSIENT_VERSION);
        delete_transient(self::TRANSIENT_ERROR);
        delete_site_transient('update_plugins');
    }

    /**
     * Get manual check URL with nonce.
     */
    public static function get_manual_check_url(string $redirect_to = ''): string
    {
        $args = [
            'scat_migrator_check_update' => '1',
        ];

        if ($redirect_to !== '') {
            $args['redirect_to'] = rawurlencode($redirect_to);
        }

        $url = add_query_arg($args, admin_url('plugins.php'));

        return wp_nonce_url($url, self::NONCE_ACTION);
    }

    /**
     * Fetch remote version from GitHub (or transient cache unless forced).
     *
     * @return array{version: string|null, error: string|null}
     */
    public function fetch_remote_version(bool $force = false): array
    {
        if (!$force) {
            $cached_version = get_transient(self::TRANSIENT_VERSION);
            if (is_string($cached_version) && $cached_version !== '') {
                return ['version' => $cached_version, 'error' => null];
            }

            $cached_error = get_transient(self::TRANSIENT_ERROR);
            if (is_string($cached_error) && $cached_error !== '') {
                return ['version' => null, 'error' => $cached_error];
            }
        }

        $token_info = self::resolve_token();
        $token = $token_info['token'];

        if ($token === '') {
            $url = sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/%s.php?t=%d',
                self::GITHUB_OWNER,
                self::GITHUB_REPO,
                self::GITHUB_BRANCH,
                self::PLUGIN_SLUG,
                time()
            );

            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'WordPress-ScatMigrator',
                ],
            ]);

            if (is_wp_error($response)) {
                return $this->cache_error($response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return $this->cache_error(sprintf('GitHub Raw HTTP %d', $code));
            }

            $file_content = wp_remote_retrieve_body($response);
        } else {
            $url = sprintf(
                'https://api.github.com/repos/%s/%s/contents/%s.php?ref=%s',
                self::GITHUB_OWNER,
                self::GITHUB_REPO,
                self::PLUGIN_SLUG,
                self::GITHUB_BRANCH
            );

            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/vnd.github+json',
                    'User-Agent'    => 'WordPress-ScatMigrator',
                ],
            ]);

            if (is_wp_error($response)) {
                return $this->cache_error($response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return $this->cache_error(sprintf('GitHub API HTTP %d', $code));
            }

            $body = wp_remote_retrieve_body($response);
            $json = json_decode($body, true);

            if (!is_array($json) || empty($json['content']) || !is_string($json['content'])) {
                return $this->cache_error('Invalid GitHub API response structure.');
            }

            $decoded = base64_decode($json['content'], false);
            if (!is_string($decoded) || $decoded === '') {
                return $this->cache_error('Failed to decode GitHub file content.');
            }

            $file_content = $decoded;
        }

        if (preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $file_content, $matches) !== 1) {
            return $this->cache_error('Version header not found in remote plugin file.');
        }

        $remote_version = trim($matches[1]);
        if ($remote_version === '') {
            return $this->cache_error('Remote Version header is empty.');
        }

        delete_transient(self::TRANSIENT_ERROR);
        set_transient(self::TRANSIENT_VERSION, $remote_version, 12 * HOUR_IN_SECONDS);

        return ['version' => $remote_version, 'error' => null];
    }

    /**
     * Perform HTTP check during pre_set_site_transient_update_plugins when cache is expired.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function check_for_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $result = $this->fetch_remote_version(false);
        if ($result['version'] === null) {
            return $transient;
        }

        return $this->apply_version_to_transient($transient, $result['version']);
    }

    /**
     * Compare cached remote version on site_transient_update_plugins read. NO HTTP requests allowed here.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function inject_cached_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $cached_version = get_transient(self::TRANSIENT_VERSION);
        if (!is_string($cached_version) || $cached_version === '') {
            return $transient;
        }

        return $this->apply_version_to_transient($transient, $cached_version);
    }

    /**
     * Provide plugin details for the WordPress update modal.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public function plugin_info($result, string $action, object $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $cached_version = get_transient(self::TRANSIENT_VERSION);
        $version = (is_string($cached_version) && $cached_version !== '')
            ? $cached_version
            : self::get_local_version();

        return (object) [
            'name'          => 'SCaT Joomla 1.5 to WordPress Content Migrator',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $version,
            'author'        => '<a href="https://github.com/' . self::GITHUB_OWNER . '">' . self::GITHUB_OWNER . '</a>',
            'homepage'      => $this->get_repo_url(),
            'requires'      => '6.5',
            'requires_php'  => '8.1',
            'download_link' => $this->get_package_url(),
            'sections'      => [
                'description' => 'Інструмент міграції контенту (статті, новини, категорії, WebP-зображення) з Joomla 1.5 на WordPress + Polylang.',
            ],
        ];
    }

    /**
     * Protect Authorization/Accept headers for GitHub API and strip Authorization on CDN redirects.
     *
     * @param array<string, mixed> $args
     * @param string               $url
     * @return array<string, mixed>
     */
    public function filter_http_request_args(array $args, string $url): array
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);

        if ($this->is_repo_api_url($url)) {
            $token = self::resolve_token()['token'];
            if ($token !== '') {
                $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
                $headers = $this->remove_header_ci($headers, 'Authorization');
                $headers = $this->remove_header_ci($headers, 'Accept');

                $headers['Authorization'] = 'Bearer ' . $token;
                $headers['Accept'] = 'application/vnd.github+json';

                if (!$this->has_header_ci($headers, 'User-Agent')) {
                    $headers['User-Agent'] = 'WordPress-ScatMigrator';
                }

                $args['headers'] = $headers;
            }
        } elseif ($this->is_github_cdn_host($host)) {
            if (isset($args['headers']) && is_array($args['headers'])) {
                $args['headers'] = $this->remove_header_ci($args['headers'], 'Authorization');
            }
        }

        return $args;
    }

    /**
     * Duplicate header protection at the cURL level.
     *
     * @param mixed                $handle
     * @param array<string, mixed> $parsed_args
     * @param string               $url
     */
    public function filter_http_api_curl($handle, array $parsed_args, string $url): void
    {
        if (!is_resource($handle) && !($handle instanceof \CurlHandle)) {
            return;
        }

        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $raw_headers = isset($parsed_args['headers']) && is_array($parsed_args['headers']) ? $parsed_args['headers'] : [];

        if ($this->is_repo_api_url($url)) {
            $token = self::resolve_token()['token'];
            if ($token !== '') {
                $raw_headers = $this->remove_header_ci($raw_headers, 'Authorization');
                $raw_headers = $this->remove_header_ci($raw_headers, 'Accept');
                $raw_headers['Authorization'] = 'Bearer ' . $token;
                $raw_headers['Accept'] = 'application/vnd.github+json';

                if (!$this->has_header_ci($raw_headers, 'User-Agent')) {
                    $raw_headers['User-Agent'] = 'WordPress-ScatMigrator';
                }

                curl_setopt($handle, CURLOPT_HTTPHEADER, $this->format_curl_headers($raw_headers));
            }
        } elseif ($this->is_github_cdn_host($host)) {
            $raw_headers = $this->remove_header_ci($raw_headers, 'Authorization');
            curl_setopt($handle, CURLOPT_HTTPHEADER, $this->format_curl_headers($raw_headers));
        }
    }

    /**
     * Rename GitHub ZIP extracted directory to clean plugin slug.
     *
     * @param string|\WP_Error $source
     * @param string           $remote_source
     * @param object           $upgrader
     * @param array<string, mixed> $hook_extra
     * @return string|\WP_Error
     */
    public function rename_github_zip_folder($source, string $remote_source, object $upgrader, array $hook_extra)
    {
        if (is_wp_error($source)) {
            return $source;
        }

        $is_target_plugin = isset($hook_extra['plugin']) && $hook_extra['plugin'] === SCAT_MIGRATOR_BASENAME;
        $source_name = basename(untrailingslashit($source));

        if (!$is_target_plugin) {
            $matches_prefix = strpos($source_name, self::GITHUB_OWNER . '-' . self::GITHUB_REPO . '-') === 0
                || strpos($source_name, self::PLUGIN_SLUG . '-') === 0;

            if (!$matches_prefix) {
                return $source;
            }
        }

        global $wp_filesystem;
        if (!isset($wp_filesystem) || !is_object($wp_filesystem)) {
            return $source;
        }

        $new_source = trailingslashit($remote_source) . self::PLUGIN_SLUG;
        if (untrailingslashit($source) === untrailingslashit($new_source)) {
            return $source;
        }

        if ($wp_filesystem->exists($new_source)) {
            $wp_filesystem->delete($new_source, true);
        }

        if ($wp_filesystem->move($source, $new_source, true)) {
            return trailingslashit($new_source);
        }

        return new \WP_Error(
            'scat_migrator_rename_failed',
            'Unable to rename extracted GitHub plugin directory.'
        );
    }

    /**
     * Clear update cache after upgrade completes.
     *
     * @param object               $upgrader
     * @param array<string, mixed> $options
     */
    public function clear_update_cache(object $upgrader, array $options): void
    {
        if (($options['action'] ?? '') === 'update' && ($options['type'] ?? '') === 'plugin') {
            self::flush_transients();
        }
    }

    /**
     * Add manual update check link to plugin row meta.
     *
     * @param array<int, string> $links
     * @param string             $file
     * @return array<int, string>
     */
    public function add_plugin_row_meta(array $links, string $file): array
    {
        if ($file !== SCAT_MIGRATOR_BASENAME || !current_user_can('manage_options')) {
            return $links;
        }

        $url = self::get_manual_check_url(admin_url('plugins.php'));
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('🔄 Проверить обновления', 'scat-joomla-migrator')
        );

        return $links;
    }

    /**
     * Handle manual update check action.
     */
    public function handle_manual_update_check(): void
    {
        if (!isset($_GET['scat_migrator_check_update'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'scat-joomla-migrator'), 403);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed.', 'scat-joomla-migrator'), 403);
        }

        self::flush_transients();
        $result = $this->fetch_remote_version(true);
        $local_version = self::get_local_version();

        if ($result['error'] !== null) {
            $notice = [
                'type'    => 'error',
                'message' => sprintf(
                    'SCaT Migrator: не вдалося перевірити оновлення (%s).',
                    $result['error']
                ),
            ];
        } elseif ($result['version'] !== null && version_compare($result['version'], $local_version, '>')) {
            $notice = [
                'type'    => 'warning',
                'message' => sprintf(
                    'SCaT Migrator: доступна нова версія %s (встановлена %s).',
                    $result['version'],
                    $local_version
                ),
            ];
        } else {
            $notice = [
                'type'    => 'success',
                'message' => sprintf(
                    'SCaT Migrator: встановлена актуальна версія (%s).',
                    $local_version
                ),
            ];
        }

        set_transient(self::TRANSIENT_NOTICE_PREFIX . get_current_user_id(), $notice, 60);

        $redirect = admin_url('plugins.php');
        if (isset($_GET['redirect_to'])) {
            $candidate = esc_url_raw(wp_unslash((string) $_GET['redirect_to']));
            if ($candidate !== '' && strpos($candidate, admin_url()) === 0) {
                $redirect = $candidate;
            }
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Render admin notice after manual update check.
     */
    public function render_admin_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $key = self::TRANSIENT_NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_transient($key);

        $type = in_array($notice['type'] ?? '', ['success', 'warning', 'error', 'info'], true)
            ? $notice['type']
            : 'info';

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html((string) $notice['message'])
        );
    }

    /**
     * @param object $transient
     */
    private function apply_version_to_transient(object $transient, string $remote_version): object
    {
        $local_version = self::get_local_version();

        if (!isset($transient->checked) || !is_array($transient->checked)) {
            $transient->checked = [];
        }
        $transient->checked[SCAT_MIGRATOR_BASENAME] = $local_version;

        $plugin_data = (object) [
            'id'          => $this->get_repo_url(),
            'slug'        => self::PLUGIN_SLUG,
            'plugin'      => SCAT_MIGRATOR_BASENAME,
            'new_version' => $remote_version,
            'url'         => $this->get_repo_url(),
            'package'     => $this->get_package_url(),
        ];

        if (version_compare($remote_version, $local_version, '>')) {
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            $transient->response[SCAT_MIGRATOR_BASENAME] = $plugin_data;

            if (isset($transient->no_update) && is_array($transient->no_update)) {
                unset($transient->no_update[SCAT_MIGRATOR_BASENAME]);
            }
        } else {
            if (isset($transient->response) && is_array($transient->response)) {
                unset($transient->response[SCAT_MIGRATOR_BASENAME]);
            }

            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = [];
            }
            $plugin_data->new_version = $local_version;
            $plugin_data->package = '';
            $transient->no_update[SCAT_MIGRATOR_BASENAME] = $plugin_data;
        }

        return $transient;
    }

    /**
     * @return array{version: null, error: string}
     */
    private function cache_error(string $message): array
    {
        set_transient(self::TRANSIENT_ERROR, $message, 10 * MINUTE_IN_SECONDS);
        return ['version' => null, 'error' => $message];
    }

    private function get_repo_url(): string
    {
        return sprintf('https://github.com/%s/%s', self::GITHUB_OWNER, self::GITHUB_REPO);
    }

    private function get_package_url(): string
    {
        if (self::resolve_token()['token'] !== '') {
            return sprintf(
                'https://api.github.com/repos/%s/%s/zipball/%s',
                self::GITHUB_OWNER,
                self::GITHUB_REPO,
                self::GITHUB_BRANCH
            );
        }

        return sprintf(
            'https://github.com/%s/%s/archive/refs/heads/%s.zip',
            self::GITHUB_OWNER,
            self::GITHUB_REPO,
            self::GITHUB_BRANCH
        );
    }

    private function is_repo_api_url(string $url): bool
    {
        $prefix = sprintf(
            'https://api.github.com/repos/%s/%s/',
            self::GITHUB_OWNER,
            self::GITHUB_REPO
        );

        return strpos($url, $prefix) === 0;
    }

    private function is_github_cdn_host(string $host): bool
    {
        $host = strtolower($host);
        return $host === 'codeload.github.com' || $host === 'objects.githubusercontent.com';
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function remove_header_ci(array $headers, string $target): array
    {
        $target_lower = strtolower($target);
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strtolower($key) === $target_lower) {
                unset($headers[$key]);
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function has_header_ci(array $headers, string $target): bool
    {
        $target_lower = strtolower($target);
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strtolower($key) === $target_lower) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<int, string>
     */
    private function format_curl_headers(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            if (is_string($key)) {
                $formatted[] = $key . ': ' . (string) $value;
            } elseif (is_string($value)) {
                $formatted[] = $value;
            }
        }

        return $formatted;
    }
}
