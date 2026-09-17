<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Reads component exports out of a component document's flat element map.
 *
 * Mirrors Cornerstone's ComponentMap: the walk starts at `e0` and follows
 * `_modules`; an element is an export when `_c_export`, `_c_id` and `_label`
 * are all set; a slot is an element flagged `_c_slot` with an `_c_id` and a
 * label, and a component whose own element is a slot accepts children.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ComponentScanner
{
    /**
     * @param  array<string, mixed> $elements Flat element map keyed by element ID.
     * @return array{
     *     components: array<string, array{root: string, label: string, type: string, prefab: bool, element: array<string, mixed>, slots: array<string, string>, children: bool}>,
     *     duplicates: array<string, int>
     * }
     */
    public static function scan(array $elements): array
    {
        $components = [];
        $duplicates = [];
        $visited = [];

        $walk = function (string $id) use (&$walk, &$components, &$duplicates, &$visited, $elements): void {
            if (isset($visited[$id]) || ! isset($elements[$id]) || ! is_array($elements[$id])) {
                return;
            }

            $visited[$id] = true;
            $element = $elements[$id];

            if (self::isExport($element)) {
                $cid = trim((string) $element['_c_id']);

                if (isset($components[$cid])) {
                    $duplicates[$cid] = ($duplicates[$cid] ?? 1) + 1;
                }

                $slots = self::slots($id, $elements);

                $components[$cid] = [
                    'root'     => $id,
                    'label'    => (string) $element['_label'],
                    'type'     => (string) ($element['_type'] ?? ''),
                    'prefab'   => ! empty($element['_c_prefab']),
                    'element'  => $element,
                    'slots'    => $slots,
                    'children' => isset($slots[$id]),
                ];
            }

            $children = $element['_modules'] ?? [];

            if (is_array($children)) {
                foreach ($children as $child) {
                    if (is_string($child) || is_int($child)) {
                        $walk((string) $child);
                    }
                }
            }
        };

        $walk('e0');

        return ['components' => $components, 'duplicates' => $duplicates];
    }

    /**
     * @param array<string, mixed> $element
     */
    public static function isExport(array $element): bool
    {
        return ! empty($element['_c_export'])
            && isset($element['_c_id']) && is_scalar($element['_c_id']) && trim((string) $element['_c_id']) !== ''
            && ! empty($element['_label']);
    }

    /**
     * @param array<string, mixed> $element
     */
    public static function isSlot(array $element): bool
    {
        return ! empty($element['_c_slot'])
            && isset($element['_c_id']) && is_scalar($element['_c_id']) && trim((string) $element['_c_id']) !== ''
            && ! empty($element['_label']);
    }

    /**
     * Slots of a component: element ID => virtual component ID.
     *
     * The walk stops at the first slot on each branch, as Cornerstone's does.
     *
     * @param  array<string, mixed> $elements
     * @return array<string, string>
     */
    public static function slots(string $rootId, array $elements): array
    {
        $slots = [];
        $seen = [];

        $walk = function (string $id) use (&$walk, &$slots, &$seen, $elements): void {
            if (isset($seen[$id]) || ! isset($elements[$id]) || ! is_array($elements[$id])) {
                return;
            }

            $seen[$id] = true;
            $element = $elements[$id];

            if (self::isSlot($element)) {
                $slots[$id] = trim((string) $element['_c_id']);
                return;
            }

            foreach ((array) ($element['_modules'] ?? []) as $child) {
                if (is_string($child) || is_int($child)) {
                    $walk((string) $child);
                }
            }
        };

        $walk($rootId);

        return $slots;
    }

    /**
     * Normalize a parameter key the way Cornerstone does (`name#` and `name[]`
     * declare groups; the parameter itself is `name`).
     */
    public static function normalizeParameterKey(string $key): string
    {
        if (preg_match('/^([\w-]+)(#|\[\])?$/', $key, $matches)) {
            return $matches[1];
        }

        return $key;
    }

    /**
     * Decode a `_p_json` value. Returns null when it is absent or invalid.
     *
     * @return array<string, mixed>|null
     */
    public static function parameterTree(mixed $pJson): ?array
    {
        if (is_array($pJson)) {
            return $pJson;
        }

        if (! is_string($pJson) || trim($pJson) === '') {
            return null;
        }

        $decoded = json_decode($pJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Top-level parameter names a component declares, normalized.
     *
     * @return string[]|null Null when the component has no readable `_p_json`.
     */
    public static function declaredParameters(mixed $pJson): ?array
    {
        $tree = self::parameterTree($pJson);

        if ($tree === null) {
            return null;
        }

        $names = [];

        foreach (array_keys($tree) as $key) {
            $names[] = self::normalizeParameterKey((string) $key);
        }

        return array_values(array_unique($names));
    }
}
