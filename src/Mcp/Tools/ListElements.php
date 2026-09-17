<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\SchemaExtractor;

final class ListElements implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly SchemaExtractor $schema,
    ) {}

    public function name(): string
    {
        return 'list_elements';
    }

    public function description(): string
    {
        return 'List all available Cornerstone element types with their names, groups, and valid children. Use this to discover what elements can be used in layouts.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'group' => [
                    'type'        => 'string',
                    'description' => 'Optional. Filter elements by group (e.g. "content", "layout", "media").',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $elements = $this->schema->getElementList();

        $group = $arguments['group'] ?? null;

        if ($group) {
            $elements = array_values(array_filter($elements, fn($el) => ($el['group'] ?? '') === $group));
        }

        return [
            'count'    => count($elements),
            'elements' => $elements,
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('List Elements');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
