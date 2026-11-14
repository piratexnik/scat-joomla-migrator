<?php

declare(strict_types=1);

namespace ScatMigrator;

defined('ABSPATH') || exit;

final class ImageMigrator
{
    public const AJAX_SCAN_ACTION = 'scat_migrator_scan_images';
    public const AJAX_CONVERT_ACTION = 'scat_migrator_convert_batch';
    public const NONCE_ACTION = 'scat_migrator_ajax_nonce';

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_SCAN_ACTION, [$this, 'ajax_scan_images']);
        add_action('wp_ajax_' . self::AJAX_CONVERT_ACTION, [$this, 'ajax_convert_batch']);
    }

    /**
     * Get legacy paths and target WebP directory inside wp_upload_dir().
     *
     * @return array{
     *   basedir: string,
     *   baseurl: string,
     *   legacy_images_dir: string,
     *   legacy_docs_dir: string,
     *   legacy_docs_url: string,
     *   webp_dir: string,
     *   webp_url: string,
     *   queue_file: string
     * }
     */
    public static function get_paths(): array
    {
        $uploads = wp_upload_dir();
        $basedir = untrailingslashit((string) ($uploads['basedir'] ?? ''));
        $baseurl = untrailingslashit((string) ($uploads['baseurl'] ?? ''));

        $webp_dir = $basedir . '/scat-content-webp';

        return [
            'basedir'           => $basedir,
            'baseurl'           => $baseurl,
            'legacy_images_dir' => $basedir . '/joomla-legacy/images',
            'legacy_docs_dir'   => $basedir . '/joomla-legacy/dmdocuments',
            'legacy_docs_url'   => $baseurl . '/joomla-legacy/dmdocuments',
            'webp_dir'          => $webp_dir,
            'webp_url'          => $baseurl . '/scat-content-webp',
            'queue_file'        => $webp_dir . '/.queue.json',
        ];
    }

    /**
     * AJAX handler: scan legacy Joomla archive and build .queue.json for pending images.
     */
    public function ajax_scan_images(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        $paths = self::get_paths();
        $legacy_images_dir = $paths['legacy_images_dir'];
        $legacy_docs_dir = $paths['legacy_docs_dir'];
        $webp_dir = $paths['webp_dir'];
        $queue_file = $paths['queue_file'];

        if (!is_dir($webp_dir)) {
            wp_mkdir_p($webp_dir);
        }

        $total_source_count = 0;
        $total_source_bytes = 0;
        $converted_count = 0;
        $converted_bytes = 0;
        $skipped_invalid = 0;
        $skipped_files = [];
        $pending_queue = [];

        $images_dir_exists = is_dir($legacy_images_dir);

        if ($images_dir_exists) {
            $real_base = realpath($legacy_images_dir);
            if ($real_base !== false) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($real_base, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file_info) {
                    if (!$file_info instanceof \SplFileInfo || !$file_info->isFile()) {
                        continue;
                    }

                    $filename = $file_info->getFilename();
                    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

                    if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                        continue;
                    }

                    $full_path = $file_info->getRealPath();
                    if ($full_path === false || strpos($full_path, $real_base) !== 0) {
                        continue;
                    }

                    $rel_path = ltrim(str_replace('\\', '/', substr($full_path, strlen($real_base))), '/');
                    $base_name = trim((string) pathinfo($filename, PATHINFO_FILENAME));

                    if ($base_name === '') {
                        $skipped_invalid++;
                        if (count($skipped_files) < 100) {
                            $skipped_files[] = $rel_path . ' (порожнє ім\'я файлу до крапки)';
                        }
                        continue;
                    }

                    $size = (int) $file_info->getSize();
                    $total_source_count++;
                    $total_source_bytes += $size;

                    $target_rel = self::to_webp_relative_path($rel_path);
                    $target_abs = $webp_dir . '/' . $target_rel;

                    if (is_file($target_abs)) {
                        $converted_count++;
                        $converted_bytes += (int) @filesize($target_abs);
                    } else {
                        $pending_queue[] = $rel_path;
                    }
                }
            }
        }

        sort($pending_queue, SORT_NATURAL | SORT_FLAG_CASE);

        @file_put_contents(
            $queue_file,
            (string) wp_json_encode($pending_queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $docs_count = 0;
        $docs_bytes = 0;
        $docs_dir_exists = is_dir($legacy_docs_dir);

        if ($docs_dir_exists) {
            $real_docs = realpath($legacy_docs_dir);
            if ($real_docs !== false) {
                $doc_iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($real_docs, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($doc_iterator as $doc_info) {
                    if (!$doc_info instanceof \SplFileInfo || !$doc_info->isFile()) {
                        continue;
                    }

                    $doc_name = $doc_info->getFilename();
                    if (strpos($doc_name, '.') === 0) {
                        continue;
                    }

                    $docs_count++;
                    $docs_bytes += (int) $doc_info->getSize();
                }
            }
        }

        $pending_count = count($pending_queue);

        wp_send_json_success([
            'images_dir_exists'  => $images_dir_exists,
            'docs_dir_exists'    => $docs_dir_exists,
            'total_source_count' => $total_source_count,
            'total_source_bytes' => $total_source_bytes,
            'converted_count'    => $converted_count,
            'converted_bytes'    => $converted_bytes,
            'pending_count'      => $pending_count,
            'skipped_invalid'    => $skipped_invalid,
            'skipped_files'      => $skipped_files,
            'docs_count'         => $docs_count,
            'docs_bytes'         => $docs_bytes,
        ]);
    }

    /**
     * AJAX handler: convert a batch of images from .queue.json to WebP.
     */
    public function ajax_convert_batch(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав доступу.', 'scat-joomla-migrator')], 403);
        }

        if (!function_exists('imagewebp')) {
            wp_send_json_error(['message' => __('Функція imagewebp() недоступна на сервері.', 'scat-joomla-migrator')], 500);
        }

        $offset = isset($_POST['offset']) ? max(0, (int) wp_unslash($_POST['offset'])) : 0;
        $batch_size = isset($_POST['batch_size']) ? (int) wp_unslash($_POST['batch_size']) : 25;
        $batch_size = max(1, min(200, $batch_size));

        $max_width = isset($_POST['max_width']) ? (int) wp_unslash($_POST['max_width']) : 1920;
        $max_width = max(100, min(8000, $max_width));

        $quality = isset($_POST['quality']) ? (int) wp_unslash($_POST['quality']) : 82;
        $quality = max(10, min(100, $quality));

        $paths = self::get_paths();
        $queue_file = $paths['queue_file'];

        if (!is_readable($queue_file)) {
            wp_send_json_error([
                'message' => __('Файл черги .queue.json не знайдено. Спочатку виконайте сканування архіву.', 'scat-joomla-migrator'),
            ], 400);
        }

        $raw_queue = file_get_contents($queue_file);
        $queue = is_string($raw_queue) ? json_decode($raw_queue, true) : null;

        if (!is_array($queue)) {
            wp_send_json_error([
                'message' => __('Некоректний формат черги .queue.json. Повторіть сканування.', 'scat-joomla-migrator'),
            ], 400);
        }

        $total = count($queue);
        if ($total === 0 || $offset >= $total) {
            wp_send_json_success([
                'processed'   => 0,
                'converted'   => 0,
                'errors'      => [],
                'next_offset' => $total,
                'total'       => $total,
                'percent'     => 100,
            ]);
        }

        $real_legacy_base = realpath($paths['legacy_images_dir']);
        if ($real_legacy_base === false) {
            wp_send_json_error([
                'message' => __('Директорію вихідних зображень joomla-legacy/images не знайдено.', 'scat-joomla-migrator'),
            ], 400);
        }

        $slice = array_slice($queue, $offset, $batch_size);
        $processed = 0;
        $converted = 0;
        $errors = [];

        foreach ($slice as $item) {
            $processed++;
            if (!is_string($item) || $item === '') {
                continue;
            }

            $rel_path = ltrim(str_replace('\\', '/', $item), '/');
            if (strpos($rel_path, '..') !== false) {
                $errors[] = [
                    'file'   => $rel_path,
                    'reason' => 'Неприпустимий шлях (Path Traversal)',
                ];
                continue;
            }

            $src_candidate = $real_legacy_base . '/' . $rel_path;
            $real_src = realpath($src_candidate);

            if ($real_src === false || !is_file($real_src) || strpos($real_src, $real_legacy_base . DIRECTORY_SEPARATOR) !== 0) {
                $errors[] = [
                    'file'   => $rel_path,
                    'reason' => 'Вихідний файл не знайдено або поза межами директорії',
                ];
                continue;
            }

            $target_rel = self::to_webp_relative_path($rel_path);
            $dest_path = $paths['webp_dir'] . '/' . $target_rel;
            $dest_dir = dirname($dest_path);

            if (!is_dir($dest_dir) && !wp_mkdir_p($dest_dir)) {
                $errors[] = [
                    'file'   => $rel_path,
                    'reason' => 'Не вдалося створити цільову підпапку',
                ];
                continue;
            }

            $result = $this->convert_single_image($real_src, $dest_path, $max_width, $quality);
            if ($result === true) {
                $converted++;
            } else {
                $errors[] = [
                    'file'   => $rel_path,
                    'reason' => $result,
                ];
            }
        }

        $next_offset = min($total, $offset + $processed);
        $percent = $total > 0 ? (int) round(($next_offset / $total) * 100) : 100;

        wp_send_json_success([
            'processed'   => $processed,
            'converted'   => $converted,
            'errors'      => $errors,
            'next_offset' => $next_offset,
            'total'       => $total,
            'percent'     => $percent,
        ]);
    }

    /**
     * Convert relative path extension to lowercase .webp.
     */
    public static function to_webp_relative_path(string $rel_path): string
    {
        $rel_path = ltrim(str_replace('\\', '/', $rel_path), '/');
        $dir = pathinfo($rel_path, PATHINFO_DIRNAME);
        $filename = pathinfo($rel_path, PATHINFO_FILENAME);

        if ($dir === '.' || $dir === '') {
            return $filename . '.webp';
        }

        return $dir . '/' . $filename . '.webp';
    }

    /**
     * Convert a single image to WebP using GD, preserving alpha and resizing if needed.
     *
     * @return true|string True on success, or error message string on failure.
     */
    private function convert_single_image(string $src_path, string $dest_path, int $max_width, int $quality)
    {
        $ext = strtolower((string) pathinfo($src_path, PATHINFO_EXTENSION));
        $img = false;

        if ($ext === 'jpg' || $ext === 'jpeg') {
            $img = @imagecreatefromjpeg($src_path);
        } elseif ($ext === 'png') {
            $img = @imagecreatefrompng($src_path);
        } elseif ($ext === 'gif') {
            $img = @imagecreatefromgif($src_path);
        } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($src_path);
        }

        if ($img === false) {
            $raw = @file_get_contents($src_path);
            if (is_string($raw) && $raw !== '') {
                $img = @imagecreatefromstring($raw);
            }
        }

        if ($img === false) {
            return 'Пошкоджений файл або непідтримуваний формат (GD не зміг прочитати)';
        }

        $preserve_alpha = ($ext === 'png' || $ext === 'gif' || $ext === 'webp');
        if ($preserve_alpha) {
            if (function_exists('imagepalettetotruecolor')) {
                @imagepalettetotruecolor($img);
            }
            @imagealphablending($img, false);
            @imagesavealpha($img, true);
        }

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width <= 0 || $height <= 0) {
            imagedestroy($img);
            return 'Некоректні розміри зображення';
        }

        if ($width > $max_width || $height > $max_width) {
            $ratio = min($max_width / $width, $max_width / $height);
            $new_width = max(1, (int) round($width * $ratio));
            $new_height = max(1, (int) round($height * $ratio));

            $resized = @imagecreatetruecolor($new_width, $new_height);
            if ($resized === false) {
                imagedestroy($img);
                return 'Не вдалося виділити пам\'ять для зміни розміру (imagecreatetruecolor)';
            }

            if ($preserve_alpha) {
                @imagealphablending($resized, false);
                @imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                if ($transparent !== false) {
                    imagefilledrectangle($resized, 0, 0, $new_width, $new_height, $transparent);
                }
            }

            if (!@imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height)) {
                imagedestroy($resized);
                imagedestroy($img);
                return 'Помилка масштабування зображення (imagecopyresampled)';
            }

            imagedestroy($img);
            $img = $resized;
        }

        $saved = @imagewebp($img, $dest_path, $quality);
        imagedestroy($img);

        if (!$saved || !is_file($dest_path)) {
            return 'Помилка збереження у формат WebP (imagewebp)';
        }

        return true;
    }
}
