<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * Merges palette or font entries into Cornerstone's stored item list by `_id`.
 *
 * Cornerstone's own addColorItems()/addFontItems() test `empty($index)`, so an
 * item stored at index 0 is appended as a duplicate instead of updated. This
 * merge looks items up with array_key_exists() instead, keeps every key it was
 * not asked to change (including `locked`), keeps the stored order, appends
 * new items in the order given, then appends a new group.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ItemMerger
{
    /**
     * @param  array<int, mixed>                    $stored      Current items, groups included.
     * @param  array<int, array<string, mixed>>     $items       Items to add or update.
     * @param  array{_id: string, title?: string}|null $group    Group to create or add new items to.
     * @return array{
     *     items: array<int, mixed>,
     *     added: string[],
     *     updated: array<int, array{_id: string, before: array<string, mixed>, after: array<string, mixed>}>,
     *     unchanged: int,
     *     group: array<string, mixed>|null,
     *     errors: string[]
     * }
     */
    public static function merge(array $stored, array $items, ?array $group, bool $allowLocked): array
    {
        $result = array_values($stored);
        $index = [];

        foreach ($result as $position => $item) {
            if (is_array($item) && isset($item['_id']) && is_scalar($item['_id']) && ! array_key_exists((string) $item['_id'], $index)) {
                $index[(string) $item['_id']] = $position;
            }
        }

        $added = [];
        $updated = [];
        $unchanged = 0;
        $errors = [];
        $seen = [];

        foreach ($items as $item) {
            $id = (string) $item['_id'];

            if (isset($seen[$id])) {
                $errors[] = sprintf('"%s" is listed more than once.', $id);
                continue;
            }

            $seen[$id] = true;

            if (! array_key_exists($id, $index)) {
                $result[] = $item;
                $index[$id] = count($result) - 1;
                $added[] = $id;
                continue;
            }

            $position = $index[$id];
            $before = $result[$position];

            if (! is_array($before)) {
                $errors[] = sprintf('The stored entry "%s" is not an object.', $id);
                continue;
            }

            if (array_key_exists('children', $before)) {
                $errors[] = sprintf('"%s" is a group. Groups are managed with the "group" argument.', $id);
                continue;
            }

            $after = array_merge($before, $item);

            if ($after === $before) {
                $unchanged++;
                continue;
            }

            if (! empty($before['locked']) && ! $allowLocked) {
                $errors[] = sprintf('"%s" is locked. Pass allow_locked: true to change it.', $id);
                continue;
            }

            $result[$position] = $after;
            $updated[] = ['_id' => $id, 'before' => $before, 'after' => $after];
        }

        $groupResult = null;

        if ($group !== null) {
            $groupId = (string) $group['_id'];

            if (isset($seen[$groupId])) {
                $errors[] = sprintf('The group ID "%s" is also used by an item.', $groupId);
            } elseif (array_key_exists($groupId, $index)) {
                $position = $index[$groupId];
                $before = $result[$position];

                if (! is_array($before) || ! array_key_exists('children', $before)) {
                    $errors[] = sprintf('"%s" already exists and is not a group.', $groupId);
                } else {
                    $after = $before;

                    if (isset($group['title']) && $group['title'] !== ($before['title'] ?? null)) {
                        $after['title'] = $group['title'];
                    }

                    $children = is_array($before['children']) ? array_values($before['children']) : [];

                    foreach ($added as $newId) {
                        if (! in_array($newId, $children, true)) {
                            $children[] = $newId;
                        }
                    }

                    $after['children'] = $children;

                    if ($after === $before) {
                        $groupResult = ['_id' => $groupId, 'action' => 'unchanged'];
                    } elseif (! empty($before['locked']) && ! $allowLocked) {
                        $errors[] = sprintf('Group "%s" is locked. Pass allow_locked: true to change it.', $groupId);
                    } else {
                        $result[$position] = $after;
                        $groupResult = ['_id' => $groupId, 'action' => 'updated', 'before' => $before, 'after' => $after];
                    }
                }
            } else {
                $new = [
                    '_id'      => $groupId,
                    'title'    => (string) ($group['title'] ?? $groupId),
                    'children' => $added,
                ];
                $result[] = $new;
                $groupResult = ['_id' => $groupId, 'action' => 'created', 'after' => $new];
            }
        }

        return [
            'items'     => $result,
            'added'     => $added,
            'updated'   => $updated,
            'unchanged' => $unchanged,
            'group'     => $groupResult,
            'errors'    => $errors,
        ];
    }
}
