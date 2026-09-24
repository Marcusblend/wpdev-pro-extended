<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\ElementParents;
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
        return 'List all available Cornerstone element types with their names, groups, valid children and valid parents. valid_parents comes from the same registry data the validator checks: the element\'s own valid_parent when Cornerstone declares one ("region" means directly in a document region, e.g. a Section), otherwise every current container whose valid_children include it or "*". Use this to discover what elements can be used in layouts and where.';
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
        $parents = ElementParents::map($this->schema->getAllDefinitions());

        foreach ($elements as $i => $element) {
            $entry = $parents[(string) ($element['type'] ?? '')] ?? null;
            $elements[$i]['valid_parents'] = $entry['parents'] ?? [];
        }

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
