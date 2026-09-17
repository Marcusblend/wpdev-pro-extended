<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\HierarchyValidator;
use ProExtended\Support\JsonArgs;

final class ValidateLayout implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly HierarchyValidator $validator,
    ) {}

    public function name(): string
    {
        return 'validate_layout';
    }

    public function description(): string
    {
        return 'Validate a Cornerstone layout JSON structure against the element schema. Checks element types, hierarchy rules, _bp_data format, and component instances (unknown component_id or undeclared parameters produce warnings).';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['layout_data'],
            'properties' => [
                'layout_data' => [
                    'type'        => ['array', 'object'],
                    'description' => 'The layout data to validate (element tree or flat map).',
                ],
                'context' => [
                    'type'        => 'string',
                    'enum'        => ['inline', 'flat'],
                    'description' => 'Validation context: "inline" for page/layout trees, "flat" for global blocks. Default: "inline".',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $arguments = JsonArgs::decode($arguments, ['layout_data']);
        $layoutData = $arguments['layout_data'] ?? null;
        $context = $arguments['context'] ?? 'inline';

        if ($layoutData === null) {
            throw new \InvalidArgumentException('layout_data is required.');
        }

        if (! in_array($context, ['inline', 'flat'], true)) {
            throw new \InvalidArgumentException('Invalid context. Must be "inline" or "flat".');
        }

        $result = $this->validator->validate($layoutData, $context);

        return $result->toArray();
    }

    public function annotations(): array
    {
        return Annotations::read('Validate Layout');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
