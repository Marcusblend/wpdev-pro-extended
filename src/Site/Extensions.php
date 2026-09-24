<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * What each plugin on a site adds to Cornerstone's registries.
 *
 * Nothing here is a hand-kept list. The caller reads the live registries —
 * element definitions, Dynamic Content groups, looper providers — and hands
 * over, for each entry, the PHP file its code lives in (found by reflecting
 * the entry's own callbacks: an element's render or builder callback, a
 * group's value filter, a provider's class or filter). This works out which
 * plugin, theme or Cornerstone itself that file belongs to and groups the
 * entries by it. An entry whose callbacks cannot be reflected is reported
 * under "unknown" rather than guessed at.
 *
 * It writes nothing and never reports a package URL, licence or key.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class Extensions
{
    public const CORNERSTONE = 'cornerstone';
    public const UNKNOWN = 'unknown';

    /** The registries reported, in order. */
    public const REGISTRIES = ['elements', 'dynamic_content', 'loopers'];

    /**
     * Who a PHP file belongs to.
     *
     * @param array{cornerstone?: string|null, plugins?: string|null, mu_plugins?: string|null, template?: string|null, stylesheet?: string|null} $roots
     *        Cornerstone's root (CS_ROOT_PATH), the plugin and must-use
     *        plugin directories, and the parent and child theme directories.
     * @return string "cornerstone", "plugin:<folder>", "mu-plugin:<file>",
     *                "child-theme:<folder>", "theme:<folder>" or "unknown".
     */
    public static function sourceOf(?string $file, array $roots): string
    {
        if ($file === null || $file === '') {
            return self::UNKNOWN;
        }

        $file = self::path($file);

        // Cornerstone first: it lives inside the Pro theme, or in the plugins
        // directory when it runs standalone.
        $cornerstone = self::root($roots['cornerstone'] ?? null);

        if ($cornerstone !== null && str_starts_with($file, $cornerstone)) {
            return self::CORNERSTONE;
        }

        foreach (['mu_plugins' => 'mu-plugin', 'plugins' => 'plugin'] as $key => $label) {
            $root = self::root($roots[$key] ?? null);

            if ($root !== null && str_starts_with($file, $root)) {
                $first = explode('/', substr($file, strlen($root)))[0];

                return $label . ':' . $first;
            }
        }

        $stylesheet = self::root($roots['stylesheet'] ?? null);
        $template = self::root($roots['template'] ?? null);

        if ($stylesheet !== null && $stylesheet !== $template && str_starts_with($file, $stylesheet)) {
            return 'child-theme:' . basename(rtrim($stylesheet, '/'));
        }

        if ($template !== null && str_starts_with($file, $template)) {
            return 'theme:' . basename(rtrim($template, '/'));
        }

        return self::UNKNOWN;
    }

    /**
     * Group the registries' entries by who registered them.
     *
     * @param  array<string, array<string, string|null>|null> $registries
     *         Registry name => [entry name => file its code lives in], or
     *         null when the registry could not be read.
     * @param  array<string, mixed> $roots   See sourceOf().
     * @param  array<string, array<string, mixed>> $plugins get_plugins(): "folder/file.php" => header.
     * @return array{sources: array<string, array<string, mixed>>, counts: array<string, int>, unreadable: string[]}
     */
    public static function describe(array $registries, array $roots, array $plugins = []): array
    {
        $sources = [];
        $counts = [];
        $unreadable = [];

        foreach (self::REGISTRIES as $registry) {
            $entries = $registries[$registry] ?? null;

            if (! is_array($entries)) {
                $unreadable[] = $registry;
                $counts[$registry] = 0;
                continue;
            }

            $counts[$registry] = count($entries);

            foreach ($entries as $name => $file) {
                $source = self::sourceOf(is_string($file) ? $file : null, $roots);
                $sources[$source] ??= self::sourceRow($source, $plugins);
                $sources[$source][$registry][] = (string) $name;
            }
        }

        foreach ($sources as $key => $row) {
            foreach (self::REGISTRIES as $registry) {
                $list = $row[$registry];
                sort($list);
                $sources[$key][$registry] = $list;
            }
        }

        // Cornerstone first, the unattributed last, the rest by name.
        uksort($sources, static function (string $a, string $b): int {
            $rank = static fn (string $key): int => $key === self::CORNERSTONE ? 0 : ($key === self::UNKNOWN ? 2 : 1);

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return ['sources' => $sources, 'counts' => $counts, 'unreadable' => $unreadable];
    }

    /**
     * The first source among an entry's callback files that says who
     * registered it: Cornerstone when Cornerstone is among them (a plugin
     * that adds to one of Cornerstone's own Dynamic Content groups does not
     * make the group the plugin's), otherwise the first file that resolves.
     *
     * @param  array<int, string|null> $files In the order they should be tried.
     * @param  array<string, mixed>    $roots
     */
    public static function ownerFile(array $files, array $roots, bool $preferCornerstone): ?string
    {
        $resolved = array_values(array_filter($files, static fn (mixed $file): bool => is_string($file) && $file !== ''));

        if ($preferCornerstone) {
            foreach ($resolved as $file) {
                if (self::sourceOf($file, $roots) === self::CORNERSTONE) {
                    return $file;
                }
            }
        }

        return $resolved[0] ?? null;
    }

    /**
     * The file a callback's code lives in, found by reflection: a closure, a
     * function name, "Class::method", [object or class, method] or an
     * invokable object. Null when it is none of those or is built into PHP.
     *
     * @param string|null $skipScope Ignore closures created inside this class
     *                               (Cornerstone's Definition wraps an element's
     *                               controls in a builder closure of its own).
     */
    public static function callableFile(mixed $callable, ?string $skipScope = null): ?string
    {
        try {
            if ($callable instanceof \Closure) {
                $reflection = new \ReflectionFunction($callable);

                if ($skipScope !== null && $reflection->getClosureScopeClass()?->getName() === ltrim($skipScope, '\\')) {
                    return null;
                }
            } elseif (is_string($callable) && str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);
                $reflection = new \ReflectionMethod($class, $method);
            } elseif (is_string($callable) && function_exists($callable)) {
                $reflection = new \ReflectionFunction($callable);
            } elseif (is_array($callable) && count($callable) === 2 && isset($callable[0], $callable[1]) && (is_object($callable[0]) || is_string($callable[0])) && is_string($callable[1])) {
                $reflection = new \ReflectionMethod($callable[0], $callable[1]);
            } elseif (is_object($callable) && method_exists($callable, '__invoke')) {
                $reflection = new \ReflectionMethod($callable, '__invoke');
            } else {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $file = $reflection->getFileName();

        return is_string($file) && $file !== '' ? $file : null;
    }

    /**
     * The file a class is declared in, or null.
     */
    public static function classFile(mixed $class): ?string
    {
        if (! is_string($class) || $class === '') {
            return null;
        }

        try {
            if (! class_exists($class)) {
                return null;
            }

            $file = (new \ReflectionClass($class))->getFileName();
        } catch (\Throwable) {
            return null;
        }

        return is_string($file) && $file !== '' ? $file : null;
    }

    /**
     * ACF, free or Pro. The `ACF` class is in both, so it says only that ACF
     * is active; Pro is the ACF_PRO constant or acf_get_setting('pro').
     *
     * @return array{active: bool, pro: bool, version: string|null}
     */
    public static function acf(bool $active, bool $proConstant, mixed $proSetting, ?string $version): array
    {
        return [
            'active'  => $active,
            'pro'     => $active && ($proConstant || ! empty($proSetting)),
            'version' => $active ? $version : null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>> $plugins
     * @return array<string, mixed>
     */
    private static function sourceRow(string $source, array $plugins): array
    {
        $row = ['label' => $source === self::CORNERSTONE ? 'Cornerstone' : ($source === self::UNKNOWN ? 'Could not be attributed' : $source)];

        if (str_starts_with($source, 'plugin:')) {
            $folder = substr($source, strlen('plugin:'));

            foreach ($plugins as $file => $header) {
                if (is_string($file) && str_starts_with($file, $folder . '/') && is_array($header)) {
                    $row['label'] = is_string($header['Name'] ?? null) && $header['Name'] !== '' ? $header['Name'] : $source;
                    $row['plugin'] = $file;
                    $row['version'] = is_string($header['Version'] ?? null) && $header['Version'] !== '' ? $header['Version'] : null;
                    break;
                }
            }
        }

        foreach (self::REGISTRIES as $registry) {
            $row[$registry] = [];
        }

        return $row;
    }

    private static function path(string $path): string
    {
        // Hosts such as WP Engine define the WordPress constants through a
        // symlinked path (/sites/<install>/…) while PHP reports the files it
        // loaded by their real path (/nas/content/live/<install>/…), so both
        // sides are resolved before they are compared.
        $real = @realpath($path);

        if (is_string($real) && $real !== '') {
            $path = $real;
        }

        $path = str_replace('\\', '/', $path);

        return (string) preg_replace('#/+#', '/', $path);
    }

    private static function root(mixed $root): ?string
    {
        if (! is_string($root) || trim($root) === '') {
            return null;
        }

        return rtrim(self::path($root), '/') . '/';
    }
}
