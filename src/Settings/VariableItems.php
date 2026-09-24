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
     * Only what the call names is validated and changed. Every other stored
     * item is carried through exactly as it is, in its place, whatever its id
     * looks like: an item this plugin would not have written (a name the
     * builder allowed, a duplicate, a leading "--") still belongs to the site,
     * and a write about something else must not drop or rename it. A stored
     * item is matched by its name with any leading "--" dropped, and keeps its
     * own spelling of the id when its value changes.
     *
     * @param  array<int, mixed>    $stored  The list as it is on the site.
     * @param  array<int|string, mixed> $updates Variables to add or change: a list of
     *                                        {id, value, breakpoints?} or a map of id => value.
     * @param  string[]             $remove  IDs to drop.
     * @return array{items: array<int, mixed>, added: string[], changed: string[], removed: string[], errors: string[]}
     */
    public static function merge(array $stored, array $updates, array $remove = []): array
    {
        $errors = [];
        $items = array_values($stored);

        // Normalised name => the positions of the stored items it matches.
        $index = [];

        foreach ($items as $position => $item) {
            $id = is_array($item) ? self::normalizeId($item['id'] ?? '') : null;

            if ($id !== null) {
                $index[$id][] = $position;
            }
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

            $breakpoints = $update['breakpoints'] ?? null;

            if ($breakpoints !== null && ! is_array($breakpoints)) {
                $errors[] = sprintf('Variable "%s": breakpoints must be a list of values, one per breakpoint.', $id);
                continue;
            }

            $apply = static function (array $entry) use ($value, $breakpoints): array {
                $entry['value'] = (string) $value;

                if ($breakpoints !== null) {
                    $entry['_bp'] = ['value' => array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $breakpoints))];
                }

                return $entry;
            };

            if (! isset($index[$id])) {
                $items[] = $apply(['id' => $id]);
                $index[$id] = [array_key_last($items)];
                $added[] = $id;
                continue;
            }

            foreach ($index[$id] as $position) {
                $current = $items[$position];
                $next = $apply($current);

                if (($current['value'] ?? null) !== $next['value'] || ($current['_bp'] ?? null) !== ($next['_bp'] ?? null)) {
                    $items[$position] = $next;

                    if (! in_array($id, $changed, true) && ! in_array($id, $added, true)) {
                        $changed[] = $id;
                    }
                }
            }
        }

        $removed = [];

        foreach ($remove as $id) {
            $id = self::normalizeId($id);

            if ($id === null || ! isset($index[$id])) {
                continue;
            }

            foreach ($index[$id] as $position) {
                unset($items[$position]);
            }

            unset($index[$id]);
            $removed[] = $id;
        }

        return [
            'items'   => array_values($items),
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
