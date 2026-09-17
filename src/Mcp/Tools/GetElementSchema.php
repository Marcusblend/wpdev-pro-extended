<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\SchemaExtractor;

final class GetElementSchema implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly SchemaExtractor $schema,
    ) {}

    public function name(): string
    {
        return 'get_element_schema';
    }

    public function description(): string
    {
        return 'Get the full schema for a specific Cornerstone element type, including all properties with defaults, valid children, and options.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['element_type'],
            'properties' => [
                'element_type' => [
                    'type'        => 'string',
                    'description' => 'The element type identifier (e.g. "headline", "layout-row", "section").',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $type = $arguments['element_type'] ?? '';

        if (empty($type)) {
            throw new \InvalidArgumentException('element_type is required.');
        }

        $definition = $this->schema->getDefinition($type);

        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('Element type "%s" not found.', $type));
        }

        // Also include computed defaults for convenience.
        $defaults = $this->schema->getDefaults($type);

        return [
            'type'       => $type,
            'definition' => $definition,
            'defaults'   => $defaults,
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('Get Element Schema');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
