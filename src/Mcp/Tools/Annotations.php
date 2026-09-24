<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

/**
 * Builders for the annotation sets the bundled tools use.
 */
final class Annotations
{
    /**
     * @param  bool $openWorld Whether the tool can reach beyond the site, as rendering a looper that calls an external API does.
     * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public static function read(string $title, bool $openWorld = false): array
    {
        return [
            'title'           => $title,
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => $openWorld,
        ];
    }

    /**
     * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public static function write(string $title, bool $destructive, bool $idempotent, bool $openWorld = false): array
    {
        return [
            'title'           => $title,
            'readOnlyHint'    => false,
            'destructiveHint' => $destructive,
            'idempotentHint'  => $idempotent,
            'openWorldHint'   => $openWorld,
        ];
    }
}
