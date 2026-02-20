<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Resources;

final class ColorPaletteResource implements ResourceInterface
{
    public function uri(): string
    {
        return 'pe://colors/palette';
    }

    public function name(): string
    {
        return 'Color Palette';
    }

    public function description(): string
    {
        return 'The Cornerstone global color palette with all defined colors, IDs, and values.';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function read(): mixed
    {
        $raw = get_option('cornerstone_color_items', '[]');

        if (is_string($raw)) {
            return json_decode(wp_unslash($raw), true) ?: [];
        }

        return is_array($raw) ? $raw : [];
    }
}
