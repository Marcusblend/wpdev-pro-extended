<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\ControlSurface;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Support\Args;

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
        return 'Get the settings a Cornerstone element type has, grouped the way the builder\'s Inspector groups them: tab, panel, label, control type, the values it accepts, its default, and the flat key or keys it writes. Use this to build an element from its own settings rather than a css block, so the client can adjust it in the builder afterwards. Each control lists keys (what to set on the element) and, where a control writes several at once, named_keys mapping a friendly name to its key — text-format, for instance, maps font_size to text_font_size. writes says whether a setting changes style, markup, or both. Pass search to narrow to matching controls ("font size", "flex", "text_font_family"). format: "raw" returns Cornerstone\'s unprocessed definition instead, which is large.';
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
                'search' => [
                    'type'        => 'string',
                    'description' => 'Optional. Return only controls whose label, panel, key or named key contains this text.',
                ],
                'format' => [
                    'type'        => 'string',
                    'enum'        => ['surface', 'raw'],
                    'description' => 'Optional. "surface" (default) returns the Inspector grouping; "raw" returns Cornerstone\'s full definition and defaults.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['element_type', 'search', 'format'], 'arguments');

        $type = (string) Args::string($arguments, 'element_type', null, 100, false);
        $format = (string) (Args::string($arguments, 'format', 'surface', 20, true) ?? 'surface');
        $search = Args::string($arguments, 'search', null, 100, true);

        if (! in_array($format, ['surface', 'raw'], true)) {
            throw new \InvalidArgumentException('format must be "surface" or "raw".');
        }

        $definition = $this->schema->getDefinition($type);

        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('Element type "%s" not found. Use list_elements to see them.', $type));
        }

        if ($format === 'raw') {
            return [
                'type'       => $type,
                'definition' => $definition,
                'defaults'   => $this->schema->getDefaults($type),
            ];
        }

        $surface = $this->schema->getSurface($type);
        $total = count($surface['controls']);

        if (is_string($search) && $search !== '') {
            $surface = ControlSurface::search($surface, $search);
        }

        $result = [
            'type'           => $type,
            'title'          => (string) ($definition['title'] ?? $type),
            'group'          => (string) ($definition['group'] ?? ''),
            'valid_children' => $definition['options']['valid_children'] ?? [],
            'panels'         => $surface['panels'],
            'controls'       => $surface['controls'],
            'control_count'  => count($surface['controls']),
        ];

        if (is_string($search) && $search !== '') {
            $result['searched'] = $search;
            $result['controls_total'] = $total;
        }

        if ($surface['controls'] === [] && $total === 0) {
            $result['note'] = 'This site returned no Inspector data for the element. Call with format: "raw" for the stored defaults.';
        }

        return $result;
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
