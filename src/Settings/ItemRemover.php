<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * Removes palette or font entries from Cornerstone's stored item list by
 * `_id`.
 *
 * An item's ID is also dropped from every group's `children`; removing a
 * group removes only the group, and its members stay in the list. Locked
 * entries (and locked groups whose members change) need allow_locked, as in
 * ItemMerger. Every other entry, and the order, is kept as stored.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ItemRemover
{
    /**
     * @param  array<int, mixed> $stored Current items, groups included.
     * @param  array<int, mixed> $ids    IDs to remove.
     * @return array{items: array<int, mixed>, removed: string[], removed_groups: string[], groups_changed: string[], errors: string[]}
     */
    public static function remove(array $stored, array $ids, bool $allowLocked): array
    {
        $errors = [];
        $wanted = [];

        foreach ($ids as $i => $id) {
            if (! is_string($id) || $id === '') {
                $errors[] = sprintf('remove[%d] must be a non-empty _id string.', $i);
                continue;
            }

            if (isset($wanted[$id])) {
                $errors[] = sprintf('"%s" is listed more than once in remove.', $id);
                continue;
            }

            $wanted[$id] = true;
        }

        $byId = [];

        foreach (array_values($stored) as $position => $entry) {
            if (is_array($entry) && isset($entry['_id']) && is_scalar($entry['_id']) && ! isset($byId[(string) $entry['_id']])) {
                $byId[(string) $entry['_id']] = $position;
            }
        }

        $removed = [];
        $removedGroups = [];
        $drop = [];

        foreach (array_keys($wanted) as $id) {
            if (! isset($byId[$id])) {
                $errors[] = sprintf('"%s" is not in the list.', $id);
                continue;
            }

            $entry = $stored[$byId[$id]];

            if (! empty($entry['locked']) && ! $allowLocked) {
                $errors[] = sprintf('"%s" is locked. Pass allow_locked: true to remove it.', $id);
                continue;
            }

            $drop[$byId[$id]] = true;

            if (array_key_exists('children', $entry)) {
                $removedGroups[] = $id;
            } else {
                $removed[] = $id;
            }
        }

        $items = [];
        $groupsChanged = [];

        foreach (array_values($stored) as $position => $entry) {
            if (isset($drop[$position])) {
                continue;
            }

            if (is_array($entry) && isset($entry['children']) && is_array($entry['children']) && $removed !== []) {
                $children = array_values(array_filter(
                    $entry['children'],
                    static fn($child): bool => ! (is_scalar($child) && in_array((string) $child, $removed, true))
                ));

                if ($children !== array_values($entry['children'])) {
                    $groupId = (string) ($entry['_id'] ?? '?');

                    if (! empty($entry['locked']) && ! $allowLocked) {
                        $errors[] = sprintf('Group "%s" is locked. Pass allow_locked: true to remove its members.', $groupId);
                    }

                    $entry['children'] = $children;
                    $groupsChanged[] = $groupId;
                }
            }

            $items[] = $entry;
        }

        return [
            'items'          => $items,
            'removed'        => $removed,
            'removed_groups' => $removedGroups,
            'groups_changed' => $groupsChanged,
            'errors'         => $errors,
        ];
    }

    /**
     * How many entries of a list are items rather than groups.
     *
     * @param array<int, mixed> $items
     */
    public static function countItems(array $items): int
    {
        return count(array_filter($items, static fn($entry): bool => is_array($entry) && ! array_key_exists('children', $entry)));
    }
}
