<?php

declare(strict_types=1);

namespace ProExtended\Layouts;

/**
 * Outlines and subtrees of layout data, for reading large documents in parts.
 *
 * Paths use update_layout's dot format ("0._modules.1",
 * "regions.top.0._modules.2"); in flat component maps a path is an element ID.
 */
final class LayoutOutline
{
    private const NODE_KEYS = ['_label', '_region', 'component_id', '_c_id'];

    /**
     * An outline of the data, or of the subtree at $path.
     *
     * @return array{nodes: array<int, array<string, mixed>>, truncated: bool}
     *
     * @throws \InvalidArgumentException
     */
    public static function outline(mixed $data, int $maxDepth = 4, ?string $path = null): array
    {
        $nodes = [];
        $truncated = false;
        $maxDepth = max(1, $maxDepth);

        if (! is_array($data)) {
            return ['nodes' => [], 'truncated' => false];
        }

        if (self::isFlat($data) && ($path === null || $path === '' || isset(self::flatElements($data)[$path]))) {
            $elements = self::flatElements($data);
            self::walkFlat($elements, $path === null || $path === '' ? 'e0' : $path, 1, $maxDepth, $nodes, $truncated, []);

            return ['nodes' => $nodes, 'truncated' => $truncated];
        }

        if ($path !== null && $path !== '') {
            self::walkAny(self::resolve($data, $path), $path, 1, $maxDepth, $nodes, $truncated);

            return ['nodes' => $nodes, 'truncated' => $truncated];
        }

        if (isset($data['regions']) && is_array($data['regions'])) {
            self::walkAny($data['regions'], 'regions', 1, $maxDepth, $nodes, $truncated);

            return ['nodes' => $nodes, 'truncated' => $truncated];
        }

        self::walkAny($data, '', 1, $maxDepth, $nodes, $truncated);

        return ['nodes' => $nodes, 'truncated' => $truncated];
    }

    /**
     * The number of elements in a document (flat maps: every element except
     * the root and region placeholders; trees: every element at any depth).
     */
    public static function countElements(mixed $data): int
    {
        if (! is_array($data)) {
            return 0;
        }

        if (self::isFlat($data)) {
            $count = 0;

            foreach (self::flatElements($data) as $element) {
                if (is_array($element) && isset($element['_type']) && ! in_array($element['_type'], ['root', 'region'], true)) {
                    $count++;
                }
            }

            return $count;
        }

        $count = 0;
        $walk = static function (mixed $value) use (&$walk, &$count): void {
            if (! is_array($value)) {
                return;
            }

            if (isset($value['_type'])) {
                $count++;
                $value = is_array($value['_modules'] ?? null) ? $value['_modules'] : [];
            }

            foreach ($value as $item) {
                $walk($item);
            }
        };

        $walk(isset($data['regions']) && is_array($data['regions']) ? $data['regions'] : $data);

        return $count;
    }

    /**
     * The value at $path. In flat maps an element ID returns that element and
     * its descendants as a flat map.
     *
     * @throws \InvalidArgumentException
     */
    public static function subtree(mixed $data, string $path): mixed
    {
        if (is_array($data) && self::isFlat($data)) {
            $elements = self::flatElements($data);

            if (isset($elements[$path])) {
                $collected = [];
                self::collectFlat($elements, $path, $collected);

                return ['root' => $path, 'elements' => $collected];
            }
        }

        return self::resolve($data, $path);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function resolve(mixed $data, string $path): mixed
    {
        $current = $data;

        foreach (array_filter(explode('.', $path), static fn(string $s): bool => $s !== '') as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;

            if (! is_array($current) || ! array_key_exists($key, $current)) {
                throw new \InvalidArgumentException(sprintf('Path segment "%s" of "%s" was not found.', $segment, $path));
            }

            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param array<mixed> $data
     */
    public static function isFlat(array $data): bool
    {
        return (isset($data['elements']) && is_array($data['elements']) && isset($data['elements']['e0']))
            || (isset($data['e0']) && is_array($data['e0']));
    }

    /**
     * @param  array<mixed> $data
     * @return array<string, mixed>
     */
    private static function flatElements(array $data): array
    {
        return isset($data['elements']) && is_array($data['elements']) ? $data['elements'] : $data;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private static function walkAny(mixed $value, string $path, int $depth, int $maxDepth, array &$nodes, bool &$truncated): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['_type'])) {
            self::walkElement($value, $path, $depth, $maxDepth, $nodes, $truncated);
            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $i => $element) {
                self::walkElement($element, self::join($path, (string) $i), $depth, $maxDepth, $nodes, $truncated);
            }
            return;
        }

        // A map of regions (or another keyed group of element lists).
        foreach ($value as $key => $list) {
            $childPath = self::join($path, (string) $key);
            $count = is_array($list) ? count($list) : 0;
            $nodes[] = ['path' => $childPath, '_type' => 'region', 'children' => $count];

            if (! is_array($list) || $count === 0) {
                continue;
            }

            if ($depth >= $maxDepth) {
                $truncated = true;
                continue;
            }

            foreach (array_values($list) as $i => $element) {
                self::walkElement($element, self::join($childPath, (string) $i), $depth + 1, $maxDepth, $nodes, $truncated);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private static function walkElement(mixed $element, string $path, int $depth, int $maxDepth, array &$nodes, bool &$truncated): void
    {
        if (! is_array($element)) {
            return;
        }

        $children = isset($element['_modules']) && is_array($element['_modules']) ? array_values($element['_modules']) : [];
        $nodes[] = self::node($element, $path, count($children));

        if ($children === []) {
            return;
        }

        if ($depth >= $maxDepth) {
            $truncated = true;
            return;
        }

        foreach ($children as $i => $child) {
            self::walkElement($child, self::join($path, '_modules.' . $i), $depth + 1, $maxDepth, $nodes, $truncated);
        }
    }

    /**
     * @param array<string, mixed>             $elements
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, true>              $seen
     */
    private static function walkFlat(array $elements, string $id, int $depth, int $maxDepth, array &$nodes, bool &$truncated, array $seen): void
    {
        if (isset($seen[$id]) || ! isset($elements[$id]) || ! is_array($elements[$id])) {
            return;
        }

        $seen[$id] = true;
        $element = $elements[$id];
        $children = isset($element['_modules']) && is_array($element['_modules']) ? array_values($element['_modules']) : [];
        $nodes[] = self::node($element, $id, count($children));

        if ($children === []) {
            return;
        }

        if ($depth >= $maxDepth) {
            $truncated = true;
            return;
        }

        foreach ($children as $child) {
            if (is_string($child) || is_int($child)) {
                self::walkFlat($elements, (string) $child, $depth + 1, $maxDepth, $nodes, $truncated, $seen);
            }
        }
    }

    /**
     * @param array<string, mixed> $elements
     * @param array<string, mixed> $collected
     */
    private static function collectFlat(array $elements, string $id, array &$collected): void
    {
        if (isset($collected[$id]) || ! isset($elements[$id]) || ! is_array($elements[$id])) {
            return;
        }

        $collected[$id] = $elements[$id];

        foreach ((array) ($elements[$id]['_modules'] ?? []) as $child) {
            if (is_string($child) || is_int($child)) {
                self::collectFlat($elements, (string) $child, $collected);
            }
        }
    }

    /**
     * @param  array<string, mixed> $element
     * @return array<string, mixed>
     */
    private static function node(array $element, string $path, int $children): array
    {
        $node = ['path' => $path, '_type' => $element['_type'] ?? null];

        foreach (self::NODE_KEYS as $key) {
            if (isset($element[$key]) && is_scalar($element[$key]) && $element[$key] !== '') {
                $node[$key] = $element[$key];
            }
        }

        $node['children'] = $children;

        return $node;
    }

    private static function join(string $prefix, string $segment): string
    {
        return $prefix === '' ? $segment : $prefix . '.' . $segment;
    }
}
