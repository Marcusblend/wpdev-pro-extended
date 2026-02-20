<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Layouts\LayoutService;

final class ListLayouts implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'list_layouts';
    }

    public function description(): string
    {
        return 'List all Cornerstone layouts including pages, headers, footers, singles, archives, and global blocks.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'type' => [
                    'type'        => 'string',
                    'description' => 'Optional. Filter by post type (e.g. "page", "cs_header", "cs_footer", "cs_global_block").',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $type = $arguments['type'] ?? null;
        $types = $type ? [$type] : [];

        $layouts = $this->layouts->listAll($types);

        return [
            'count'   => count($layouts),
            'layouts' => $layouts,
        ];
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
