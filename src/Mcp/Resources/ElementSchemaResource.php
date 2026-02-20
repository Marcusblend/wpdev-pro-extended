<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Resources;

use ProExtended\Elements\SchemaExtractor;

final class ElementSchemaResource implements ResourceInterface
{
    public function __construct(
        private readonly SchemaExtractor $schema,
    ) {}

    public function uri(): string
    {
        return 'pe://schema/elements';
    }

    public function name(): string
    {
        return 'Element Schema';
    }

    public function description(): string
    {
        return 'Full Cornerstone element schema with all types, properties, defaults, and options. Cached for performance.';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function read(): mixed
    {
        return $this->schema->getAllDefinitions();
    }
}
