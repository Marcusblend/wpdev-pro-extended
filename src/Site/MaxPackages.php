<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * A summary of the Themeco Max products a site knows about.
 *
 * Pro stores them in the `x_max_plugins` option, whose entries also carry
 * download URLs for licensed packages. Only whitelisted fields are copied,
 * and a whitelisted value that looks like a URL is dropped, so no package
 * URL can leave the site through this summary.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class MaxPackages
{
    /** Fields copied from each entry (output name => stored name). */
    private const FIELDS = [
        'slug'    => 'slug',
        'title'   => 'title',
        'plugin'  => 'plugin',
        'version' => 'new_version',
    ];

    /**
     * @param  mixed    $stored    The option value.
     * @param  string[] $installed Installed plugin files ("folder/file.php").
     * @param  string[] $active    Active plugin files.
     * @return array<int, array{slug: string|null, title: string|null, plugin: string|null, version: string|null, purchased: bool, installed: bool, active: bool}>
     */
    public static function summarize(mixed $stored, array $installed, array $active): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $packages = [];

        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $row = [];

            foreach (self::FIELDS as $out => $in) {
                $value = $entry[$in] ?? null;
                $row[$out] = is_scalar($value) && ! self::looksLikeUrl((string) $value) ? (string) $value : null;
            }

            $plugin = $row['plugin'];
            $row['purchased'] = ! empty($entry['purchased']);
            $row['installed'] = $plugin !== null && in_array($plugin, $installed, true);
            $row['active'] = $plugin !== null && in_array($plugin, $active, true);

            $packages[] = $row;
        }

        usort($packages, static fn(array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));

        return $packages;
    }

    private static function looksLikeUrl(string $value): bool
    {
        return (bool) preg_match('#(^[a-z][a-z0-9+.-]*:|//|\?)#i', $value);
    }
}
