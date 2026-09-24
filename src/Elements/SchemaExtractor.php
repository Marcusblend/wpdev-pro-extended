<?php

declare(strict_types=1);

namespace ProExtended\Elements;

use ProExtended\Cornerstone\BuilderContext;

/**
 * Extract element definitions and schemas from Cornerstone's registry.
 *
 * Wraps `cornerstone('Elements')` and caches results for performance.
 */
final class SchemaExtractor
{
    private const CACHE_KEY = 'pe_element_definitions';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Transients earlier versions kept surfaces in, one per type plus an
     * index. Only cleared now; surfaces live in SurfaceStore.
     */
    private const LEGACY_SURFACE_INDEX = 'pe_element_surface_index';

    /** @var array<string, mixed>|null */
    private static ?array $inspector = null;

    private ?SurfaceStore $surfaces = null;

    public function __construct(?SurfaceStore $surfaces = null)
    {
        $this->surfaces = $surfaces;
    }

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
     * The control surface for a type, but only if one is already stored.
     *
     * Building one reaches into Cornerstone's inspector data, which means
     * entering builder context and firing `cs_before_late_data`. That is a read
     * path's business: doing it inside a save marks the save request as a
     * builder request, which is what BuilderContext promises never to do. The
     * lints take this instead. What they get no longer depends on timing:
     * a stored surface lasts until Cornerstone or Pro Extended changes version
     * (see SurfaceStore), so the same layout gives the same warnings every run.
     *
     * @return array<string, mixed>|null
     */
    public function getCachedSurface(string $type): ?array
    {
        return $this->surfaces()->get($type);
    }

    /**
     * The settings an element type really has, grouped the way the builder's
     * Inspector groups them. Built on a miss and stored, so a read path warms
     * the lints as a side effect.
     *
     * @return array{panels: array<int, array<string, string>>, controls: array<int, array<string, mixed>>}
     */
    public function getSurface(string $type): array
    {
        $stored = $this->surfaces()->get($type);

        if (is_array($stored)) {
            return $stored;
        }

        return $this->buildAndStore($type) ?? ['panels' => [], 'controls' => []];
    }

    /**
     * Build and store the surface of every element type, on a read path.
     *
     * For `wp pe warm` and clear_cache (elements). Types the Inspector returns
     * nothing for are reported rather than stored, so a failed read cannot
     * pin an empty surface until the next version change.
     *
     * @return array{cornerstone: string, stored: string[], empty: string[]}
     */
    public function warmSurfaces(): array
    {
        $stored = [];
        $empty = [];

        foreach ($this->getAllDefinitions() as $definition) {
            $type = is_array($definition) ? (string) ($definition['id'] ?? $definition['name'] ?? '') : '';

            if ($type === '') {
                continue;
            }

            $surface = $this->buildAndStore($type);

            if ($surface === null || $surface['controls'] === []) {
                $empty[] = $type;
            } else {
                $stored[] = $type;
            }
        }

        return [
            'cornerstone' => $this->surfaces()->cornerstoneVersion(),
            'stored'      => $stored,
            'empty'       => $empty,
        ];
    }

    /**
     * @return array{panels: array<int, array<string, string>>, controls: array<int, array<string, mixed>>}|null
     */
    private function buildAndStore(string $type): ?array
    {
        $inspector = $this->inspectorData()[$type] ?? null;

        if (! is_array($inspector)) {
            return null;
        }

        $surface = ControlSurface::build($inspector, $this->getDesignations($type), $this->getDefaults($type));

        if ($surface['controls'] === []) {
            return $surface;
        }

        try {
            $this->surfaces()->put($type, $surface);
        } catch (\Throwable) {
            // A surface that will not store still answers this request.
        }

        return $surface;
    }

    private function surfaces(): SurfaceStore
    {
        return $this->surfaces ??= SurfaceStore::forSite();
    }

    /**
     * Which flat keys are style and which are markup, as the element declares.
     *
     * @return array<string, string>
     */
    public function getDesignations(string $type): array
    {
        if (! function_exists('cornerstone')) {
            return [];
        }

        try {
            $element = cornerstone('Elements')->get_element($type);
        } catch (\Throwable) {
            return [];
        }

        if (! is_object($element) || ! method_exists($element, 'get_designations')) {
            return [];
        }

        $designations = $element->get_designations();

        if (! is_array($designations)) {
            return [];
        }

        $clean = [];

        foreach ($designations as $key => $designation) {
            if (is_string($key) && is_string($designation)) {
                $clean[$key] = $designation;
            }
        }

        return $clean;
    }

    /**
     * Cornerstone's Inspector data for every element.
     *
     * The control definitions are assembled with cs_recall, which returns a
     * fallback and raises a warning unless the request is a builder one. The
     * builder marks itself by firing cs_before_late_data, so this fires it too
     * — once per request, on this read path only, never while saving — and
     * quiets the warnings that Cornerstone raises while it decides.
     *
     * @return array<string, mixed>
     */
    private function inspectorData(): array
    {
        if (self::$inspector !== null) {
            return self::$inspector;
        }

        if (! function_exists('cornerstone')) {
            return self::$inspector = [];
        }

        $elements = cornerstone('Elements');

        if (! method_exists($elements, 'get_element_inspector_data')) {
            return self::$inspector = [];
        }

        $data = BuilderContext::read(static fn (): mixed => $elements->get_element_inspector_data(), []);

        return self::$inspector = is_array($data) ? $data : [];
    }

    /**
     * Clear the element definitions cache and every stored control surface.
     *
     * @return int How many stored surfaces were deleted.
     */
    public function clearCache(): int
    {
        delete_transient(self::CACHE_KEY);

        // Surfaces from before they moved to SurfaceStore.
        $legacy = get_transient(self::LEGACY_SURFACE_INDEX);

        foreach (is_array($legacy) ? $legacy : [] as $key) {
            if (is_string($key)) {
                delete_transient($key);
            }
        }

        delete_transient(self::LEGACY_SURFACE_INDEX);

        self::$inspector = null;

        return $this->surfaces()->clear();
    }
}
