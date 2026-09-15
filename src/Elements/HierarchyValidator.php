<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Validates Cornerstone layout data against element hierarchy rules and data format requirements.
 */
final class HierarchyValidator
{
    /** @var array<string, string[]> Cached hierarchy map. */
    private ?array $hierarchyMap = null;

    public function __construct(
        private readonly SchemaExtractor $schema,
    ) {}

    /**
     * Validate a complete layout data structure.
     *
     * @param mixed  $data    The layout data (tree or flat map).
     * @param string $context 'inline' for page/layout trees, 'flat' for global blocks.
     * @return ValidationResult
     */
    public function validate(mixed $data, string $context = 'inline'): ValidationResult
    {
        $errors = [];
        $warnings = [];

        if (! is_array($data)) {
            return new ValidationResult(false, ['Layout data must be an array.'], []);
        }

        if ($context === 'flat') {
            // Global blocks are a flat map, sometimes nested under `elements`.
            $elements = (isset($data['elements']) && is_array($data['elements']))
                ? $data['elements']
                : $data;

            $this->validateFlatMap($elements, $errors, $warnings);

            return new ValidationResult(empty($errors), $errors, $warnings);
        }

        // Pages store a bare element list, but headers, footers and layout
        // templates wrap their trees in named regions. Feeding an envelope
        // straight to validateTree() treats "regions" as an element and fails
        // every layout post type, so resolve the shape first.
        $regions = $this->extractRegions($data);

        if ($regions === null) {
            // An envelope we do not recognise. Never block a write on a shape
            // we cannot read — report it and let the caller decide.
            return new ValidationResult(true, [], [
                'Unrecognised layout envelope; structural validation was skipped.',
            ]);
        }

        foreach ($regions as $name => $tree) {
            $regionErrors = [];
            $regionWarnings = [];

            $this->validateTree($tree, $regionErrors, $regionWarnings, null);

            $prefix = $name === '' ? '' : sprintf('Region "%s": ', $name);

            foreach ($regionErrors as $error) {
                $errors[] = $prefix . $error;
            }

            foreach ($regionWarnings as $warning) {
                $warnings[] = $prefix . $warning;
            }
        }

        return new ValidationResult(empty($errors), $errors, $warnings);
    }

    /**
     * Resolve a stored document into the element lists it contains.
     *
     * Returns a map of region name (empty string for a bare tree) to element
     * list, or null when the shape is not recognised.
     *
     * @param  array<mixed> $data
     * @return array<string, array<int, mixed>>|null
     */
    private function extractRegions(array $data): ?array
    {
        // A bare element list — pages, and any region handed to us directly.
        if (array_is_list($data)) {
            return ['' => $data];
        }

        // Headers, footers, layout templates: { regions: { name: [...] } }.
        if (isset($data['regions']) && is_array($data['regions'])) {
            $regions = [];

            foreach ($data['regions'] as $name => $region) {
                if (! is_array($region)) {
                    continue;
                }

                if (array_is_list($region)) {
                    $regions[(string) $name] = $region;
                } elseif (isset($region['_modules']) && is_array($region['_modules'])) {
                    // The region is itself an element carrying children.
                    $regions[(string) $name] = [$region];
                } else {
                    $regions[(string) $name] = [$region];
                }
            }

            return $regions;
        }

        // A single element object.
        if (isset($data['_type'])) {
            return ['' => [$data]];
        }

        return null;
    }

    /**
     * Validate inline tree format (pages, layout regions).
     *
     * @param array<int, mixed> $elements
     * @param string[]          $errors
     * @param string[]          $warnings
     * @param string|null       $parentType
     */
    private function validateTree(array $elements, array &$errors, array &$warnings, ?string $parentType): void
    {
        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                $errors[] = sprintf('Element at index %d is not an array.', $index);
                continue;
            }

            $type = $element['_type'] ?? null;

            if ($type === null) {
                $errors[] = sprintf('Element at index %d is missing _type.', $index);
                continue;
            }

            // Check if element type exists in the registry.
            $def = $this->schema->getDefinition($type);
            if ($def === null) {
                $warnings[] = sprintf('Unknown element type "%s" at index %d (may be custom or deprecated).', $type, $index);
            }

            // Check parent-child relationship.
            if ($parentType !== null) {
                $this->validateParentChild($parentType, $type, $warnings);
            }

            // Validate _bp_data format.
            $this->validateBreakpointData($element, $type, $errors);

            // Recurse into children.
            if (isset($element['_modules']) && is_array($element['_modules'])) {
                $this->validateTree($element['_modules'], $errors, $warnings, $type);
            }
        }
    }

    /**
     * Validate flat map format (global blocks).
     *
     * @param array<string, mixed> $elements
     * @param string[]             $errors
     * @param string[]             $warnings
     */
    private function validateFlatMap(array $elements, array &$errors, array &$warnings): void
    {
        foreach ($elements as $id => $element) {
            if (! is_array($element)) {
                $errors[] = sprintf('Element "%s" is not an array.', $id);
                continue;
            }

            $type = $element['_type'] ?? null;

            if ($type === null) {
                $errors[] = sprintf('Element "%s" is missing _type.', $id);
                continue;
            }

            // Check element type.
            $def = $this->schema->getDefinition($type);
            if ($def === null) {
                $warnings[] = sprintf('Unknown element type "%s" for element "%s".', $type, $id);
            }

            // Check _id consistency.
            if (isset($element['_id']) && $element['_id'] !== $id) {
                $errors[] = sprintf('Element "%s" has mismatched _id "%s".', $id, $element['_id']);
            }

            // Check _parent references.
            if (isset($element['_parent']) && ! isset($elements[$element['_parent']])) {
                $errors[] = sprintf('Element "%s" references non-existent parent "%s".', $id, $element['_parent']);
            }

            // Check _modules references.
            if (isset($element['_modules']) && is_array($element['_modules'])) {
                foreach ($element['_modules'] as $childId) {
                    if (! isset($elements[$childId])) {
                        $errors[] = sprintf('Element "%s" references non-existent child "%s".', $id, $childId);
                    }
                }
            }

            // Validate _bp_data format.
            $this->validateBreakpointData($element, $type, $errors);
        }
    }

    /**
     * Validate parent-child relationship.
     */
    private function validateParentChild(string $parentType, string $childType, array &$warnings): void
    {
        $map = $this->getHierarchyMap();

        if (! isset($map[$parentType]) || empty($map[$parentType])) {
            return;
        }

        $validChildren = $map[$parentType];

        // Wildcard: any child is allowed.
        if ($validChildren === '*') {
            return;
        }

        // Array with wildcard entry.
        if (is_array($validChildren) && in_array('*', $validChildren, true)) {
            return;
        }

        // Ensure array for in_array check.
        if (is_string($validChildren)) {
            $validChildren = [$validChildren];
        }

        if (! in_array($childType, $validChildren, true)) {
            $warnings[] = sprintf(
                'Element type "%s" may not be a valid child of "%s" (valid: %s).',
                $childType,
                $parentType,
                implode(', ', $validChildren)
            );
        }
    }

    /**
     * Validate _bp_data values are sequential arrays with correct length.
     *
     * Critical: Sparse objects cause `TypeError: t[i] is not iterable` in Cornerstone's React app.
     *
     * @param array<string, mixed> $element
     * @param string               $type
     * @param string[]             $errors
     */
    private function validateBreakpointData(array $element, string $type, array &$errors): void
    {
        foreach ($element as $key => $value) {
            if (! str_starts_with($key, '_bp_data')) {
                continue;
            }

            if ($key === '_bp_base') {
                continue; // This is the breakpoint base string, not data.
            }

            if (! is_array($value)) {
                $errors[] = sprintf('Element "%s" has non-array %s value.', $type, $key);
                continue;
            }

            foreach ($value as $prop => $bpValues) {
                if (! is_array($bpValues)) {
                    continue;
                }

                // Check for sequential array (not associative/sparse).
                if (array_keys($bpValues) !== range(0, count($bpValues) - 1)) {
                    $errors[] = sprintf(
                        'Element "%s": %s.%s is a sparse object. Must be a sequential array (null-padded).',
                        $type,
                        $key,
                        $prop
                    );
                }
            }
        }
    }

    /**
     * Get cached hierarchy map.
     *
     * @return array<string, string[]>
     */
    private function getHierarchyMap(): array
    {
        if ($this->hierarchyMap === null) {
            $this->hierarchyMap = $this->schema->getHierarchyMap();
        }

        return $this->hierarchyMap;
    }
}
