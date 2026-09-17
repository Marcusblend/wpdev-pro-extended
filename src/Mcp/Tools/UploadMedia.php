<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Media\MediaImporter;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class UploadMedia implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['items'];
    private const ITEM_KEYS = ['source_url', 'data_base64', 'filename', 'title', 'alt', 'decorative', 'caption', 'description', 'attach_to', 'dedupe'];

    public function __construct(
        private readonly MediaImporter $importer,
    ) {}

    public function name(): string
    {
        return 'upload_media';
    }

    public function description(): string
    {
        return 'Add 1-10 files to the Media Library from an https source_url or a small data_base64 payload (up to 512 KB; needs filename). Images (jpg, png, gif, webp, avif) need alt text unless decorative: true; woff2/woff fonts are accepted; SVG only when the site allows it, and only allowlisted SVG content. Files already uploaded (same content) are returned with deduped: true. Each item reports attachment_id, url, sizes and cs_ref ("<id>:full" for Cornerstone image fields). Items are independent; items skipped for time can be retried.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['items'],
            'properties' => [
                'items' => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'maxItems' => 10,
                    'items'    => [
                        'type'       => 'object',
                        'properties' => [
                            'source_url'  => ['type' => 'string', 'description' => 'https URL to download.'],
                            'data_base64' => ['type' => 'string', 'description' => 'File contents, base64 (512 KB max decoded).'],
                            'filename'    => ['type' => 'string', 'description' => 'File name with extension (required with data_base64).'],
                            'title'       => ['type' => 'string'],
                            'alt'         => ['type' => 'string', 'description' => 'Alt text (required for images unless decorative).'],
                            'decorative'  => ['type' => 'boolean', 'description' => 'Image is decorative; stored with empty alt.'],
                            'caption'     => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'attach_to'   => ['type' => 'integer', 'description' => 'Post ID to attach to.'],
                            'dedupe'      => ['type' => 'boolean', 'description' => 'Return an existing identical upload. Default: true.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $arguments = JsonArgs::decode($arguments, ['items']);
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $items = Args::list($arguments, 'items', 1, 10);

        if ($items === null) {
            throw new \InvalidArgumentException('"items" is required.');
        }

        $prepared = [];

        foreach ($items as $index => $item) {
            try {
                $prepared[$index] = $this->validateItem($item);
            } catch (\InvalidArgumentException $e) {
                $prepared[$index] = $e->getMessage();
            }
        }

        $result = $this->importer->import($prepared);
        $rows = $result['items'];

        return [
            'ok_count'      => count(array_filter($rows, static fn(array $r): bool => $r['ok'])),
            'error_count'   => count(array_filter($rows, static fn(array $r): bool => ! $r['ok'] && ! isset($r['skipped']))),
            'skipped_count' => count(array_filter($rows, static fn(array $r): bool => isset($r['skipped']))),
            'items'         => $rows,
            'warnings'      => $result['warnings'],
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function validateItem(mixed $item): array
    {
        if (! is_array($item) || ($item !== [] && array_is_list($item))) {
            throw new \InvalidArgumentException('Each item must be an object.');
        }

        Args::rejectUnknown($item, self::ITEM_KEYS, 'item');

        $hasUrl = isset($item['source_url']);
        $hasData = isset($item['data_base64']);

        if ($hasUrl === $hasData) {
            throw new \InvalidArgumentException('Give exactly one of source_url or data_base64.');
        }

        $clean = [
            'decorative' => Args::bool($item, 'decorative', false),
            'dedupe'     => Args::bool($item, 'dedupe', true),
        ];

        if ($hasUrl) {
            $url = Args::string($item, 'source_url', null, 2048, false);

            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                throw new \InvalidArgumentException('source_url must be an https:// URL.');
            }

            $clean['source_url'] = $url;
        } else {
            $clean['data_base64'] = Args::string($item, 'data_base64', null, 0, false);
        }

        $filename = Args::string($item, 'filename', null, 200);

        if ($hasData && ($filename === null || trim($filename) === '')) {
            throw new \InvalidArgumentException('filename (with extension) is required with data_base64.');
        }

        if ($filename !== null) {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (! isset(MediaImporter::TYPES[$extension])) {
                throw new \InvalidArgumentException(sprintf(
                    'File type "%s" is not allowed. Allowed: %s.',
                    $extension === '' ? '(none)' : '.' . $extension,
                    implode(', ', array_keys(MediaImporter::TYPES))
                ));
            }

            if (in_array($extension, MediaImporter::IMAGE_EXTENSIONS, true) && ! $clean['decorative'] && trim((string) ($item['alt'] ?? '')) === '') {
                throw new \InvalidArgumentException('alt text is required for images (or set decorative: true).');
            }

            $clean['filename'] = $filename;
        }

        foreach (['title' => 200, 'alt' => 500, 'caption' => 2000, 'description' => 10000] as $key => $max) {
            $value = Args::string($item, $key, null, $max);

            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        $attachTo = Args::int($item, 'attach_to', null, 1);

        if ($attachTo !== null) {
            if (! get_post($attachTo)) {
                throw new \InvalidArgumentException(sprintf('attach_to %d is not an existing post.', $attachTo));
            }

            $clean['attach_to'] = $attachTo;
        }

        return $clean;
    }

    public function annotations(): array
    {
        return Annotations::write('Upload Media', false, true, true);
    }

    public function requiredCapability(): string
    {
        return 'upload_files';
    }
}
