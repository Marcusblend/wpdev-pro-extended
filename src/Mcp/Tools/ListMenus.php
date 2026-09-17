<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Support\Args;

final class ListMenus implements ToolInterface, AnnotatedToolInterface
{
    public function name(): string
    {
        return 'list_menus';
    }

    public function description(): string
    {
        return 'List WordPress navigation menus with their theme locations, item counts and the Cornerstone reference ("menu:<id>") for menu elements. include_items: true adds each menu\'s items.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'include_items' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Include menu items. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['include_items'], 'arguments');
        $includeItems = Args::bool($arguments, 'include_items', false);

        $locations = [];

        foreach ((array) get_nav_menu_locations() as $location => $menuId) {
            $locations[(int) $menuId][] = (string) $location;
        }

        $menus = [];

        foreach (wp_get_nav_menus() as $menu) {
            $row = [
                'id'         => (int) $menu->term_id,
                'name'       => $menu->name,
                'slug'       => $menu->slug,
                'locations'  => $locations[(int) $menu->term_id] ?? [],
                'item_count' => (int) $menu->count,
                'cs_ref'     => 'menu:' . $menu->term_id,
            ];

            if ($includeItems) {
                $row['items'] = array_map(static fn($item): array => [
                    'id'        => (int) $item->ID,
                    'title'     => $item->title,
                    'url'       => $item->url,
                    'parent'    => (int) $item->menu_item_parent,
                    'order'     => (int) $item->menu_order,
                    'type'      => $item->type,
                    'object'    => $item->object,
                    'object_id' => (int) $item->object_id,
                ], (array) wp_get_nav_menu_items($menu->term_id));
            }

            $menus[] = $row;
        }

        return [
            'count' => count($menus),
            'menus' => $menus,
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('List Menus');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
