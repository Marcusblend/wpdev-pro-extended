<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Support\Args;
use ProExtended\Templates\TemplateGateway;

final class ExportTco implements ToolInterface, AnnotatedToolInterface
{
    /** Guard against returning an archive too large to pass through a tool result. */
    private const MAX_BYTES = 6 * 1024 * 1024;

    public function __construct(private readonly TemplateGateway $templates) {}

    public function name(): string
    {
        return 'export_tco';
    }

    public function description(): string
    {
        return 'Export templates or documents as a .tco archive, base64 encoded, the same file the builder\'s export produces: Cornerstone resolves dependencies, colours, fonts, components and attachments itself. group is "template" for library entries (ids from list_templates) or "document" for headers, footers, layouts and pages (ids from list_layouts), where the archive imports as a new document on the other site. Read-only: nothing on this site changes.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['ids'],
            'properties' => [
                'ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'maxItems'    => 100,
                    'description' => 'Template or document post IDs.',
                ],
                'group' => [
                    'type'        => 'string',
                    'enum'        => ['template', 'document'],
                    'description' => 'Optional. What the IDs are. Default: "template".',
                ],
                'include_thumbnails' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Keep preview images in the archive, which makes it much larger. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['ids', 'group', 'include_thumbnails'], 'arguments');

        $ids = Args::list($arguments, 'ids', 1, 100) ?? [];
        $group = (string) (Args::string($arguments, 'group', 'template', 20) ?? 'template');
        $includeThumbnails = Args::bool($arguments, 'include_thumbnails', false);

        if (! in_array($group, ['template', 'document'], true)) {
            throw new \InvalidArgumentException('group must be "template" or "document".');
        }

        $clean = [];

        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new \InvalidArgumentException('ids must be post IDs.');
            }

            $clean[] = (int) $id;
        }

        $export = $this->templates->export($clean, $group, ! $includeThumbnails);

        if ($export['bytes'] > self::MAX_BYTES) {
            throw new \RuntimeException(sprintf(
                'The archive is %d bytes, over the %d byte limit for a single call. Export fewer IDs at a time.',
                $export['bytes'],
                self::MAX_BYTES
            ));
        }

        return [
            'group'    => $export['group'],
            'ids'      => $clean,
            'bytes'    => $export['bytes'],
            'encoding' => 'base64',
            'filename' => sprintf('pe-export-%s-%s.tco', $group, gmdate('Ymd-His')),
            'tco'      => $export['tco'],
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('Export .tco');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
