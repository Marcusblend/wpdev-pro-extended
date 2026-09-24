<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Element control surfaces kept until Cornerstone or Pro Extended changes.
 *
 * The css-over-control and literal-color lints need an element type's control
 * surface, and they run inside the validation every write performs, where
 * building one would enter builder context mid-save. So they only read one
 * that a read path already built. Those used to sit in one-hour transients,
 * which made the lints depend on timing: the same layout warned or did not
 * depending on whether get_element_schema had run for that type within the
 * hour. Here each surface is kept in its own non-autoloaded option with no
 * expiry, under an index that records the Cornerstone and Pro Extended
 * versions it was built with. When either version changes the index no
 * longer matches and everything reads as missing until it is warmed again
 * (get_element_schema, clear_cache with elements, or `wp pe warm`).
 *
 * One option per type rather than one for all: a full set runs to megabytes,
 * and a validation only needs the handful of types its layout uses.
 *
 * The option functions are injected, so this is unit-tested without a site.
 */
final class SurfaceStore
{
    public const INDEX_OPTION = 'pe_surfaces';
    public const SURFACE_PREFIX = 'pe_surface_';

    /** @var array{cornerstone: string, plugin: string, types: array<string, string>}|null */
    private ?array $index = null;

    /**
     * @param \Closure(string, mixed): mixed      $get    get_option()
     * @param \Closure(string, mixed, bool): mixed $set   update_option() with autoload
     * @param \Closure(string): mixed              $delete delete_option()
     */
    public function __construct(
        private readonly string $cornerstoneVersion,
        private readonly string $pluginVersion,
        private readonly \Closure $get,
        private readonly \Closure $set,
        private readonly \Closure $delete,
    ) {}

    /**
     * The store for this site, keyed by CS_VERSION and PE_VERSION.
     */
    public static function forSite(): self
    {
        return new self(
            defined('CS_VERSION') ? (string) constant('CS_VERSION') : '',
            defined('PE_VERSION') ? (string) constant('PE_VERSION') : '',
            static fn(string $name, mixed $default): mixed => get_option($name, $default),
            static fn(string $name, mixed $value, bool $autoload): mixed => update_option($name, $value, $autoload),
            static fn(string $name): mixed => delete_option($name),
        );
    }

    /**
     * The stored surface for a type, or null when there is none for these versions.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $type): ?array
    {
        $name = $this->index()['types'][$type] ?? null;

        if ($name === null) {
            return null;
        }

        $surface = ($this->get)($name, null);

        return is_array($surface) && isset($surface['controls']) && is_array($surface['controls']) ? $surface : null;
    }

    /**
     * Keep a surface. A stored set from other versions is dropped first.
     *
     * @param array<string, mixed> $surface
     */
    public function put(string $type, array $surface): void
    {
        $index = $this->index();

        if ($index['types'] === [] && $this->storedIndex() !== null && ! $this->matches($this->storedIndex())) {
            $this->clear();
            $index = $this->index();
        }

        $name = self::SURFACE_PREFIX . md5($type);
        ($this->set)($name, $surface, false);

        $index['types'][$type] = $name;
        ($this->set)(self::INDEX_OPTION, $index, false);
        $this->index = $index;
    }

    /**
     * Element types stored for these versions.
     *
     * @return string[]
     */
    public function types(): array
    {
        return array_keys($this->index()['types']);
    }

    /**
     * Delete every stored surface and the index, whatever versions built them.
     *
     * @return int How many surfaces were deleted.
     */
    public function clear(): int
    {
        $stored = $this->storedIndex();
        $count = 0;

        foreach (is_array($stored['types'] ?? null) ? $stored['types'] : [] as $name) {
            if (is_string($name) && str_starts_with($name, self::SURFACE_PREFIX)) {
                ($this->delete)($name);
                $count++;
            }
        }

        ($this->delete)(self::INDEX_OPTION);
        $this->index = null;

        return $count;
    }

    public function cornerstoneVersion(): string
    {
        return $this->cornerstoneVersion;
    }

    /**
     * The index for these versions: what is stored when it matches, empty otherwise.
     *
     * @return array{cornerstone: string, plugin: string, types: array<string, string>}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $stored = $this->storedIndex();

        if ($stored !== null && $this->matches($stored)) {
            $types = [];

            foreach ((array) $stored['types'] as $type => $name) {
                if (is_string($type) && is_string($name)) {
                    $types[$type] = $name;
                }
            }

            return $this->index = ['cornerstone' => $this->cornerstoneVersion, 'plugin' => $this->pluginVersion, 'types' => $types];
        }

        return $this->index = ['cornerstone' => $this->cornerstoneVersion, 'plugin' => $this->pluginVersion, 'types' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedIndex(): ?array
    {
        $stored = ($this->get)(self::INDEX_OPTION, null);

        return is_array($stored) ? $stored : null;
    }

    /**
     * @param array<string, mixed> $index
     */
    private function matches(array $index): bool
    {
        return ($index['cornerstone'] ?? null) === $this->cornerstoneVersion
            && ($index['plugin'] ?? null) === $this->pluginVersion
            && is_array($index['types'] ?? null);
    }
}
