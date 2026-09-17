<?php

declare(strict_types=1);

namespace ProExtended\Mcp;

/**
 * Thrown by a tool when a permission check that depends on its arguments fails
 * (for example `refresh: true` on `list_components`).
 *
 * The server reports it as a tool result with `isError: true`; only the
 * argument-independent `requiredCapability()` check is a JSON-RPC error.
 */
final class ToolPermissionException extends \RuntimeException
{
}
