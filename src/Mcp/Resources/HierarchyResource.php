<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Resources;

use ProExtended\Elements\SchemaExtractor;

final class HierarchyResource implements ResourceInterface
{
    public function __construct(
        private readonly SchemaExtractor $schema,
    ) {}

    public function uri(): string
    {
        return 'pe://schema/hierarchy';
    }

    public function name(): string
    {
        return 'Element Hierarchy';
    }

    public function description(): string
    {
        return 'Valid parent-child relationships between Cornerstone element types. Shows which elements can contain which others.';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function read(): mixed
    {
        return $this->schema->getHierarchyMap();
    }
}
