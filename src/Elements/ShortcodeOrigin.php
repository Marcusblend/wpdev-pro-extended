<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Where a shortcode used in element text comes from.
 *
 * A layout that prints `[site_promo]` depends on whatever PHP registered that
 * tag. When that is WordPress, Pro/Cornerstone or a Themeco extension, the
 * dependency is part of the platform. When it is a site's own plugin, an
 * mu-plugin or a child theme's functions.php, the layout breaks the day that
 * code goes, and the feature almost always has a native form: Dynamic Content
 * for data, Twig for logic.
 *
 * The tag list and the callback are read from WordPress at runtime (see
 * ElementContext); this class only finds tags in text, finds the file that
 * defines a callback, and says which of those places the file is in. Pure
 * PHP with no WordPress calls, so it is unit-tested from fixture files.
 */
final class ShortcodeOrigin
{
    /** Origins that are part of the platform rather than the site. */
    public const PLATFORM = ['core', 'themeco', 'internal'];

    /**
     * Shortcode tags a string uses, in order, once each.
     *
     * Only the opening form counts: `[tag]`, `[tag attr="x"]`, `[tag/]`. An
     * escaped `[[tag]]` prints literally and is skipped, as are closing tags,
     * and names that start with a digit (footnote marks such as `[1]`). A
     * name only matters if it is registered, which the caller checks.
     *
     * @return string[]
     */
    public static function tags(string $text): array
    {
        if (! str_contains($text, '[')) {
            return [];
        }

        if (! preg_match_all('/\[([A-Za-z_][A-Za-z0-9_-]*)(?=[\s\]\/])/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $tags = [];

        foreach ($matches[1] as [$tag, $offset]) {
            // `[[tag]]` is WordPress's escape: it prints `[tag]` and runs
            // nothing. Both sides have to be doubled, as in WordPress.
            $close = strpos($text, ']', $offset);

            if ($offset >= 2 && $text[$offset - 2] === '[' && $close !== false && ($text[$close + 1] ?? '') === ']') {
                continue;
            }

            $tags[$tag] = true;
        }

        return array_keys($tags);
    }

    /**
     * The file a callback is defined in.
     *
     * Returns "" for a function PHP itself provides (no file), and null when
     * the callback cannot be reflected — a method that only exists through
     * __call, or a value that is not callable at all.
     */
    public static function definingFile(mixed $callback): ?string
    {
        try {
            if ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
            } elseif (is_string($callback) && str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);
                $reflection = new \ReflectionMethod($class, $method);
            } elseif (is_string($callback)) {
                $reflection = new \ReflectionFunction($callback);
            } elseif (is_array($callback) && count($callback) === 2 && is_string($callback[1] ?? null) && (is_object($callback[0] ?? null) || is_string($callback[0] ?? null))) {
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
            } else {
                return null;
            }
        } catch (\ReflectionException) {
            return null;
        }

        $file = $reflection->getFileName();

        return $file === false ? '' : self::normalize($file);
    }

    /**
     * Which part of the install a file belongs to.
     *
     * @param array<string, string[]> $roots Directories by kind:
     *   core (wp-includes, wp-admin), themeco (Cornerstone and the theme that
     *   bundles it), plugins, mu_plugins, themes, and themeco_plugins — plugin
     *   folder names Themeco ships (Max products). A plugin folder named
     *   "cornerstone" or "cornerstone-*" is Themeco's.
     * @return string core, themeco, internal, plugin, mu-plugin, theme or other.
     */
    public static function classify(string $file, array $roots): string
    {
        if ($file === '') {
            return 'internal';
        }

        $file = self::normalize($file);

        foreach (['core' => 'core', 'themeco' => 'themeco', 'mu_plugins' => 'mu-plugin'] as $kind => $origin) {
            if (self::under($file, $roots[$kind] ?? []) !== null) {
                return $origin;
            }
        }

        $relative = self::under($file, $roots['plugins'] ?? []);

        if ($relative !== null) {
            $folder = explode('/', $relative, 2)[0];

            return self::isThemecoPlugin($folder, $roots['themeco_plugins'] ?? []) ? 'themeco' : 'plugin';
        }

        if (self::under($file, $roots['themes'] ?? []) !== null) {
            return 'theme';
        }

        return 'other';
    }

    /**
     * A short name for a file, for messages: "plugins/site-helpers/site-helpers.php".
     *
     * @param array<string, string[]> $roots The same roots classify() takes, plus abspath.
     */
    public static function label(string $file, array $roots): string
    {
        if ($file === '') {
            return 'PHP itself';
        }

        $file = self::normalize($file);

        foreach (['mu_plugins' => 'mu-plugins/', 'plugins' => 'plugins/', 'themes' => 'themes/', 'abspath' => ''] as $kind => $prefix) {
            $relative = self::under($file, $roots[$kind] ?? []);

            if ($relative !== null) {
                return $prefix . $relative;
            }
        }

        return $file;
    }

    /**
     * @param string[] $themecoFolders
     */
    public static function isThemecoPlugin(string $folder, array $themecoFolders = []): bool
    {
        return $folder === 'cornerstone'
            || str_starts_with($folder, 'cornerstone-')
            || in_array($folder, $themecoFolders, true);
    }

    /**
     * The part of a path below the first root it sits in, or null.
     *
     * @param string[] $roots
     */
    private static function under(string $file, array $roots): ?string
    {
        foreach ($roots as $root) {
            if (! is_string($root) || trim($root) === '') {
                continue;
            }

            $root = rtrim(self::normalize($root), '/') . '/';

            if (str_starts_with($file, $root)) {
                return substr($file, strlen($root));
            }
        }

        return null;
    }

    private static function normalize(string $path): string
    {
        return (string) preg_replace('#/{2,}#', '/', str_replace('\\', '/', $path));
    }
}
