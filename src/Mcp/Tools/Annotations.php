<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

/**
 * Builders for the annotation sets the bundled tools use.
 */
final class Annotations
{
    /**
     * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public static function read(string $title): array
    {
        return [
            'title'           => $title,
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
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
