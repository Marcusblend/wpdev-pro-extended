<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

final class ListColors implements ToolInterface
{
    public function name(): string
    {
        return 'list_colors';
    }

    public function description(): string
    {
        return 'List the Cornerstone global color palette. Returns all colors with their IDs, labels, and values.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => (object) [],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $raw = get_option('cornerstone_color_items', '[]');

        if (is_string($raw)) {
            $colors = json_decode(wp_unslash($raw), true);
        } else {
            $colors = $raw;
        }

        if (! is_array($colors)) {
            $colors = [];
        }

        return [
            'count'  => count($colors),
            'colors' => $colors,
        ];
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
