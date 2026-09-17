<?php

declare(strict_types=1);

namespace ProExtended\Media;

/**
 * Adds files to the Media Library from an HTTPS URL or a small base64 payload.
 *
 * Downloads go through wp_safe_remote_get() (which refuses private and local
 * addresses) straight into a temporary file with a size cap. Each file is
 * hashed; a known hash returns the existing attachment. A temporary copy is
 * handed to media_handle_sideload(), which consumes the file it is given.
 */
final class MediaImporter
{
    public const MAX_BASE64_BYTES = 524288;          // 512 KB
    public const DEFAULT_MAX_DOWNLOAD_BYTES = 20971520; // 20 MB
    public const TIME_BUDGET_SECONDS = 40.0;
    public const DOWNLOAD_TIMEOUT_SECONDS = 20;

    public const TYPES = [
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'png'   => 'image/png',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'woff2' => 'font/woff2',
        'woff'  => 'font/woff',
        'svg'   => 'image/svg+xml',
    ];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    private const CONTENT_TYPE_EXTENSIONS = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/avif'    => 'avif',
        'image/svg+xml' => 'svg',
        'font/woff2'    => 'woff2',
        'font/woff'     => 'woff',
    ];

    /** @var array<string, true> Temporary files that passed SVG validation. */
    private array $validatedSvg = [];

    /**
     * Whether SVG uploads are allowed: the `pe_allow_svg_uploads` option
     * (default off), overridden by the PE_ALLOW_SVG_UPLOADS constant, then by
     * the filter of the same name.
     */
    public static function svgAllowed(): bool
    {
        $allowed = (string) get_option('pe_allow_svg_uploads', '0') === '1';

        if (defined('PE_ALLOW_SVG_UPLOADS')) {
            $allowed = (bool) constant('PE_ALLOW_SVG_UPLOADS');
        }

        return (bool) apply_filters('pe_allow_svg_uploads', $allowed);
    }

    public function maxDownloadBytes(): int
    {
        return max(1, (int) apply_filters('pe_upload_media_max_bytes', self::DEFAULT_MAX_DOWNLOAD_BYTES));
    }

    /**
     * Import validated items. Each item is independent.
     *
     * @param  array<int, array<string, mixed>|string> $items Validated items, or an error message per invalid item.
     * @return array{items: array<int, array<string, mixed>>, warnings: string[]}
     */
    public function import(array $items): array
    {
        $this->loadAdminIncludes();

        $started = microtime(true);
        $results = [];
        $warnings = [];

        $mimes = function (array $types): array {
            $types['woff2'] ??= self::TYPES['woff2'];
            $types['woff'] ??= self::TYPES['woff'];

            if (self::svgAllowed()) {
                $types['svg'] = self::TYPES['svg'];
            }

            return $types;
        };

        $check = function ($data, $file, $filename) {
            return $this->checkFiletype(is_array($data) ? $data : [], (string) $file, (string) $filename);
        };

        add_filter('upload_mimes', $mimes, 1000);
        add_filter('wp_check_filetype_and_ext', $check, 1000, 3);

        try {
            foreach ($items as $index => $item) {
                if (is_string($item)) {
                    $results[] = $this->failure($index, $item);
                    continue;
                }

                if (microtime(true) - $started > self::TIME_BUDGET_SECONDS) {
                    $results[] = $this->failure($index, null) + ['skipped' => 'time budget'];
                    continue;
                }

                $results[] = $this->importOne($index, $item, $warnings);
            }
        } finally {
            remove_filter('upload_mimes', $mimes, 1000);
            remove_filter('wp_check_filetype_and_ext', $check, 1000);
            $this->validatedSvg = [];
        }

        return ['items' => $results, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed> $item
     * @param  string[]             $warnings
     * @return array<string, mixed>
     */
    private function importOne(int $index, array $item, array &$warnings): array
    {
        $temp = null;
        $copy = null;

        try {
            [$temp, $filename, $sourceUrl] = $this->fetch($item);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (! isset(self::TYPES[$extension])) {
                throw new \RuntimeException(sprintf(
                    'File type "%s" is not allowed. Allowed: %s.',
                    $extension === '' ? '(none)' : '.' . $extension,
                    implode(', ', array_keys(self::TYPES))
                ));
            }

            $isImage = in_array($extension, self::IMAGE_EXTENSIONS, true);
            $alt = trim((string) ($item['alt'] ?? ''));
            $decorative = (bool) ($item['decorative'] ?? false);

            if ($isImage && ! $decorative && $alt === '') {
                throw new \RuntimeException('alt text is required for images (or set decorative: true).');
            }

            $svg = null;

            if ($extension === 'svg') {
                if (! self::svgAllowed()) {
                    throw new \RuntimeException('SVG uploads are turned off on this site (pe_allow_svg_uploads).');
                }

                $check = SvgValidator::validate((string) file_get_contents($temp));

                if (! $check['ok'] || $check['svg'] === null) {
                    throw new \RuntimeException('SVG refused: ' . implode(' ', $check['errors']));
                }

                $svg = $check['svg'];
            }

            $sha1 = (string) sha1_file($temp);
            $dedupe = (bool) ($item['dedupe'] ?? true);

            if ($dedupe) {
                $existing = $this->findByHash($sha1);

                if ($existing !== null) {
                    return $this->describe($index, $existing, true, $warnings);
                }
            }

            // media_handle_sideload() consumes the file it is given, so it only
            // ever gets a copy.
            $copy = wp_tempnam($filename);

            if ($svg !== null) {
                file_put_contents($copy, $svg);
                $this->validatedSvg[$copy] = true;
            } elseif (! copy($temp, $copy)) {
                throw new \RuntimeException('Could not prepare the file for upload.');
            }

            $checked = wp_check_filetype_and_ext($copy, $filename);

            if (empty($checked['ext']) || empty($checked['type']) || ! isset(self::TYPES[strtolower((string) $checked['ext'])])) {
                throw new \RuntimeException(sprintf('The contents of "%s" do not match an allowed file type.', $filename));
            }

            $postData = [];

            if (isset($item['title']) && $item['title'] !== '') {
                $postData['post_title'] = sanitize_text_field((string) $item['title']);
            }

            if (isset($item['caption'])) {
                $postData['post_excerpt'] = wp_kses_post((string) $item['caption']);
            }

            if (isset($item['description'])) {
                $postData['post_content'] = wp_kses_post((string) $item['description']);
            }

            $attachTo = (int) ($item['attach_to'] ?? 0);
            $id = media_handle_sideload(['name' => $filename, 'tmp_name' => $copy], $attachTo, null, $postData);

            if (is_wp_error($id)) {
                throw new \RuntimeException('Upload failed: ' . $id->get_error_message());
            }

            $copy = null; // Consumed by the sideload.

            update_post_meta($id, '_pe_source_sha1', $sha1);

            if ($sourceUrl !== null) {
                update_post_meta($id, '_pe_source_url', esc_url_raw($sourceUrl));
            }

            if ($isImage) {
                update_post_meta($id, '_wp_attachment_image_alt', $decorative ? '' : sanitize_text_field($alt));
            }

            return $this->describe($index, (int) $id, false, $warnings);
        } catch (\Throwable $e) {
            return $this->failure($index, $e->getMessage());
        } finally {
            foreach ([$temp, $copy] as $path) {
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    wp_delete_file($path);
                }
            }
        }
    }

    /**
     * Get the item's bytes into a temporary file.
     *
     * @param  array<string, mixed> $item
     * @return array{0: string, 1: string, 2: string|null} Temp path, file name, source URL.
     *
     * @throws \RuntimeException
     */
    private function fetch(array $item): array
    {
        if (isset($item['source_url'])) {
            $url = (string) $item['source_url'];
            $path = (string) parse_url($url, PHP_URL_PATH);
            $filename = sanitize_file_name((string) ($item['filename'] ?? wp_basename($path)));
            $temp = wp_tempnam($filename !== '' ? $filename : 'pe-media');
            $cap = $this->maxDownloadBytes();

            $response = wp_safe_remote_get($url, [
                'timeout'             => self::DOWNLOAD_TIMEOUT_SECONDS,
                'redirection'         => 3,
                'stream'              => true,
                'filename'            => $temp,
                'limit_response_size' => $cap,
            ]);

            if (is_wp_error($response)) {
                wp_delete_file($temp);
                throw new \RuntimeException('Download failed: ' . $response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);

            if ($code !== 200) {
                wp_delete_file($temp);
                throw new \RuntimeException(sprintf('Download failed: HTTP %d.', $code));
            }

            clearstatcache(true, $temp);
            $size = (int) filesize($temp);
            $length = (int) self::lastHeader(wp_remote_retrieve_header($response, 'content-length'));

            if ($length > $cap || $size >= $cap) {
                wp_delete_file($temp);
                throw new \RuntimeException(sprintf('The file is larger than the %s limit.', size_format($cap)));
            }

            if ($size === 0) {
                wp_delete_file($temp);
                throw new \RuntimeException('The download was empty.');
            }

            if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
                $type = strtolower(trim(explode(';', self::lastHeader(wp_remote_retrieve_header($response, 'content-type')))[0]));
                $filename = ($filename !== '' ? $filename : 'download') . '.' . (self::CONTENT_TYPE_EXTENSIONS[$type] ?? 'bin');
            }

            return [$temp, $filename, $url];
        }

        $data = (string) ($item['data_base64'] ?? '');

        if (preg_match('/^data:[^;,]*;base64,/i', $data, $prefix)) {
            $data = substr($data, strlen($prefix[0]));
        }

        $data = (string) preg_replace('/\s+/', '', $data);

        if (strlen($data) > (int) (ceil(self::MAX_BASE64_BYTES / 3) * 4)) {
            throw new \RuntimeException('data_base64 is larger than 512 KB once decoded.');
        }

        $bytes = base64_decode($data, true);

        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('data_base64 is not valid base64.');
        }

        if (strlen($bytes) > self::MAX_BASE64_BYTES) {
            throw new \RuntimeException('data_base64 is larger than 512 KB once decoded.');
        }

        $filename = sanitize_file_name((string) ($item['filename'] ?? ''));
        $temp = wp_tempnam($filename);

        if (file_put_contents($temp, $bytes) === false) {
            wp_delete_file($temp);
            throw new \RuntimeException('Could not write the decoded file.');
        }

        return [$temp, $filename, null];
    }

    /**
     * Accept font files by their signature and SVGs that passed validation,
     * in case the server's file-type detection does not recognise them.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function checkFiletype(array $data, string $file, string $filename): array
    {
        if (! empty($data['ext']) && ! empty($data['type'])) {
            return $data;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $signature = is_readable($file) ? (string) file_get_contents($file, false, null, 0, 4) : '';

        $recognised = ($extension === 'woff2' && $signature === 'wOF2')
            || ($extension === 'woff' && $signature === 'wOFF')
            || ($extension === 'svg' && isset($this->validatedSvg[$file]));

        if (! $recognised) {
            return $data;
        }

        return [
            'ext'             => $extension,
            'type'            => self::TYPES[$extension],
            'proper_filename' => $data['proper_filename'] ?? false,
        ];
    }

    /**
     * A header value as a string (the last one when the header repeats).
     *
     * @param string|string[] $value
     */
    private static function lastHeader(string|array $value): string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        return is_string($value) ? $value : '';
    }

    private function findByHash(string $sha1): ?int
    {
        $ids = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'meta_key'         => '_pe_source_sha1',
            'meta_value'       => $sha1,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ]);

        return $ids ? (int) $ids[0] : null;
    }

    /**
     * @param  string[] $warnings
     * @return array<string, mixed>
     */
    private function describe(int $index, int $id, bool $deduped, array &$warnings): array
    {
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : [];
        $mime = (string) get_post_mime_type($id);
        $sizes = [];

        foreach ((array) ($meta['sizes'] ?? []) as $name => $info) {
            $source = wp_get_attachment_image_src($id, (string) $name);

            if (is_array($source)) {
                $sizes[(string) $name] = $source[0];
            }
        }

        $width = isset($meta['width']) ? (int) $meta['width'] : null;
        $raster = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true);

        if (! $deduped && $raster && $sizes === [] && $width !== null && $width > (int) get_option('thumbnail_size_w', 150)) {
            $warnings[] = sprintf('WordPress generated no image subsizes for attachment %d (%dpx wide).', $id, $width);
        }

        return [
            'index'         => $index,
            'ok'            => true,
            'attachment_id' => $id,
            'url'           => wp_get_attachment_url($id),
            'mime'          => $mime,
            'width'         => $width,
            'height'        => isset($meta['height']) ? (int) $meta['height'] : null,
            'sizes'         => $sizes === [] ? (object) [] : $sizes,
            'cs_ref'        => $id . ':full',
            'deduped'       => $deduped,
            'error'         => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(int $index, ?string $error): array
    {
        return [
            'index'         => $index,
            'ok'            => false,
            'attachment_id' => null,
            'url'           => null,
            'mime'          => null,
            'width'         => null,
            'height'        => null,
            'sizes'         => (object) [],
            'cs_ref'        => null,
            'deduped'       => false,
            'error'         => $error,
        ];
    }

    private function loadAdminIncludes(): void
    {
        foreach (['file.php', 'media.php', 'image.php'] as $file) {
            if (is_readable(ABSPATH . 'wp-admin/includes/' . $file)) {
                require_once ABSPATH . 'wp-admin/includes/' . $file;
            }
        }
    }
}
