<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\HierarchyValidator;

final class ValidateLayout implements ToolInterface
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
        return 'Validate a Cornerstone layout JSON structure against the element schema. Checks element types, hierarchy rules, and _bp_data format.';
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
        $layoutData = $arguments['layout_data'] ?? null;
        $context = $arguments['context'] ?? 'inline';

        if ($layoutData === null) {
            throw new \InvalidArgumentException('layout_data is required.');
        }

        $result = $this->validator->validate($layoutData, $context);

        return $result->toArray();
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
