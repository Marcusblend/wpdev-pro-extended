<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Layouts\LayoutService;

final class CreatePage implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'create_page';
    }

    public function description(): string
    {
        return 'Create a new WordPress page, optionally with Cornerstone layout data. Returns the new post ID.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['title'],
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => 'Page title.',
                ],
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Optional. Page slug (post_name). Auto-generated from title if not provided.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['draft', 'publish', 'private'],
                    'description' => 'Optional. Post status. Default: "draft".',
                ],
                'layout_data' => [
                    'type'        => ['array', 'null'],
                    'description' => 'Optional. Cornerstone layout data (element tree array).',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $title  = $arguments['title'] ?? '';
        $slug   = $arguments['slug'] ?? '';
        $status = $arguments['status'] ?? 'draft';
        $layout = $arguments['layout_data'] ?? null;

        if (empty($title)) {
            throw new \InvalidArgumentException('title is required.');
        }

        // Validate status.
        if (! in_array($status, ['draft', 'publish', 'private'], true)) {
            throw new \InvalidArgumentException('Invalid status. Must be "draft", "publish", or "private".');
        }

        $postArgs = [
            'post_title'  => sanitize_text_field($title),
            'post_type'   => 'page',
            'post_status' => $status,
        ];

        if (! empty($slug)) {
            $postArgs['post_name'] = sanitize_title($slug);
        }

        $postId = wp_insert_post($postArgs, true);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create page: ' . $postId->get_error_message());
        }

        // Save Cornerstone data if provided.
        if ($layout !== null && is_array($layout)) {
            $this->layouts->save($postId, $layout);
        }

        return [
            'post_id' => $postId,
            'title'   => get_the_title($postId),
            'slug'    => get_post_field('post_name', $postId),
            'status'  => get_post_status($postId),
            'url'     => get_permalink($postId),
        ];
    }

    public function requiredCapability(): string
    {
        return 'publish_pages';
    }
}
