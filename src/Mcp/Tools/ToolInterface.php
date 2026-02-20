<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

/**
 * Contract for MCP tools.
 */
interface ToolInterface
{
    /**
     * Unique tool name (e.g. 'list_elements').
     */
    public function name(): string;

    /**
     * Human-readable description for the AI agent.
     */
    public function description(): string;

    /**
     * JSON Schema for the tool's input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param  array<string, mixed> $arguments
     * @return mixed JSON-serializable result.
     */
    public function execute(array $arguments): mixed;

    /**
     * WordPress capability required to call this tool.
     */
    public function requiredCapability(): string;
}
