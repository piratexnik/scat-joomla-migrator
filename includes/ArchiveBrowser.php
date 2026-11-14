<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class ArchiveBrowser
{
    public const MENU_SLUG = 'scat-joomla-archive';
    public const AJAX_BROWSE_ACTION = 'scat_archive_browse';
    public const AJAX_IMPORT_ACTION = 'scat_archive_import_to_media';
    public const NONCE_ACTION = 'scat_archive_nonce';
    public const META_KEY_REL_PATH = '_scat_archive_rel_path';
    public const PER_PAGE = 60;

    private const IMAGE_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png', 'gif', 'svg'];

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_submenu']);
        add_action('wp_ajax_' . self::AJAX_BROWSE_ACTION, [$this, 'ajax_browse']);
        add_action('wp_ajax_' . self::AJAX_IMPORT_ACTION, [$this, 'ajax_import_to_media']);
    }

    public function register_submenu(): void
    {
        add_submenu_page(
            'upload.php',
            __('📁 Архів старого сайту', 'scat-joomla-migrator'),
            __('📁 Архів старого сайту', 'scat-joomla-migrator'),
            'upload_files',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Недостатньо прав доступу.', 'scat-joomla-migrator'), 403);
        }
        ?>
        <div class="wrap scat-archive-wrap">
            <div class="scat-archive-header">
                <h1><?php echo esc_html__('📁 Архів старого сайту (Joomla 1.5)', 'scat-joomla-migrator'); ?></h1>
                <?php if (current_user_can('manage_options')) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminPage::MENU_SLUG)); ?>" class="button">
                        <?php echo esc_html__('⚙️ До панелі міграції та конвертера WebP', 'scat-joomla-migrator'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="scat-archive-tabs" role="tablist">
                <button type="button" class="scat-tab-btn is-active" data-source="webp">
                    <?php echo esc_html__('🖼️ Зображення WebP (scat-content-webp)', 'scat-joomla-migrator'); ?>
                </button>
                <button type="button" class="scat-tab-btn" data-source="docs">
                    <?php echo esc_html__('📄 Документи та сертифікати (dmdocuments)', 'scat-joomla-migrator'); ?>
                </button>
            </div>

            <div class="scat-archive-toolbar">
                <nav class="scat-breadcrumbs" id="scat-archive-breadcrumbs" aria-label="Breadcrumbs">
                    <button type="button" class="scat-crumb-btn is-active" data-path="">
                        <?php echo esc_html__('🏠 Корінь', 'scat-joomla-migrator'); ?>
                    </button>
                </nav>

                <div class="scat-search-box">
                    <input
                        type="search"
                        id="scat-archive-search"
                        class="regular-text"
                        placeholder="<?php echo esc_attr__('🔍 Пошук файлу за назвою...', 'scat-joomla-migrator'); ?>"
                    >
                    <button type="button" id="scat-archive-search-clear" class="button" style="display: none;">
                        <?php echo esc_html__('Скинути', 'scat-joomla-migrator'); ?>
                    </button>
                </div>
            </div>

            <div id="scat-archive-status" class="scat-archive-status" style="display: none;"></div>

            <div id="scat-archive-folders-section" class="scat-archive-section" style="display: none;">
                <h3 class="scat-section-title"><?php echo esc_html__('Папки', 'scat-joomla-migrator'); ?></h3>
                <div id="scat-archive-folders" class="scat-folders-grid"></div>
            </div>

            <div id="scat-archive-files-section" class="scat-archive-section">
                <div class="scat-files-header">
                    <h3 class="scat-section-title" id="scat-files-heading"><?php echo esc_html__('Файли', 'scat-joomla-migrator'); ?></h3>
                    <span id="scat-files-counter" class="scat-counter-badge"></span>
                </div>
                <div id="scat-archive-files" class="scat-files-grid"></div>
                <div id="scat-archive-pagination" class="scat-pagination"></div>
            </div>

            <div id="scat-toast" class="scat-toast" role="status" aria-live="polite"></div>
        </div>
        <?php
    }

    /**
     * AJAX handler: browse directories or search files in WebP / DocMan archive.
     */
    public function ajax_browse(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $source = isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : 'webp';
        if (!in_array($source, ['webp', 'docs'], true)) {
            $source = 'webp';
        }

        $raw_path = isset($_POST['path']) ? sanitize_text_field(wp_unslash((string) $_POST['path'])) : '';
        $rel_dir = $this->sanitize_relative_path($raw_path);
        if ($rel_dir === null) {
            wp_send_json_error(['message' => __('Неприпустимий шлях директорії.', 'scat-joomla-migrator')], 400);
        }

        $search = isset($_POST['search']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['search']))) : '';
        $page = isset($_POST['page']) ? max(1, (int) wp_unslash($_POST['page'])) : 1;

        $source_config = $this->get_source_config($source);
        $base_dir = $source_config['dir'];
        $base_url = $source_config['url'];

        if (!is_dir($base_dir)) {
            wp_send_json_success([
                'dir_exists'  => false,
                'source'      => $source,
                'path'        => '',
                'breadcrumbs' => [],
                'folders'     => [],
                'files'       => [],
                'total_files' => 0,
                'page'        => 1,
                'total_pages' => 1,
            ]);
        }

        $real_base = realpath($base_dir);
        if ($real_base === false) {
            wp_send_json_error(['message' => __('Не вдалося визначити базову директорію.', 'scat-joomla-migrator')], 500);
        }

        $target_dir = $rel_dir === '' ? $real_base : $real_base . '/' . $rel_dir;
        $real_target = realpath($target_dir);

        if (
            $real_target === false
            || !is_dir($real_target)
            || ($real_target !== $real_base && strpos($real_target, $real_base . DIRECTORY_SEPARATOR) !== 0)
        ) {
            wp_send_json_error(['message' => __('Директорію не знайдено або доступ заборонено.', 'scat-joomla-migrator')], 400);
        }

        $folders = [];
        $matched_files = [];

        if ($search !== '') {
            $search_lower = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($real_base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }

                $filename = $item->getFilename();
                if (strpos($filename, '.') === 0) {
                    continue;
                }

                $name_lower = function_exists('mb_strtolower') ? mb_strtolower($filename, 'UTF-8') : strtolower($filename);
                if (strpos($name_lower, $search_lower) === false) {
                    continue;
                }

                $full = $item->getRealPath();
                if ($full === false || strpos($full, $real_base) !== 0) {
                    continue;
                }

                $file_rel = ltrim(str_replace('\\', '/', substr($full, strlen($real_base))), '/');
                $matched_files[] = [
                    'name'       => $filename,
                    'rel_path'   => $file_rel,
                    'size_bytes' => (int) $item->getSize(),
                ];
            }
        } else {
            $dir_iterator = new \DirectoryIterator($real_target);
            foreach ($dir_iterator as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                $entry_name = $entry->getFilename();
                if (strpos($entry_name, '.') === 0) {
                    continue;
                }

                $entry_real = $entry->getRealPath();
                if ($entry_real === false || strpos($entry_real, $real_base) !== 0) {
                    continue;
                }

                $entry_rel = ltrim(str_replace('\\', '/', substr($entry_real, strlen($real_base))), '/');

                if ($entry->isDir()) {
                    $folders[] = [
                        'name'       => $entry_name,
                        'rel_path'   => $entry_rel,
                        'item_count' => $this->count_directory_items($entry_real),
                    ];
                } elseif ($entry->isFile()) {
                    $matched_files[] = [
                        'name'       => $entry_name,
                        'rel_path'   => $entry_rel,
                        'size_bytes' => (int) $entry->getSize(),
                    ];
                }
            }
        }

        usort($folders, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        usort($matched_files, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $total_files = count($matched_files);
        $total_pages = max(1, (int) ceil($total_files / self::PER_PAGE));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $page_files = array_slice($matched_files, $offset, self::PER_PAGE);
        $imported_map = $this->get_imported_attachments_map($source, array_column($page_files, 'rel_path'));

        $files_out = [];
        foreach ($page_files as $file) {
            $ext = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
            $is_image = in_array($ext, self::IMAGE_EXTENSIONS, true);
            $meta_val = $source . ':' . $file['rel_path'];
            $imported_info = $imported_map[$meta_val] ?? null;

            $files_out[] = [
                'name'          => $file['name'],
                'rel_path'      => $file['rel_path'],
                'url'           => $this->build_public_url($base_url, $file['rel_path']),
                'size_bytes'    => $file['size_bytes'],
                'size_human'    => size_format($file['size_bytes'], 1),
                'ext'           => strtoupper($ext),
                'type'          => $is_image ? 'image' : 'doc',
                'imported'      => $imported_info !== null,
                'attachment_id' => $imported_info['attachment_id'] ?? 0,
                'edit_url'      => $imported_info['edit_url'] ?? '',
                'media_url'     => $imported_info['media_url'] ?? '',
            ];
        }

        wp_send_json_success([
            'dir_exists'  => true,
            'source'      => $source,
            'path'        => $rel_dir,
            'breadcrumbs' => $this->build_breadcrumbs($rel_dir),
            'folders'     => $folders,
            'files'       => $files_out,
            'total_files' => $total_files,
            'page'        => $page,
            'total_pages' => $total_pages,
        ]);
    }

    /**
     * AJAX handler: import a selected file from archive into WordPress Media Library.
     */
    public function ajax_import_to_media(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $source = isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : '';
        if (!in_array($source, ['webp', 'docs'], true)) {
            wp_send_json_error(['message' => __('Невідоме джерело архіву.', 'scat-joomla-migrator')], 400);
        }

        $raw_rel = isset($_POST['rel_path']) ? sanitize_text_field(wp_unslash((string) $_POST['rel_path'])) : '';
        $rel_path = $this->sanitize_relative_path($raw_rel);

        if ($rel_path === null || $rel_path === '') {
            wp_send_json_error(['message' => __('Неприпустимий шлях файлу.', 'scat-joomla-migrator')], 400);
        }

        $source_config = $this->get_source_config($source);
        $real_base = realpath($source_config['dir']);
        if ($real_base === false) {
            wp_send_json_error(['message' => __('Базова папка архіву не існує.', 'scat-joomla-migrator')], 400);
        }

        $real_file = realpath($real_base . '/' . $rel_path);
        if (
            $real_file === false
            || !is_file($real_file)
            || strpos($real_file, $real_base . DIRECTORY_SEPARATOR) !== 0
        ) {
            wp_send_json_error(['message' => __('Файл не знайдено або доступ заборонено.', 'scat-joomla-migrator')], 400);
        }

        $meta_value = $source . ':' . $rel_path;
        $existing_map = $this->get_imported_attachments_map($source, [$rel_path]);
        if (isset($existing_map[$meta_value])) {
            $existing = $existing_map[$meta_value];
            wp_send_json_success([
                'status'        => 'already_exists',
                'attachment_id' => $existing['attachment_id'],
                'edit_url'      => $existing['edit_url'],
                'url'           => $existing['media_url'],
            ]);
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            wp_send_json_error(['message' => (string) $uploads['error']], 500);
        }

        $target_dir = (string) $uploads['path'];
        if (!is_dir($target_dir) && !wp_mkdir_p($target_dir)) {
            wp_send_json_error(['message' => __('Не вдалося створити папку завантажень WP.', 'scat-joomla-migrator')], 500);
        }

        $original_name = basename($real_file);
        $unique_filename = wp_unique_filename($target_dir, $original_name);
        $new_file_path = trailingslashit($target_dir) . $unique_filename;

        if (!@copy($real_file, $new_file_path)) {
            wp_send_json_error(['message' => __('Не вдалося скопіювати файл у Медіатеку.', 'scat-joomla-migrator')], 500);
        }

        $filetype = wp_check_filetype($unique_filename, null);
        $mime_type = !empty($filetype['type']) ? (string) $filetype['type'] : 'application/octet-stream';
        $file_url = trailingslashit((string) $uploads['url']) . rawurlencode($unique_filename);

        $title = preg_replace('/\.[^.]+$/', '', $original_name);
        $title = is_string($title) && $title !== '' ? $title : $original_name;

        $attachment_data = [
            'guid'           => $file_url,
            'post_mime_type' => $mime_type,
            'post_title'     => sanitize_text_field($title),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment_data, $new_file_path, 0, true);
        if (is_wp_error($attachment_id)) {
            @unlink($new_file_path);
            wp_send_json_error(['message' => $attachment_id->get_error_message()], 500);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_meta = wp_generate_attachment_metadata((int) $attachment_id, $new_file_path);
        if (is_array($attach_meta)) {
            wp_update_attachment_metadata((int) $attachment_id, $attach_meta);
        }

        update_post_meta((int) $attachment_id, self::META_KEY_REL_PATH, $meta_value);

        $final_url = wp_get_attachment_url((int) $attachment_id);
        $edit_url = get_edit_post_link((int) $attachment_id, 'raw');

        wp_send_json_success([
            'status'        => 'imported',
            'attachment_id' => (int) $attachment_id,
            'edit_url'      => is_string($edit_url) ? $edit_url : admin_url('post.php?post=' . (int) $attachment_id . '&action=edit'),
            'url'           => is_string($final_url) ? $final_url : $file_url,
        ]);
    }

    /**
     * Batch check which files on the current page are already imported into WP Media Library.
     * Uses a single prepared SQL query (no N+1 queries).
     *
     * @param array<int, string> $rel_paths
     * @return array<string, array{attachment_id: int, edit_url: string, media_url: string}>
     */
    private function get_imported_attachments_map(string $source, array $rel_paths): array
    {
        if ($rel_paths === []) {
            return [];
        }

        global $wpdb;

        $meta_values = [];
        foreach ($rel_paths as $rel) {
            $meta_values[] = $source . ':' . $rel;
        }

        $placeholders = implode(', ', array_fill(0, count($meta_values), '%s'));
        $query_args = array_merge([self::META_KEY_REL_PATH], $meta_values);

        $sql = $wpdb->prepare(
            "SELECT pm.meta_value, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
             WHERE pm.meta_key = %s
               AND pm.meta_value IN ({$placeholders})",
            ...$query_args
        );

        $rows = is_string($sql) ? $wpdb->get_results($sql) : [];
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_object($row) || empty($row->meta_value) || empty($row->post_id)) {
                continue;
            }

            $att_id = (int) $row->post_id;
            $att_url = wp_get_attachment_url($att_id);

            $map[(string) $row->meta_value] = [
                'attachment_id' => $att_id,
                'edit_url'      => admin_url('post.php?post=' . $att_id . '&action=edit'),
                'media_url'     => is_string($att_url) ? $att_url : '',
            ];
        }

        return $map;
    }

    /**
     * @return array{dir: string, url: string}
     */
    private function get_source_config(string $source): array
    {
        $paths = ImageMigrator::get_paths();

        if ($source === 'docs') {
            return [
                'dir' => $paths['legacy_docs_dir'],
                'url' => $paths['legacy_docs_url'],
            ];
        }

        return [
            'dir' => $paths['webp_dir'],
            'url' => $paths['webp_url'],
        ];
    }

    /**
     * Normalize and validate a relative path against traversal.
     */
    private function sanitize_relative_path(string $path): ?string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return '';
        }

        if (strpos($normalized, "\0") !== false) {
            return null;
        }

        $segments = explode('/', $normalized);
        $clean = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $clean[] = $segment;
        }

        return implode('/', $clean);
    }

    /**
     * Count immediate visible items inside a folder.
     */
    private function count_directory_items(string $dir_path): int
    {
        $count = 0;
        try {
            $iter = new \FilesystemIterator($dir_path, \FilesystemIterator::SKIP_DOTS);
            foreach ($iter as $item) {
                if ($item instanceof \SplFileInfo && strpos($item->getFilename(), '.') !== 0) {
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return $count;
    }

    /**
     * Build URL-encoded public URL for relative path.
     */
    private function build_public_url(string $base_url, string $rel_path): string
    {
        $segments = explode('/', ltrim(str_replace('\\', '/', $rel_path), '/'));
        $encoded = array_map('rawurlencode', $segments);

        return untrailingslashit($base_url) . '/' . implode('/', $encoded);
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    private function build_breadcrumbs(string $rel_dir): array
    {
        if ($rel_dir === '') {
            return [];
        }

        $parts = explode('/', $rel_dir);
        $crumbs = [];
        $accum = [];

        foreach ($parts as $part) {
            $accum[] = $part;
            $crumbs[] = [
                'name' => $part,
                'path' => implode('/', $accum),
            ];
        }

        return $crumbs;
    }
}
