<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

final class ClearCache implements ToolInterface
{
    public function name(): string
    {
        return 'clear_cache';
    }

    public function description(): string
    {
        return 'Clear the Cornerstone TSS (compiled CSS) cache for a specific post or globally. Forces CSS regeneration on next page load.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Post ID to clear cache for. If omitted, clears all TSS caches.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId = isset($arguments['post_id']) ? (int) $arguments['post_id'] : null;

        if ($postId !== null && $postId > 0) {
            delete_post_meta($postId, '_cs_generated_tss');

            return [
                'cleared' => true,
                'scope'   => 'post',
                'post_id' => $postId,
            ];
        }

        // Global clear — delete all _cs_generated_tss meta entries.
        /** @var \wpdb $wpdb */
        global $wpdb;

        $count = (int) $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_cs_generated_tss'"
        );

        return [
            'cleared'       => true,
            'scope'         => 'global',
            'entries_cleared' => $count,
        ];
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
