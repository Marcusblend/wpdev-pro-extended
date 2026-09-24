<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Which element types each element may sit inside.
 *
 * Read from the same registry data the validator checks children against:
 * each definition's options.valid_children ("*", one type or a list). Where a
 * definition also declares options.valid_parent (a tab inside tabs, a section
 * directly in a region), that stricter rule is Cornerstone's own and wins.
 * Classic and deprecated containers are left out of the derived lists, since
 * new content should not use them.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ElementParents
{
    /**
     * @param  array<int, mixed> $definitions Element definitions as SchemaExtractor returns them.
     * @return array<string, array{parents: string[], source: string}>
     */
    public static function map(array $definitions): array
    {
        $children = [];
        $declared = [];
        $retired = [];
        $types = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $type = (string) ($definition['id'] ?? $definition['name'] ?? '');

            if ($type === '') {
                continue;
            }

            $types[] = $type;
            $options = is_array($definition['options'] ?? null) ? $definition['options'] : [];

            $validChildren = self::list($options['valid_children'] ?? null);

            if ($validChildren !== []) {
                $children[$type] = $validChildren;
            }

            $validParent = self::list($options['valid_parent'] ?? null);

            if ($validParent !== []) {
                $declared[$type] = $validParent;
            }

            if (str_starts_with($type, 'classic:') || ($definition['group'] ?? null) === 'deprecated') {
                $retired[$type] = true;
            }
        }

        $map = [];

        foreach ($types as $type) {
            if (isset($declared[$type])) {
                $map[$type] = ['parents' => $declared[$type], 'source' => 'valid_parent'];
                continue;
            }

            $parents = [];

            foreach ($children as $parent => $list) {
                if (isset($retired[$parent])) {
                    continue;
                }

                if (in_array($type, $list, true) || in_array('*', $list, true)) {
                    $parents[] = $parent;
                }
            }

            sort($parents);

            $map[$type] = ['parents' => $parents, 'source' => 'valid_children'];
        }

        return $map;
    }

    /**
     * @return string[]
     */
    private static function list(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $value), static fn (string $v): bool => $v !== ''));
    }
}
