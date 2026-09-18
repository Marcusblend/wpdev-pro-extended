<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * A fingerprint of the Cornerstone platform this site is running.
 *
 * Themeco ships often, and each release can add element types, change a
 * migration version, register a theme option or a document type, or add a
 * dynamic content group. Pro Extended is written against those, so the useful
 * question is not "what version is installed" but "what changed since the last
 * time we looked". This records the answer and diffs it.
 *
 * The comparison is pure, so it is unit-tested without a site.
 */
final class PlatformBaseline
{
    public const OPTION = 'pe_platform_baseline';

    /**
     * Compare two snapshots.
     *
     * @param  array<string, mixed>|null $before
     * @param  array<string, mixed>      $after
     * @return array{drifted: bool, changes: array<int, array<string, mixed>>}
     */
    public static function diff(?array $before, array $after): array
    {
        if ($before === null) {
            return ['drifted' => false, 'changes' => []];
        }

        $changes = [];

        foreach (['cornerstone_version', 'pro_version', 'wordpress_version', 'php_version', 'breakpoint_tag'] as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;

            if ($was !== $now) {
                $changes[] = ['kind' => 'version', 'what' => $key, 'from' => $was, 'to' => $now];
            }
        }

        foreach (['element_types', 'document_types', 'theme_option_keys', 'dynamic_content_groups', 'looper_types', 'permissions'] as $key) {
            $was = self::strings($before[$key] ?? []);
            $now = self::strings($after[$key] ?? []);

            $added = array_values(array_diff($now, $was));
            $removed = array_values(array_diff($was, $now));

            if ($added !== []) {
                $changes[] = ['kind' => 'added', 'what' => $key, 'items' => $added];
            }

            if ($removed !== []) {
                $changes[] = ['kind' => 'removed', 'what' => $key, 'items' => $removed];
            }
        }

        $wasMigrations = self::ints($before['migrations'] ?? []);
        $nowMigrations = self::ints($after['migrations'] ?? []);
        $bumped = [];

        foreach ($nowMigrations as $type => $version) {
            if (isset($wasMigrations[$type]) && $wasMigrations[$type] !== $version) {
                $bumped[] = ['type' => $type, 'from' => $wasMigrations[$type], 'to' => $version];
            }
        }

        if ($bumped !== []) {
            $changes[] = ['kind' => 'migrations', 'what' => 'migrations', 'items' => $bumped];
        }

        return ['drifted' => $changes !== [], 'changes' => $changes];
    }

    /**
     * A one-line summary of what a diff found.
     *
     * @param array<int, array<string, mixed>> $changes
     */
    public static function summarize(array $changes): string
    {
        if ($changes === []) {
            return 'No drift since the stored baseline.';
        }

        $parts = [];

        foreach ($changes as $change) {
            $count = isset($change['items']) && is_array($change['items']) ? count($change['items']) : 1;
            $parts[] = sprintf('%s %s (%d)', (string) $change['kind'], (string) $change['what'], $count);
        }

        return implode(', ', $parts);
    }

    /**
     * @param  mixed $value
     * @return string[]
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value), static fn (string $v): bool => $v !== ''));
        sort($items);

        return $items;
    }

    /**
     * @param  mixed $value
     * @return array<string, int>
     */
    private static function ints(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_numeric($item)) {
                $items[$key] = (int) $item;
            }
        }

        ksort($items);

        return $items;
    }
}
