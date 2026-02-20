<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Layouts\LayoutService;

final class GetLayout implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'get_layout';
    }

    public function description(): string
    {
        return 'Get the full Cornerstone layout data for a specific post. Returns the JSON structure with post metadata, checksum, and the element tree.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['post_id'],
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The WordPress post ID to retrieve layout data from.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId = (int) ($arguments['post_id'] ?? 0);

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        return $this->layouts->get($postId);
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
