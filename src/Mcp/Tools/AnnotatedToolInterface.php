<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

/**
 * Optional contract for tools that describe themselves with MCP annotations.
 *
 * Kept separate from ToolInterface so third-party tools written against
 * 1.0.x keep loading without changes.
 */
interface AnnotatedToolInterface
{
    /**
     * MCP tool annotations (protocol 2025-03-26).
     *
     * @return array{title?: string, readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool}
     */
    public function annotations(): array;
}
