<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\ControlSurface;
use ProExtended\Elements\ElementStyleFacts;
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
        return 'Get the settings a Cornerstone element type has, grouped the way the builder\'s Inspector groups them: tab, panel, label, control type, the values it accepts, its default, and the flat key or keys it writes. Use this to build an element from its own settings rather than a css block, so the client can adjust it in the builder afterwards. Each control lists keys (what to set on the element) and, where a control writes several at once, named_keys mapping a friendly name to its key — text-format, for instance, maps font_size to text_font_size. writes says whether a setting changes style, markup, or both. Pass search to narrow to matching controls ("font size", "flex", "text_font_family"). A tag control lists the tags it accepts (accepts.tags). A control writing a font family or weight key carries font_reference: the family is a global font\'s bare _id ("body") and the weight "fw-normal" or "fw-bold". emits says what the element\'s style template always outputs and the selector specificity Cornerstone writes it with (one generated class, 0,1,0), where the TSS source can be read; notes carry quirks such as the Section and Div clearfix under display grid or flex. format: "raw" returns Cornerstone\'s unprocessed definition instead, which is large.';
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
            'controls'       => self::withFontReferences($surface['controls']),
            'control_count'  => count($surface['controls']),
        ];

        if (is_string($search) && $search !== '') {
            $result['searched'] = $search;
            $result['controls_total'] = $total;
        }

        if ($surface['controls'] === [] && $total === 0) {
            $result['note'] = 'This site returned no Inspector data for the element. Call with format: "raw" for the stored defaults.';
        }

        $result += self::elementFacts($type);

        return $result;
    }

    /** What a font family or weight key holds, as Cornerstone resolves it (Settings\FontReferences). */
    public const FONT_FAMILY_REFERENCE = 'A global font\'s bare _id, such as "body" (get_native_reference section "fonts" lists them), "inherit", or var(). Nothing before the _id: a prefixed value renders Cornerstone\'s fallback font.';
    public const FONT_WEIGHT_REFERENCE = '"fw-normal" or "fw-bold" on its own: Cornerstone adds the family itself, so a value holding the family and a "|" renders inherit. "inherit", a number such as "700" (snapped to the closest weight the font has) or var() also work.';

    /**
     * Controls that write a font family or weight key, with what that key
     * holds (font_reference: key => form), so a model reading the schema
     * writes the form Cornerstone resolves.
     *
     * @param  array<int, array<string, mixed>> $controls
     * @return array<int, array<string, mixed>>
     */
    public static function withFontReferences(array $controls): array
    {
        foreach ($controls as $n => $control) {
            $references = [];

            foreach ((array) ($control['keys'] ?? []) as $key) {
                $key = (string) $key;
                $base = str_ends_with($key, '_alt') ? substr($key, 0, -4) : $key;

                if (str_ends_with($base, '_font_family')) {
                    $references[$key] = self::FONT_FAMILY_REFERENCE;
                } elseif (str_ends_with($base, '_font_weight')) {
                    $references[$key] = self::FONT_WEIGHT_REFERENCE;
                }
            }

            if ($references !== []) {
                $controls[$n]['font_reference'] = $references;
            }
        }

        return $controls;
    }

    /**
     * Facts builds had to discover by trial: what the element's styles always
     * emit and at what specificity, and layout quirks worth knowing.
     *
     * @return array<string, mixed>
     */
    private static function elementFacts(string $type): array
    {
        $facts = [];

        try {
            $facts['emits'] = (new ElementStyleFacts())->emits($type);
        } catch (\Throwable $e) {
            $facts['emits'] = ['unavailable' => 'The style template could not be read: ' . $e->getMessage()];
        }

        $notes = self::NOTES[$type] ?? [];

        if ($notes !== []) {
            $facts['notes'] = $notes;
        }

        return $facts;
    }

    /**
     * Element type => notes the surface cannot express on its own.
     */
    private const NOTES = [
        'section' => [
            'Pro gives sections a clearfix (::before and ::after with content). With display set to grid or flex those pseudo-elements become grid or flex items: an empty first and last grid cell, or extra gaps with space-between. Lay out the children with a Row, Grid or Div inside the section, or hide them in the section\'s css: $el::before, $el::after { display: none; }',
        ],
        'layout-div' => [
            'Pro gives containers a clearfix (::before and ::after with content). When a Div\'s display is grid (or flex with space-between), those pseudo-elements become items: an empty first and last grid cell, or extra gaps. Use a Grid element for grids, or hide them in the Div\'s css: $el::before, $el::after { display: none; }',
            'layout_div_tag takes the tags listed on its control; with "a" the Div renders as a link (layout_div_href).',
        ],
    ];

    public function annotations(): array
    {
        return Annotations::read('Get Element Schema');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
