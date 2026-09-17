<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Support\Json;

final class ListFonts implements ToolInterface, AnnotatedToolInterface
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

        // Cornerstone stores the config slashed (wp_slash(cs_json_encode())),
        // and the options API does not unslash, so decode both forms.
        $fontConfig = Json::decodeStored(get_option('cornerstone_font_config', '{}')) ?? [];

        return [
            'count'  => count($fonts),
            'fonts'  => $fonts,
            'config' => $fontConfig === [] ? (object) [] : $fontConfig,
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('List Fonts');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
