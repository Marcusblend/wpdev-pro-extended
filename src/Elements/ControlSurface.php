<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * The settings an element really has, in the shape the builder's Inspector
 * shows them.
 *
 * Cornerstone stores element data as a flat map of keys — text_font_size,
 * layout_div_flex_justify — but the Inspector groups those keys into tabs and
 * panels, gives each a label, a control type and a set of valid values, and
 * knows which keys are style and which are markup. All of that comes out of
 * the element registry; this class turns it into something an author can read
 * and write against, so an element can be built from its own settings instead
 * of a block of CSS.
 *
 * A control writes either one key or several. A unit slider labelled "Font
 * Size" writes text_base_font_size; a "Format" control writes eleven keys at
 * once and names them (font_size, text_color, line_height), which is the map
 * callers need to set typography without knowing Cornerstone's prefixes.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested from a captured
 * registry fixture without a site.
 */
final class ControlSurface
{
    /** Control types that hold other controls rather than writing keys themselves. */
    private const CONTAINER_TYPES = ['group', 'group-module'];

    /**
     * Build the surface for one element.
     *
     * @param  array<string, mixed> $inspector    One element's get_element_inspector_data() entry.
     * @param  array<string, mixed> $designations get_designations(): key => "style", "markup:bool", …
     * @param  array<string, mixed> $defaults     get_defaults(): key => default value.
     * @return array{panels: array<int, array<string, string>>, controls: array<int, array<string, mixed>>}
     */
    public static function build(array $inspector, array $designations = [], array $defaults = []): array
    {
        $nav = is_array($inspector['control_nav'] ?? null) ? $inspector['control_nav'] : [];
        $controls = is_array($inspector['controls'] ?? null) ? $inspector['controls'] : [];

        return [
            'panels'   => self::panels($nav),
            'controls' => self::flatten($controls, $nav, $designations, $defaults),
        ];
    }

    /**
     * Narrow a surface to the controls a term matches.
     *
     * Matches a label, a key, a named key or a panel name, so "font size",
     * "text_font_size" and "typography" all find their way home.
     *
     * @param  array{panels: array<int, array<string, string>>, controls: array<int, array<string, mixed>>} $surface
     * @return array{panels: array<int, array<string, string>>, controls: array<int, array<string, mixed>>}
     */
    public static function search(array $surface, string $term): array
    {
        $needle = strtolower(trim($term));

        if ($needle === '') {
            return $surface;
        }

        $hits = [];

        foreach ($surface['controls'] as $control) {
            if (self::matches($control, $needle)) {
                $hits[] = $control;
            }
        }

        return ['panels' => $surface['panels'], 'controls' => $hits];
    }

    /**
     * @param array<string, mixed> $control
     */
    private static function matches(array $control, string $needle): bool
    {
        $haystack = [
            (string) ($control['label'] ?? ''),
            (string) ($control['panel_name'] ?? ''),
            (string) ($control['type'] ?? ''),
        ];

        foreach ((array) ($control['keys'] ?? []) as $key) {
            $haystack[] = (string) $key;
        }

        foreach (array_keys((array) ($control['named_keys'] ?? [])) as $name) {
            $haystack[] = (string) $name;
        }

        foreach ($haystack as $candidate) {
            if ($candidate !== '' && str_contains(strtolower($candidate), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Inspector's tabs and panels, in order.
     *
     * A nav id with no colon is a tab ("text" => "Primary"); one with a colon
     * is a panel inside it ("text:setup" => "Setup"). A panel whose name is
     * empty has no heading of its own in the builder, so it takes its tab's.
     *
     * @param  array<string, mixed> $nav
     * @return array<int, array<string, string>>
     */
    private static function panels(array $nav): array
    {
        $tabs = [];

        foreach ($nav as $id => $name) {
            if (! str_contains((string) $id, ':')) {
                $tabs[(string) $id] = (string) $name;
            }
        }

        $panels = [];

        foreach ($nav as $id => $name) {
            $id = (string) $id;

            if (! str_contains($id, ':')) {
                continue;
            }

            [$tab] = explode(':', $id, 2);
            $tabName = $tabs[$tab] ?? $tab;

            $panels[] = [
                'id'   => $id,
                'tab'  => $tabName,
                'name' => (string) $name !== '' ? (string) $name : $tabName,
            ];
        }

        return $panels;
    }

    /**
     * Walk the control tree and return one row per control that writes a key.
     *
     * @param  array<int, mixed>    $controls
     * @param  array<string, mixed> $nav
     * @param  array<string, mixed> $designations
     * @param  array<string, mixed> $defaults
     * @return array<int, array<string, mixed>>
     */
    private static function flatten(array $controls, array $nav, array $designations, array $defaults, string $inherited = ''): array
    {
        $rows = [];

        foreach ($controls as $control) {
            if (! is_array($control)) {
                continue;
            }

            $group = (string) ($control['group'] ?? '') !== '' ? (string) $control['group'] : $inherited;
            $type = (string) ($control['type'] ?? '');

            if (isset($control['controls']) && is_array($control['controls'])) {
                $rows = array_merge($rows, self::flatten($control['controls'], $nav, $designations, $defaults, $group));

                if (in_array($type, self::CONTAINER_TYPES, true) && ! isset($control['key']) && ! isset($control['keys'])) {
                    continue;
                }
            }

            $row = self::row($control, $group, $nav, $designations, $defaults);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One control, or null when it writes nothing.
     *
     * @param  array<string, mixed> $control
     * @param  array<string, mixed> $nav
     * @param  array<string, mixed> $designations
     * @param  array<string, mixed> $defaults
     * @return array<string, mixed>|null
     */
    private static function row(array $control, string $group, array $nav, array $designations, array $defaults): ?array
    {
        $named = [];
        $keys = [];

        if (is_array($control['keys'] ?? null)) {
            foreach ($control['keys'] as $name => $key) {
                if (! is_string($key) || $key === '') {
                    continue;
                }

                $named[(string) $name] = $key;
                $keys[] = $key;
            }
        }

        if (is_string($control['key'] ?? null) && $control['key'] !== '') {
            $keys[] = $control['key'];
        }

        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            return null;
        }

        $row = [
            'label'      => self::label($control),
            'type'       => (string) ($control['type'] ?? ''),
            'panel'      => $group,
            'panel_name' => self::panelName($group, $nav),
            'keys'       => $keys,
        ];

        if ($named !== []) {
            $row['named_keys'] = $named;
        }

        $values = [];
        $marks = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $defaults)) {
                $values[$key] = $defaults[$key];
            }

            if (isset($designations[$key]) && is_string($designations[$key])) {
                $marks[$key] = $designations[$key];
            }
        }

        if ($values !== []) {
            $row['defaults'] = $values;
        }

        if ($marks !== []) {
            $row['designations'] = $marks;
            $row['writes'] = self::writes($marks);
        }

        $accepts = self::accepts($control);

        if ($accepts !== []) {
            $row['accepts'] = $accepts;
        }

        if (! empty($control['conditions'])) {
            $row['conditional'] = true;
        }

        return $row;
    }

    /**
     * Whether a control changes the page's CSS, its HTML, or both.
     *
     * @param array<string, string> $designations
     */
    private static function writes(array $designations): string
    {
        $style = false;
        $markup = false;

        foreach ($designations as $designation) {
            if (str_starts_with($designation, 'style')) {
                $style = true;
            }

            if (str_starts_with($designation, 'markup')) {
                $markup = true;
            }
        }

        return match (true) {
            $style && $markup => 'style and markup',
            $style            => 'style',
            $markup           => 'markup',
            default           => 'unknown',
        };
    }

    /**
     * The values a control will take, where it says so.
     *
     * @param  array<string, mixed> $control
     * @return array<string, mixed>
     */
    private static function accepts(array $control): array
    {
        $options = is_array($control['options'] ?? null) ? $control['options'] : [];
        $accepts = [];

        if (is_array($options['choices'] ?? null)) {
            $choices = [];

            foreach ($options['choices'] as $value => $choice) {
                $choices[(string) $value] = is_array($choice)
                    ? (string) ($choice['label'] ?? $choice['title'] ?? $value)
                    : (string) $choice;
            }

            $accepts['choices'] = $choices;
        }

        foreach (['available_units' => 'units', 'valid_keywords' => 'keywords'] as $from => $to) {
            if (is_array($options[$from] ?? null) && $options[$from] !== []) {
                $accepts[$to] = array_values($options[$from]);
            }
        }

        if (isset($options['fallback_value']) && is_scalar($options['fallback_value'])) {
            $accepts['fallback'] = (string) $options['fallback_value'];
        }

        if (is_array($options['list'] ?? null)) {
            $accepts['flags'] = array_keys($options['list']);
        }

        return $accepts;
    }

    /**
     * @param array<string, mixed> $control
     */
    private static function label(array $control): string
    {
        $label = $control['label'] ?? '';

        if (! is_string($label)) {
            return '';
        }

        // Cornerstone leaves a {{prefix}} placeholder in shared control labels;
        // the builder fills it with the element's own name.
        return trim(str_replace('{{prefix}}', '', $label));
    }

    /**
     * @param array<string, mixed> $nav
     */
    private static function panelName(string $group, array $nav): string
    {
        if ($group === '') {
            return '';
        }

        $name = $nav[$group] ?? '';

        if (is_string($name) && $name !== '') {
            return $name;
        }

        [$tab] = explode(':', $group, 2);
        $tabName = $nav[$tab] ?? '';

        return is_string($tabName) ? $tabName : '';
    }
}
