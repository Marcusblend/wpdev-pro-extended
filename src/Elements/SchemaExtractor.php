<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Extract element definitions and schemas from Cornerstone's registry.
 *
 * Wraps `cornerstone('Elements')` and caches results for performance.
 */
final class SchemaExtractor
{
    private const CACHE_KEY = 'pe_element_definitions';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /** Stand-in for values that cannot survive serialization. */
    private const UNSERIALIZABLE = '__unserializable_closure__';

    /**
     * Get all element definitions (serialized).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllDefinitions(): array
    {
        $cached = get_transient(self::CACHE_KEY);

        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        if (! function_exists('cornerstone')) {
            return [];
        }

        $elements = cornerstone('Elements');

        // Strip before caching *and* before returning: a cache hit would
        // otherwise hand back a different shape than a cache miss, and a
        // Closure encodes as null in the JSON-RPC response either way.
        $definitions = $this->stripUnserializable($elements->get_element_definitions());

        try {
            set_transient(self::CACHE_KEY, $definitions, self::CACHE_TTL);
        } catch (\Throwable) {
            // Something in the definitions still refuses to serialize. Skip the
            // cache rather than taking down every tool that reads the schema.
        }

        return $definitions;
    }

    /**
     * Recursively replace closures with a placeholder.
     *
     * Cornerstone element definitions can carry Closures for dynamic behaviour.
     * `set_transient()` serializes its value, and PHP throws
     * "Serialization of 'Closure' is not allowed" — which took out every tool
     * reading the element schema (`list_elements`, `get_element_schema`,
     * `validate_layout`, `deploy_layout`, and both schema resources).
     *
     * @see https://github.com/renandadalte/wpdev-pro-extended/issues/2
     */
    private function stripUnserializable(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return self::UNSERIALIZABLE;
        }

        if (is_array($value)) {
            return array_map([$this, 'stripUnserializable'], $value);
        }

        return $value;
    }

    /**
     * Get summarized list of element types (lightweight, for list_elements tool).
     *
     * @return array<int, array{type: string, title: string, group: string, valid_children: string[]}>
     */
    public function getElementList(): array
    {
        $definitions = $this->getAllDefinitions();
        $list = [];

        foreach ($definitions as $def) {
            $type = $def['id'] ?? $def['name'] ?? '';
            $title = $def['title'] ?? $type;
            $group = $def['group'] ?? 'unknown';

            $validChildren = [];
            if (isset($def['options']['valid_children'])) {
                $validChildren = $def['options']['valid_children'];
            }

            $list[] = [
                'type'           => $type,
                'title'          => $title,
                'group'          => $group,
                'valid_children' => $validChildren,
            ];
        }

        return $list;
    }

    /**
     * Get full schema for a specific element type.
     *
     * @return array<string, mixed>|null
     */
    public function getDefinition(string $type): ?array
    {
        $definitions = $this->getAllDefinitions();

        foreach ($definitions as $def) {
            $id = $def['id'] ?? $def['name'] ?? '';
            if ($id === $type) {
                return $def;
            }
        }

        return null;
    }

    /**
     * Get valid child element types for a given parent type.
     *
     * @return string[]
     */
    public function getValidChildren(string $type): array
    {
        $def = $this->getDefinition($type);

        if ($def === null) {
            return [];
        }

        return $def['options']['valid_children'] ?? [];
    }

    /**
     * Get the element hierarchy map (parent → children).
     *
     * @return array<string, string[]>
     */
    public function getHierarchyMap(): array
    {
        $definitions = $this->getAllDefinitions();
        $map = [];

        foreach ($definitions as $def) {
            $type = $def['id'] ?? $def['name'] ?? '';
            $validChildren = $def['options']['valid_children'] ?? [];

            if (! empty($validChildren)) {
                $map[$type] = $validChildren;
            }
        }

        return $map;
    }

    /**
     * Get all default values for an element type.
     *
     * @return array<string, mixed>
     */
    public function getDefaults(string $type): array
    {
        $def = $this->getDefinition($type);

        if ($def === null) {
            return [];
        }

        $defaults = [];

        if (isset($def['values']) && is_array($def['values'])) {
            foreach ($def['values'] as $key => $valueDef) {
                // array_key_exists(), not isset(): a property whose default is
                // null is a real default and must not be dropped.
                if (is_array($valueDef) && array_key_exists(0, $valueDef)) {
                    $defaults[$key] = $valueDef[0]; // First element is the default.
                }
            }
        }

        return $defaults;
    }

    /**
     * Clear the element definitions cache.
     */
    public function clearCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }
}
