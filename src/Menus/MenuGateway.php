<?php

declare(strict_types=1);

namespace ProExtended\Menus;

/**
 * Reads and writes WordPress navigation menus, including the anchor graphic
 * meta Cornerstone's navigation elements read.
 *
 * Cornerstone's own menu import assigns four theme locations at once, so this
 * gateway only ever touches locations it is given by name. Anchor graphics are
 * written with update_post_meta: the admin screen's save hook returns early
 * outside nav-menus.php, so a programmatic wp_update_nav_menu_item() never
 * stores them.
 */
final class MenuGateway
{
    /**
     * Find a menu by ID, slug or name.
     */
    public function locate(int|string $menu): ?\WP_Term
    {
        $term = is_int($menu) || ctype_digit((string) $menu)
            ? wp_get_nav_menu_object((int) $menu)
            : wp_get_nav_menu_object((string) $menu);

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Create a menu.
     *
     * @return array{id: int, name: string, slug: string}
     */
    public function create(string $name): array
    {
        $id = wp_create_nav_menu($name);

        if (is_wp_error($id)) {
            throw new \RuntimeException('The menu could not be created: ' . $id->get_error_message());
        }

        $term = wp_get_nav_menu_object((int) $id);

        return [
            'id'   => (int) $id,
            'name' => $term instanceof \WP_Term ? $term->name : $name,
            'slug' => $term instanceof \WP_Term ? $term->slug : '',
        ];
    }

    /**
     * Every item in a menu, flat, with its anchor graphic meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(int $menuId): array
    {
        $items = wp_get_nav_menu_items($menuId, ['update_post_term_cache' => false]);

        if (! is_array($items)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            $row = [
                'id'        => (int) $item->ID,
                'title'     => (string) $item->title,
                'url'       => (string) $item->url,
                'parent'    => (int) $item->menu_item_parent,
                'order'     => (int) $item->menu_order,
                'type'      => (string) $item->type,
                'object'    => (string) $item->object,
                'object_id' => (int) $item->object_id,
                'target'    => (string) $item->target,
            ];

            $graphic = $this->graphics((int) $item->ID);

            if ($graphic !== []) {
                $row['graphic'] = $graphic;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The anchor graphic meta stored on one item, keyed by the short field
     * names MenuItems accepts.
     *
     * @return array<string, string>
     */
    public function graphics(int $itemId): array
    {
        $graphic = [];

        foreach (MenuItems::GRAPHIC_FIELDS as $name => $metaKey) {
            $value = get_post_meta($itemId, $metaKey, true);

            if (is_string($value) && $value !== '') {
                $graphic[$name] = $value;
            }
        }

        return $graphic;
    }

    /**
     * The theme locations a menu is assigned to.
     *
     * @return string[]
     */
    public function locations(int $menuId): array
    {
        $assigned = [];

        foreach ((array) get_nav_menu_locations() as $location => $id) {
            if ((int) $id === $menuId) {
                $assigned[] = (string) $location;
            }
        }

        return $assigned;
    }

    /**
     * Assign or clear theme locations, touching only the ones named.
     *
     * @param  array<string, bool> $locations Location => whether this menu takes it.
     * @return array{changed: array<string, string>, unknown: string[]}
     */
    public function setLocations(int $menuId, array $locations, bool $dryRun): array
    {
        $registered = get_registered_nav_menus();
        $current = (array) get_nav_menu_locations();
        $changed = [];
        $unknown = [];
        $next = $current;

        foreach ($locations as $location => $take) {
            if (! isset($registered[$location])) {
                $unknown[] = $location;
                continue;
            }

            $before = (int) ($current[$location] ?? 0);
            $after = $take ? $menuId : 0;

            if ($before === $after) {
                continue;
            }

            $changed[$location] = sprintf('%d -> %d', $before, $after);
            $next[$location] = $after;
        }

        if (! $dryRun && $changed !== []) {
            set_theme_mod('nav_menu_locations', $next);
        }

        return ['changed' => $changed, 'unknown' => $unknown];
    }

    /**
     * Apply normalised operations in order.
     *
     * @param  array<int, array<string, mixed>> $operations From MenuItems::normalize().
     * @return array{applied: array<int, array<string, mixed>>, errors: string[], refs: array<string, int>}
     */
    public function apply(int $menuId, array $operations, bool $dryRun, bool $force): array
    {
        $applied = [];
        $errors = [];
        $refs = [];
        $existing = [];
        $positioned = [];

        foreach ($this->items($menuId) as $item) {
            $existing[(int) $item['id']] = $item;
        }

        foreach ($operations as $index => $operation) {
            $op = (string) $operation['op'];
            $label = sprintf('operations[%d]', $index);

            $itemId = 0;

            if ($op !== 'add') {
                $itemId = $this->resolve($operation['item'] ?? null, $refs);

                if ($itemId <= 0) {
                    $errors[] = $label . ': the item could not be resolved.';
                    continue;
                }

                if (! $dryRun && ! isset($existing[$itemId])) {
                    $errors[] = sprintf('%s: item %d is not in this menu.', $label, $itemId);
                    continue;
                }
            }

            if ($op === 'remove') {
                if (! $force) {
                    $errors[] = sprintf('%s: removing item %d needs force: true.', $label, $itemId);
                    continue;
                }

                if (! $dryRun) {
                    wp_delete_post($itemId, true);
                }

                $applied[] = ['op' => $op, 'item' => $itemId, 'title' => $existing[$itemId]['title'] ?? ''];
                continue;
            }

            if ($op === 'set_graphic') {
                if (! $dryRun) {
                    $this->writeGraphic($itemId, (array) ($operation['graphic'] ?? []));
                }

                $applied[] = ['op' => $op, 'item' => $itemId, 'graphic' => $operation['graphic'] ?? []];
                continue;
            }

            $args = $this->args($operation, $refs, $menuId, $itemId, $existing);

            if ($dryRun) {
                $applied[] = ['op' => $op, 'item' => $itemId ?: null, 'ref' => $operation['ref'] ?? null, 'would_write' => $args];

                if (isset($operation['ref'])) {
                    $refs[(string) $operation['ref']] = 0;
                }

                continue;
            }

            $result = wp_update_nav_menu_item($menuId, $itemId, $args);

            if (is_wp_error($result)) {
                $errors[] = sprintf('%s: %s', $label, $result->get_error_message());
                continue;
            }

            $newId = (int) $result;

            if (isset($operation['ref'])) {
                $refs[(string) $operation['ref']] = $newId;
            }

            $graphic = (array) ($operation['graphic'] ?? []);

            if ($graphic !== []) {
                $this->writeGraphic($newId, $graphic);
            }

            if (($operation['position'] ?? null) !== null) {
                $positioned[$newId] = (int) $operation['position'];
            }

            $written = ['id' => $newId];

            foreach (MenuItems::FIELDS as $name => $key) {
                if (array_key_exists($key, $args)) {
                    $written[$name] = $args[$key];
                }
            }

            // Merge, never replace: a second operation on the same item in the
            // same call has to carry through everything the first one left alone.
            $existing[$newId] = array_merge($existing[$newId] ?? [], $written);
            $applied[] = ['op' => $op, 'item' => $newId, 'ref' => $operation['ref'] ?? null];
        }

        if (! $dryRun && $positioned !== []) {
            $this->resequence($menuId, $positioned);
        }

        return ['applied' => $applied, 'errors' => $errors, 'refs' => $refs];
    }

    /**
     * Renumber a menu so the positions operations asked for actually hold.
     *
     * wp_update_nav_menu_item() writes menu-item-position straight into
     * menu_order and leaves every sibling where it was, so asking for position
     * 1 still leaves whatever already sat there in front. Rebuilding the order
     * the way the menu screen does — siblings in sequence, each parent before
     * its children — makes "1 is first" true, and normalises a menu whose
     * items were only ever appended.
     *
     * @param array<int, int> $positioned Item ID => the position it asked for (1 is first).
     */
    private function resequence(int $menuId, array $positioned): void
    {
        $items = wp_get_nav_menu_items($menuId, ['update_post_term_cache' => false]);

        if (! is_array($items) || $items === []) {
            return;
        }

        $parents = [];
        $orders = [];

        foreach ($items as $item) {
            $id = (int) $item->ID;
            $parents[$id] = (int) $item->menu_item_parent;
            $orders[$id] = (int) $item->menu_order;
        }

        $children = [];

        foreach ($parents as $id => $parent) {
            $children[$parent][] = $id;
        }

        foreach ($children as $parent => $ids) {
            usort($ids, static fn (int $a, int $b): int => $orders[$a] <=> $orders[$b]);
            $children[$parent] = $ids;
        }

        foreach ($positioned as $id => $position) {
            if (! isset($parents[$id])) {
                continue;
            }

            $parent = $parents[$id];
            $siblings = array_values(array_filter(
                $children[$parent] ?? [],
                static fn (int $sibling): bool => $sibling !== $id
            ));

            array_splice($siblings, max(0, min($position - 1, count($siblings))), 0, [$id]);
            $children[$parent] = $siblings;
        }

        $order = 0;
        $seen = [];

        $walk = function (int $parent) use (&$walk, $children, &$order, $orders, &$seen): void {
            foreach ($children[$parent] ?? [] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $order++;

                if (($orders[$id] ?? 0) !== $order) {
                    wp_update_post(['ID' => $id, 'menu_order' => $order]);
                }

                $walk($id);
            }
        };

        $walk(0);
    }

    /**
     * Build the wp_update_nav_menu_item() argument list for an add or update.
     *
     * @param  array<string, mixed> $operation
     * @param  array<string, int>   $refs
     * @param  array<int, mixed>    $existing
     * @return array<string, mixed>
     */
    private function args(array $operation, array $refs, int $menuId, int $itemId, array $existing): array
    {
        $fields = (array) ($operation['fields'] ?? []);
        $args = [];

        foreach (MenuItems::FIELDS as $name => $key) {
            if (array_key_exists($name, $fields)) {
                $args[$key] = $fields[$name];
            }
        }

        // wp_update_nav_menu_item() rewrites every field it is given, so an
        // update has to carry the item's current values through.
        if ($itemId > 0 && isset($existing[$itemId])) {
            $current = $existing[$itemId];

            foreach (MenuItems::FIELDS as $name => $key) {
                if (array_key_exists($key, $args) || ! array_key_exists($name, $current)) {
                    continue;
                }

                $args[$key] = $current[$name];
            }
        }

        $args['menu-item-status'] ??= 'publish';
        $args['menu-item-type'] ??= 'custom';

        if (array_key_exists('parent', $operation) && $operation['parent'] !== null) {
            $parent = $operation['parent'];
            $args['menu-item-parent-id'] = ($parent['kind'] ?? '') === 'root' ? 0 : $this->resolve($parent, $refs);
        }

        // menu-item-position is deliberately not passed through:
        // wp_update_nav_menu_item() would write menu_order for this item and
        // leave every sibling where it was. resequence() owns ordering.

        return $args;
    }

    /**
     * @param  array<string, mixed>|null $reference
     * @param  array<string, int>        $refs
     */
    private function resolve(?array $reference, array $refs): int
    {
        if ($reference === null) {
            return 0;
        }

        if (($reference['kind'] ?? '') === 'id') {
            return (int) ($reference['id'] ?? 0);
        }

        if (($reference['kind'] ?? '') === 'ref') {
            return (int) ($refs[(string) ($reference['ref'] ?? '')] ?? 0);
        }

        return 0;
    }

    /**
     * @param array<string, string> $graphic Meta key => value.
     */
    private function writeGraphic(int $itemId, array $graphic): void
    {
        foreach ($graphic as $metaKey => $value) {
            if ($value === '') {
                delete_post_meta($itemId, $metaKey);
                continue;
            }

            update_post_meta($itemId, $metaKey, $value);
        }
    }
}
