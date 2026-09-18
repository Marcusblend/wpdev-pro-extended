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

        foreach ($registry as $group => $prefabs) {
            if (! is_string($group) || ! is_array($prefabs)) {
                continue;
            }

            foreach ($prefabs as $name => $prefab) {
                if (! is_string($name) || ! is_array($prefab)) {
                    continue;
                }

                $values = is_array($prefab['values'] ?? null) ? $prefab['values'] : [];

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

        $values = BuilderContext::read(static fn (): mixed => cs_prefab_element_values($group, $name));

        return is_array($values) && $values !== [] ? $values : null;
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
