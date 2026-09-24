<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * The parameter types a component's `_p_json` (and Global Parameters) can use.
 *
 * Two kinds exist. Managed types are named compositions Cornerstone keeps in
 * PHP (ManagedParameters::managedTypes(), plus the `cs_parameters_managed`
 * filter): "bg-image", "text-align", "managed-size" and so on. Each expands
 * into a base type with its own initial value, options or sub-parameters.
 * Base types (text, choose, select, color, image, …) are defined by the
 * builder's JavaScript, which PHP cannot read, so only the base types the
 * managed registry itself composes from are listed as confirmed; the rest are
 * reported as unreadable rather than guessed.
 *
 * How a value comes out at render time is decided by Parameter.php, which
 * treats a few shapes specially; STRUCTURE describes those.
 *
 * shape() is pure, so it is unit-tested from a fixture of the registry.
 */
final class ParameterTypeCatalog
{
    /**
     * What Parameter.php (Cornerstone 7.9.4) does with each shape of schema entry.
     */
    public const STRUCTURE = [
        'default'    => 'Any type not listed below outputs its stored value, or "initial" when nothing is stored. Strings may hold Dynamic Content, expanded where the parameter is printed.',
        'shorthand'  => 'A schema entry may be a string "<type>|<initial>" (or just "<initial>", which is a text parameter).',
        'group'      => '"type": "group" with "params": {...} outputs an object of its sub-parameters. A key written "name#" is a group shortcut.',
        'list'       => 'A type ending in "[]" (e.g. "group[]", "bg-image[]") outputs a list of objects, one per item, each shaped by "params". A key written "name[]" is a group[] shortcut.',
        'color-pair' => '"color-pair" outputs {"base": ..., "alt": ...}; an empty base becomes "transparent" and an empty alt copies base.',
        'isVar'      => '"isVar": true makes the parameter a CSS variable: in style contexts {{dc:param:x}} and Twig param.x resolve to var(--…) and the value can vary per breakpoint (unless "responsive": false).',
        'managed'    => 'A managed type expands into its base type, keeping any initial/options/params the entry adds.',
        'access'     => 'Inside a component read a parameter with {{dc:param:<path>}} (alias p) or Twig {{ param.<path> }}. Global Parameters are read with {{dc:g:<path>}} or Twig {{ dc.g.<path> }} (DynamicContent\'s cs_dynamic_content_g filter).',
    ];

    /**
     * @param  array<string, mixed> $managed ManagedParameters::managedTypes()
     * @return array<string, mixed>
     */
    public static function shape(array $managed): array
    {
        $types = [];
        $bases = [];

        foreach ($managed as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                continue;
            }

            $base = is_string($definition['type'] ?? null) ? $definition['type'] : 'text';
            $row = [
                'type'    => $name,
                'expands_to' => $base,
                'outputs' => self::outputs($base),
            ];

            if (array_key_exists('initial', $definition) && (is_scalar($definition['initial']) || $definition['initial'] === null)) {
                $row['initial'] = $definition['initial'];
            }

            $values = self::optionValues($definition['options'] ?? null);

            if ($values !== []) {
                $row['values'] = $values;
            }

            if (is_array($definition['params'] ?? null)) {
                $params = [];

                foreach ($definition['params'] as $key => $param) {
                    $paramType = is_array($param) && is_string($param['type'] ?? null) ? $param['type'] : 'text';
                    $params[(string) $key] = $paramType;
                    $bases[$paramType] = true;
                }

                $row['params'] = $params;
            }

            if (! empty($definition['isVar'])) {
                $row['isVar'] = true;
            }

            $bases[$base] = true;
            $types[] = $row;
        }

        // A managed name used inside another managed type is not a base type.
        $baseTypes = array_values(array_diff(array_keys($bases), array_keys($managed)));
        sort($baseTypes);

        return [
            'managed'   => $types,
            'base_types' => [
                'confirmed' => $baseTypes,
                'note'      => 'Base types are defined in the builder\'s JavaScript, which PHP cannot read. These are the base types Cornerstone\'s own managed types are built from, so they are certainly valid; others the builder offers are not listed here. "color-pair" and "group" (with "[]" for lists) are handled in PHP and always valid.',
            ],
            'structure' => self::STRUCTURE,
        ];
    }

    /**
     * Type names, for the platform baseline: managed types and the base
     * types they use.
     *
     * @param  array<string, mixed> $managed
     * @return string[]
     */
    public static function fingerprint(array $managed): array
    {
        $shape = self::shape($managed);
        $ids = array_merge(array_column($shape['managed'], 'type'), $shape['base_types']['confirmed']);
        $ids = array_values(array_unique(array_map('strval', $ids)));
        sort($ids);

        return $ids;
    }

    private static function outputs(string $base): string
    {
        return match (true) {
            str_ends_with($base, '[]') => 'a list of objects',
            $base === 'group'          => 'an object of its params',
            $base === 'color-pair'     => '{"base": ..., "alt": ...}',
            in_array($base, ['choose', 'select'], true) => 'the chosen option\'s value',
            $base === 'image'          => 'an image reference ("<attachment_id>:full" or a URL)',
            default                    => 'the stored string',
        };
    }

    /**
     * @return string[]
     */
    private static function optionValues(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $values = [];

        foreach ($options as $option) {
            if (is_array($option) && array_key_exists('value', $option) && is_scalar($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }

        return $values;
    }

    // ─── Live registry ───────────────────────────────────────────────────────

    /**
     * The managed types as Cornerstone defines them, or null when the class
     * is not there.
     *
     * @return array<string, mixed>|null
     */
    public function managed(): ?array
    {
        $class = 'Themeco\\Cornerstone\\Util\\ManagedParameters';

        if (! class_exists($class) || ! method_exists($class, 'managedTypes')) {
            return null;
        }

        $managed = BuilderContext::read(static fn (): mixed => $class::managedTypes(), null);

        return is_array($managed) ? $managed : null;
    }
}
