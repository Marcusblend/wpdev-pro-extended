<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * Global Variables: the site-wide values elements can point at.
 *
 * Cornerstone stores them as the cs_theme_variables theme option — a list of
 * {id, value} with optional per-breakpoint values under _bp.value — and writes
 * each one to :root as a custom property. That is what makes a value global:
 * change the variable and everything referring to var(--name) follows, instead
 * of the value being copied into every element that uses it.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class VariableItems
{
    public const OPTION = 'cs_theme_variables';

    /** What a custom property name may contain, once any leading -- is dropped. */
    private const ID_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]*$/';

    /**
     * Merge a set of variables into the stored list.
     *
     * @param  array<int, mixed>    $stored  The list as it is on the site.
     * @param  array<int|string, mixed> $updates Variables to add or change: a list of
     *                                        {id, value, breakpoints?} or a map of id => value.
     * @param  string[]             $remove  IDs to drop.
     * @return array{items: array<int, array<string, mixed>>, added: string[], changed: string[], removed: string[], errors: string[]}
     */
    public static function merge(array $stored, array $updates, array $remove = []): array
    {
        $errors = [];
        $items = [];
        $order = [];

        foreach ($stored as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = self::normalizeId($item['id'] ?? '');

            if ($id === null) {
                continue;
            }

            $item['id'] = $id;
            $items[$id] = $item;
            $order[] = $id;
        }

        $added = [];
        $changed = [];

        foreach (self::asList($updates, $errors) as $update) {
            $id = self::normalizeId($update['id'] ?? '');

            if ($id === null) {
                $errors[] = sprintf('"%s" is not a usable variable name. Use letters, numbers, dashes and underscores, starting with a letter or underscore; a leading "--" is optional.', is_scalar($update['id'] ?? '') ? (string) ($update['id'] ?? '') : '?');
                continue;
            }

            $value = $update['value'] ?? null;

            if (! is_scalar($value) && $value !== null) {
                $errors[] = sprintf('Variable "%s" needs a text value.', $id);
                continue;
            }

            $entry = $items[$id] ?? ['id' => $id];
            $existed = isset($items[$id]);
            $entry['id'] = $id;
            $entry['value'] = (string) $value;

            $breakpoints = $update['breakpoints'] ?? null;

            if ($breakpoints !== null) {
                if (! is_array($breakpoints)) {
                    $errors[] = sprintf('Variable "%s": breakpoints must be a list of values, one per breakpoint.', $id);
                    continue;
                }

                $entry['_bp'] = ['value' => array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $breakpoints))];
            }

            if ($existed) {
                if (($items[$id]['value'] ?? null) !== $entry['value'] || ($items[$id]['_bp'] ?? null) !== ($entry['_bp'] ?? null)) {
                    $changed[] = $id;
                }
            } else {
                $added[] = $id;
                $order[] = $id;
            }

            $items[$id] = $entry;
        }

        $removed = [];

        foreach ($remove as $id) {
            $id = self::normalizeId($id);

            if ($id === null || ! isset($items[$id])) {
                continue;
            }

            unset($items[$id]);
            $removed[] = $id;
        }

        $final = [];

        foreach ($order as $id) {
            if (isset($items[$id]) && ! in_array($id, array_column($final, 'id'), true)) {
                $final[] = $items[$id];
            }
        }

        return [
            'items'   => $final,
            'added'   => $added,
            'changed' => $changed,
            'removed' => $removed,
            'errors'  => $errors,
        ];
    }

    /**
     * The CSS a variable produces, for reporting.
     */
    public static function property(string $id): string
    {
        return '--' . ltrim($id, '-');
    }

    /**
     * How an element refers to a variable.
     */
    public static function reference(string $id): string
    {
        return sprintf('var(%s)', self::property($id));
    }

    /**
     * Accept either a list of objects or a plain id => value map.
     *
     * @param  array<int|string, mixed> $updates
     * @param  string[]                 $errors
     * @return array<int, array<string, mixed>>
     */
    private static function asList(array $updates, array &$errors): array
    {
        $list = [];

        foreach ($updates as $key => $update) {
            if (is_string($key)) {
                $list[] = is_array($update) ? ['id' => $key] + $update : ['id' => $key, 'value' => $update];
                continue;
            }

            if (! is_array($update)) {
                $errors[] = 'Each variable must be an object with id and value, or a map of name => value.';
                continue;
            }

            $list[] = $update;
        }

        return $list;
    }

    private static function normalizeId(mixed $id): ?string
    {
        if (! is_string($id)) {
            return null;
        }

        $id = ltrim(trim($id), '-');

        return $id !== '' && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }
}
