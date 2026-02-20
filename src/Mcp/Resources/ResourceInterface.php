<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Resources;

/**
 * Contract for MCP resources.
 */
interface ResourceInterface
{
    /**
     * Resource URI (e.g. 'pe://schema/elements').
     */
    public function uri(): string;

    /**
     * Human-readable display name.
     */
    public function name(): string;

    /**
     * Description of the resource.
     */
    public function description(): string;

    /**
     * MIME type of the resource content.
     */
    public function mimeType(): string;

    /**
     * Read the resource content.
     *
     * @return mixed JSON-serializable content.
     */
    public function read(): mixed;
}
