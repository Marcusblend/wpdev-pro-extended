<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

final class ListFonts implements ToolInterface
{
    public function name(): string
    {
        return 'list_fonts';
    }

    public function description(): string
    {
        return 'List the Cornerstone global font definitions. Returns all registered fonts with their families and configuration.';
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
        $raw = get_option('cornerstone_font_items', '[]');

        if (is_string($raw)) {
            $fonts = json_decode(wp_unslash($raw), true);
        } else {
            $fonts = $raw;
        }

        if (! is_array($fonts)) {
            $fonts = [];
        }

        $fontConfig = get_option('cornerstone_font_config', '{}');
        if (is_string($fontConfig)) {
            $fontConfig = json_decode($fontConfig, true) ?: [];
        }

        return [
            'count'  => count($fonts),
            'fonts'  => $fonts,
            'config' => $fontConfig,
        ];
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
