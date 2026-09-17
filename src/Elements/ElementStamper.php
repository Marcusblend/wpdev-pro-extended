<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Adds the markers Cornerstone gives every element it creates.
 *
 * - `_m` (`{"e": N}`): the element's migration version. Cornerstone treats
 *   an element of a type with base migrations as legacy content when the
 *   marker is missing, and fills every unset key with its old default (a bar
 *   becomes 96px tall, a nav in a header bar turns into a toggle).
 * - `_bp_base` (for example "4_4"): the breakpoint set the element's
 *   responsive data was written for. Without it Cornerstone runs its pre-6.0
 *   migration, which rewrites grid, row and cell layouts.
 *
 * Only missing markers are added; existing values are never changed. Pure
 * PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ElementStamper
{
    /** Structural types that carry no styles. */
    private const SKIP_TYPES = ['root', 'region'];

    /** @var array{elements: int, _m: int, _bp_base: int} */
    private array $counts = ['elements' => 0, '_m' => 0, '_bp_base' => 0];

    /**
     * @param array<string, int> $versions Element type => latest migration version (types without migrations may be left out).
     * @param string             $bpBase   The site's breakpoint tag, for example "4_4".
     * @param bool               $dryRun   Count what would be stamped without changing anything.
     */
    public function __construct(
        private readonly array $versions,
        private readonly string $bpBase,
        private readonly bool $dryRun = false,
    ) {}

    /**
     * Elements visited and markers added (or, in a dry run, missing).
     *
     * @return array{elements: int, _m: int, _bp_base: int}
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Stamp layout data in any storage shape: a page's element list, a
     * layout's {"regions": ...} envelope, or a component document's flat map
     * (bare or inside {"elements": ...}).
     *
     * @param  array<mixed> $data
     * @return array<mixed>
     */
    public function stampData(array $data, bool $flat): array
    {
        if ($flat) {
            if (isset($data['elements']) && is_array($data['elements'])) {
                $data['elements'] = $this->stampFlat($data['elements']);

                return $data;
            }

            return $this->stampFlat($data);
        }

        if (array_is_list($data)) {
            return $this->stampTree($data);
        }

        if (isset($data['regions']) && is_array($data['regions'])) {
            $data['regions'] = $this->stampRegions($data['regions']);

            return $data;
        }

        return isset($data['_type']) ? $this->stampElement($data) : $data;
    }

    /**
     * Stamp a list of elements and their children (pages, a layout region).
     *
     * @param  array<int|string, mixed> $elements
     * @return array<int|string, mixed>
     */
    public function stampTree(array $elements): array
    {
        foreach ($elements as $index => $element) {
            if (is_array($element)) {
                $elements[$index] = $this->stampElement($element);
            }
        }

        return $elements;
    }

    /**
     * Stamp the regions of a header, footer or layout ({region: [elements]}).
     *
     * @param  array<string, mixed> $regions
     * @return array<string, mixed>
     */
    public function stampRegions(array $regions): array
    {
        foreach ($regions as $name => $region) {
            if (is_array($region)) {
                $regions[$name] = $this->stampTree($region);
            }
        }

        return $regions;
    }

    /**
     * Stamp a component document's flat element map ({"e0": ..., "e1": ...}).
     * Children there are ID strings, so each element is stamped on its own.
     *
     * @param  array<string, mixed> $map
     * @return array<string, mixed>
     */
    public function stampFlat(array $map): array
    {
        foreach ($map as $id => $element) {
            if (is_array($element)) {
                $map[$id] = $this->stampOne($element);
            }
        }

        return $map;
    }

    /**
     * Stamp one element and every nested element in its `_modules`.
     *
     * @param  array<string, mixed> $element
     * @return array<string, mixed>
     */
    public function stampElement(array $element): array
    {
        $element = $this->stampOne($element);

        if (isset($element['_modules']) && is_array($element['_modules'])) {
            $element['_modules'] = $this->stampTree($element['_modules']);
        }

        return $element;
    }

    /**
     * The breakpoint tag an element's responsive data was written for: its
     * single `_bp_data<tag>` key when it has exactly one, otherwise null.
     *
     * @param array<string, mixed> $element
     */
    public static function dataTag(array $element): ?string
    {
        $tags = [];

        foreach (array_keys($element) as $key) {
            if (is_string($key) && preg_match('/^_bp_data(\d+_\d+)$/', $key, $match)) {
                $tags[] = $match[1];
            }
        }

        return count($tags) === 1 ? $tags[0] : null;
    }

    /**
     * @param  array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function stampOne(array $element): array
    {
        $type = $element['_type'] ?? null;

        if (! is_string($type) || $type === '' || in_array($type, self::SKIP_TYPES, true)) {
            return $element;
        }

        $this->counts['elements']++;
        $version = (int) ($this->versions[$type] ?? 0);

        if ($version > 0) {
            $marker = $element['_m'] ?? null;

            if (! is_array($marker) || ! array_key_exists('e', $marker)) {
                $this->counts['_m']++;

                if (! $this->dryRun) {
                    $element['_m'] = (is_array($marker) ? $marker : []) + ['e' => $version];
                }
            }
        }

        if (! array_key_exists('_bp_base', $element)) {
            $this->counts['_bp_base']++;

            if (! $this->dryRun) {
                // Responsive data written for another breakpoint set keeps its
                // own tag, so Cornerstone converts it instead of misreading it.
                $element['_bp_base'] = self::dataTag($element) ?? $this->bpBase;
            }
        }

        return $element;
    }
}
