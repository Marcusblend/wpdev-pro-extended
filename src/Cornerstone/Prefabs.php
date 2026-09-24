<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Cornerstone's prefab elements: ready-made element groups the builder's
 * library inserts, like a horizontal flex container or a link box.
 *
 * They are registered in code, not stored, so they can only be read from the
 * live registry. Knowing what a site has saves rebuilding by hand something the
 * builder already offers — and a prefab arrives with the values Cornerstone
 * gives it, which is a better starting point than an element with defaults.
 */
final class Prefabs
{
    private const SERVICE = 'ElementLibrary';

    /**
     * Prefab values as a read last found them, for the Cornerstone version it
     * found them on. A write takes its prefab from here and never from the
     * registry, which only answers inside builder context.
     */
    public const CACHE = 'pe_prefab_values';

    /**
     * Every prefab this site registers, by group.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function all(): array
    {
        $registry = $this->registry();

        if ($registry === null) {
            return [];
        }

        $groups = [];
        $found = [];

        foreach ($registry as $group => $prefabs) {
            if (! is_string($group) || ! is_array($prefabs)) {
                continue;
            }

            foreach ($prefabs as $name => $prefab) {
                if (! is_string($name) || ! is_array($prefab)) {
                    continue;
                }

                $values = is_array($prefab['values'] ?? null) ? $prefab['values'] : [];

                if ($values !== []) {
                    $found[$group][$name] = $values;
                }

                $groups[$group][] = [
                    'name'     => $name,
                    'group'    => $group,
                    'title'    => is_string($prefab['title'] ?? null) ? $prefab['title'] : $name,
                    'type'     => is_string($values['_type'] ?? null) ? $values['_type'] : null,
                    'children' => isset($values['_modules']) && is_array($values['_modules']) ? count($values['_modules']) : 0,
                    'keys'     => count($values),
                ];
            }
        }

        ksort($groups);

        $this->remember($found, true);

        return $groups;
    }

    /**
     * One prefab's element values, ready to insert.
     *
     * @return array<string, mixed>|null
     */
    public function values(string $group, string $name): ?array
    {
        if (! function_exists('cs_prefab_element_values')) {
            return null;
        }

        // get_library() is what loads Cornerstone's prefab files; asking for a
        // prefab's values without it finds nothing, whatever the name.
        $this->library();

        $values = BuilderContext::read(static fn (): mixed => cs_prefab_element_values($group, $name));

        if (! is_array($values) || $values === []) {
            return null;
        }

        $this->remember([$group => [$name => $values]], false);

        return $values;
    }

    /**
     * One prefab's values as the last read found them, without asking the
     * registry.
     *
     * This is what a write uses. The registry only answers inside builder
     * context, and entering it (firing cs_before_late_data) marks the whole
     * request as a builder request, which a save must never be. So a write
     * takes what list_prefabs, a read, has already cached, the way the css
     * lint takes only an already-cached control surface.
     *
     * @return array<string, mixed>|null Null when this prefab has not been read on this Cornerstone version.
     */
    public function cached(string $group, string $name): ?array
    {
        $values = $this->cache()['prefabs'][$group][$name] ?? null;

        return is_array($values) && $values !== [] ? $values : null;
    }

    /**
     * Whether the cache holds the whole registry for this Cornerstone version,
     * so a prefab missing from it does not exist rather than was never read.
     */
    public function cachedAll(): bool
    {
        return ($this->cache()['complete'] ?? false) === true;
    }

    /**
     * @return array{version: string, complete: bool, prefabs: array<string, array<string, array<string, mixed>>>}|array{}
     */
    private function cache(): array
    {
        $cached = get_transient(self::CACHE);

        if (! is_array($cached) || ($cached['version'] ?? null) !== self::version() || ! is_array($cached['prefabs'] ?? null)) {
            return [];
        }

        return $cached;
    }

    /**
     * Keep what a read found. The whole registry replaces the cache; one
     * prefab is added to it.
     *
     * @param array<string, array<string, array<string, mixed>>> $prefabs
     */
    private function remember(array $prefabs, bool $complete): void
    {
        try {
            $current = $this->cache();

            if ($complete) {
                $next = ['version' => self::version(), 'complete' => true, 'prefabs' => $prefabs];
            } else {
                $next = $current !== [] ? $current : ['version' => self::version(), 'complete' => false, 'prefabs' => []];

                foreach ($prefabs as $group => $named) {
                    foreach ($named as $name => $values) {
                        $next['prefabs'][$group][$name] = $values;
                    }
                }
            }

            if ($next !== $current) {
                set_transient(self::CACHE, $next, DAY_IN_SECONDS);
            }
        } catch (\Throwable) {
            // A cache that cannot be written costs a later write a refusal,
            // never this read.
        }
    }

    private static function version(): string
    {
        return defined('CS_VERSION') ? (string) constant('CS_VERSION') : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function registry(): ?array
    {
        $library = $this->library();

        return is_array($library['prefabs'] ?? null) ? $library['prefabs'] : null;
    }

    /**
     * Cornerstone's element library, which loads its prefab files on demand.
     *
     * @return array<string, mixed>
     */
    private function library(): array
    {
        return BuilderContext::read(static function (): array {
            $service = cornerstone(self::SERVICE);

            if (! is_object($service) || ! method_exists($service, 'get_library')) {
                return [];
            }

            $library = $service->get_library();

            return is_array($library) ? $library : [];
        }, []);
    }

    /**
     * The library's group titles, so a listing can name them.
     *
     * @return array<string, string>
     */
    public function groups(): array
    {
        $library = $this->library();
        $groups = is_array($library['groups'] ?? null) ? $library['groups'] : [];
        $clean = [];

        foreach ($groups as $name => $title) {
            if (is_string($name) && is_scalar($title)) {
                $clean[$name] = (string) $title;
            }
        }

        return $clean;
    }
}
