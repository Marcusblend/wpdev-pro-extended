<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\ElementContext;
use ProExtended\Settings\ThemeOptionsReader;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class GetThemeOptions implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['section', 'keys', 'search', 'changed_only'];

    public function __construct(
        private readonly ThemeOptionsReader $reader,
        private readonly ?ElementContext $elements = null,
    ) {}

    public function name(): string
    {
        return 'get_theme_options';
    }

    public function description(): string
    {
        return 'Read Pro\'s Theme Options (read only). Without arguments: the Theme Options panel sections with their key counts, how many values differ from their defaults, and the breakpoint tag. With section, keys, search or changed_only: each option\'s control label, value, default, whether it changed, its designation and, for responsive options, its per-breakpoint values. Secret-looking values are redacted and code is reported by size (use get_global_css for Global CSS).';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'section' => [
                    'type'        => 'string',
                    'description' => 'Optional. A section tag or label from the overview (for example "typography"), or "other" for keys outside the panel.',
                ],
                'keys' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'maxItems'    => 100,
                    'description' => 'Optional. Option keys to read (for example ["x_body_font_family_selection"]).',
                ],
                'search' => [
                    'type'        => 'string',
                    'description' => 'Optional. Case-insensitive text to find in keys and control labels.',
                ],
                'changed_only' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Only options whose value differs from the default. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $arguments = JsonArgs::decode($arguments, ['keys']);
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $section = Args::string($arguments, 'section', null, 100);
        $keys = Args::list($arguments, 'keys', 1, 100);
        $search = Args::string($arguments, 'search', null, 100);
        $changedOnly = Args::bool($arguments, 'changed_only', false);

        if ($keys !== null) {
            foreach ($keys as $i => $key) {
                if (! is_string($key) || $key === '') {
                    throw new \InvalidArgumentException(sprintf('keys[%d] must be a non-empty string.', $i));
                }
            }
        }

        $snapshot = $this->reader->snapshot();
        $registered = $snapshot['keys'];
        $sections = $this->reader->sections($registered);

        $sectionOf = [];
        $labelOf = [];

        foreach ($sections as $tag => $info) {
            foreach ($info['keys'] as $key => $label) {
                $sectionOf[$key] ??= $tag;
                $labelOf[$key] ??= $label;
            }
        }

        $rows = [];

        foreach ($registered as $key) {
            $rows[$key] = ThemeOptionsReader::describe(
                $key,
                $snapshot['values'][$key] ?? null,
                $snapshot['defaults'][$key] ?? null,
                $snapshot['designations'][$key] ?? null
            ) + ['section' => $sectionOf[$key] ?? ThemeOptionsReader::OTHER_SECTION, 'label' => $labelOf[$key] ?? null];
        }

        $breakpoints = $this->breakpoints($snapshot['values']);
        $result = [
            'stack'       => is_string($snapshot['values']['x_stack'] ?? null) ? $snapshot['values']['x_stack'] : null,
            'total_keys'  => count($rows),
            'changed'     => count(array_filter($rows, static fn(array $row): bool => $row['changed'])),
            'breakpoints' => $breakpoints,
        ];

        if ($section === null && $keys === null && $search === null && ! $changedOnly) {
            $result['sections'] = $this->overview($sections, $rows);
            $result['hint'] = 'Pass section, keys, search or changed_only to read values.';

            return $result;
        }

        $selected = $rows;
        $unknown = [];

        if ($section !== null) {
            $tag = $this->resolveSection($section, $sections);
            $selected = array_filter($selected, static fn(array $row): bool => $row['section'] === $tag);
            $result['section'] = $tag;
        }

        if ($keys !== null) {
            $unknown = array_values(array_diff($keys, $registered));
            $selected = array_intersect_key($selected, array_flip($keys));
        }

        if ($search !== null && $search !== '') {
            $needle = strtolower($search);
            $selected = array_filter($selected, static fn(array $row): bool => str_contains(strtolower($row['key']), $needle)
                || str_contains(strtolower((string) $row['label']), $needle));
        }

        if ($changedOnly) {
            $selected = array_filter($selected, static fn(array $row): bool => $row['changed']);
        }

        foreach ($selected as $key => $row) {
            if (in_array($key, $breakpoints['responsive_keys'], true)) {
                $values = $snapshot['values']['_bp_data' . $breakpoints['tag']][$key] ?? null;
                $selected[$key]['responsive'] = true;
                $selected[$key]['breakpoint_values'] = is_array($values) && ! isset($row['redacted']) ? array_values($values) : null;
            } else {
                $selected[$key]['responsive'] = false;
            }
        }

        $result['count'] = count($selected);
        $result['options'] = array_values($selected);

        if ($unknown !== []) {
            $result['unknown_keys'] = $unknown;
        }

        return $result;
    }

    /**
     * @param  array<string, array{label: string, keys: array<string, string|null>}> $sections
     * @param  array<string, array<string, mixed>>                                    $rows
     * @return array<int, array{tag: string, label: string, keys: int, changed: int}>
     */
    private function overview(array $sections, array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $tag = (string) $row['section'];
            $counts[$tag] ??= ['keys' => 0, 'changed' => 0];
            $counts[$tag]['keys']++;
            $counts[$tag]['changed'] += $row['changed'] ? 1 : 0;
        }

        $overview = [];

        foreach ($sections as $tag => $info) {
            if (isset($counts[$tag])) {
                $overview[] = ['tag' => $tag, 'label' => $info['label']] + $counts[$tag];
            }
        }

        if (isset($counts[ThemeOptionsReader::OTHER_SECTION])) {
            $overview[] = ['tag' => ThemeOptionsReader::OTHER_SECTION, 'label' => 'Not in the Theme Options panel'] + $counts[ThemeOptionsReader::OTHER_SECTION];
        }

        return $overview;
    }

    /**
     * @param array<string, array{label: string, keys: array<string, string|null>}> $sections
     *
     * @throws \InvalidArgumentException
     */
    private function resolveSection(string $section, array $sections): string
    {
        $wanted = strtolower(trim($section));

        if ($wanted === ThemeOptionsReader::OTHER_SECTION) {
            return ThemeOptionsReader::OTHER_SECTION;
        }

        foreach ($sections as $tag => $info) {
            if (strtolower($tag) === $wanted || strtolower($info['label']) === $wanted) {
                return $tag;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown section "%s". Sections: %s, other.',
            $section,
            implode(', ', array_keys($sections))
        ));
    }

    /**
     * @param  array<string, mixed> $values
     * @return array{tag: string, base: int|null, ranges: mixed, stored_tag: string|null, converted: bool, responsive_keys: string[]}
     */
    private function breakpoints(array $values): array
    {
        $tag = $this->elements?->breakpointTag() ?? (is_string($values['_bp_base'] ?? null) ? $values['_bp_base'] : '4_4');
        $stored = get_option('cs_option_data', []);
        $storedTag = is_array($stored) && is_string($stored['_bp_base'] ?? null) ? $stored['_bp_base'] : null;
        $responsive = function_exists('cs_theme_option_breakpoint_keys') ? array_keys((array) cs_theme_option_breakpoint_keys()) : [];

        foreach (array_keys((array) ($values['_bp_data' . $tag] ?? [])) as $key) {
            if (is_string($key) && ! in_array($key, $responsive, true)) {
                $responsive[] = $key;
            }
        }

        $base = get_option('x_breakpoint_base', null);

        return [
            'tag'             => $tag,
            'base'            => is_numeric($base) ? (int) $base : null,
            'ranges'          => get_option('x_breakpoint_ranges', null),
            'stored_tag'      => $storedTag,
            'converted'       => $storedTag !== null && $storedTag !== $tag,
            'responsive_keys' => array_values(array_map('strval', $responsive)),
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('Get Theme Options');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
