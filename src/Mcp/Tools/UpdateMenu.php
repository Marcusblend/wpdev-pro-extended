<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Menus\MenuGateway;
use ProExtended\Menus\MenuItems;
use ProExtended\Support\Args;

final class UpdateMenu implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly MenuGateway $menus) {}

    public function name(): string
    {
        return 'update_menu';
    }

    public function description(): string
    {
        return 'Change a menu\'s items, order, nesting, anchor graphics and theme locations. menu is an ID, slug or name. operations run in order: {"op": "add", title, url | type+object+object_id, parent, position, graphic, ref}, {"op": "update", item, ...fields}, {"op": "move", item, parent, position}, {"op": "set_graphic", item, graphic}, {"op": "remove", item} (needs force: true). parent and item take an item ID, 0 for the top level, or the ref of an item added earlier in the same call. graphic sets the anchor graphic Cornerstone navigation elements read: icon, icon_alt, image, image_alt, image_text, image_width, image_height, display. locations maps a registered theme location to true or false and leaves every other location alone. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'menu' => [
                    'type'        => ['string', 'integer'],
                    'description' => 'Menu ID, slug or name.',
                ],
                'operations' => [
                    'type'        => 'array',
                    'maxItems'    => 200,
                    'items'       => ['type' => 'object'],
                    'description' => 'Optional. Item operations, applied in order.',
                ],
                'locations' => [
                    'type'        => 'object',
                    'description' => 'Optional. Theme location => true to assign this menu, false to clear it.',
                ],
                'force' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Required for a remove operation, which deletes the item permanently. Default: false.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report what would be written and change nothing. Default: false.',
                ],
            ],
            'required'   => ['menu'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['menu', 'operations', 'locations', 'force', 'dry_run'], 'arguments');

        $menuArg = $arguments['menu'] ?? null;

        if (! is_int($menuArg) && (! is_string($menuArg) || trim($menuArg) === '')) {
            throw new \InvalidArgumentException('menu must be a menu ID, slug or name.');
        }

        $dryRun = Args::bool($arguments, 'dry_run', false);
        $force = Args::bool($arguments, 'force', false);
        $operations = Args::list($arguments, 'operations', 0, 200) ?? [];
        $locations = Args::object($arguments, 'locations') ?? [];

        if ($operations === [] && $locations === []) {
            throw new \InvalidArgumentException('Pass operations, locations, or both.');
        }

        $menu = $this->menus->locate(is_int($menuArg) ? $menuArg : (string) $menuArg);

        if ($menu === null) {
            throw new \InvalidArgumentException(sprintf('No menu matches "%s". Use list_menus to see them.', (string) $menuArg));
        }

        $menuId = (int) $menu->term_id;

        $normalized = MenuItems::normalize($operations);

        if ($normalized['errors'] !== []) {
            throw new \InvalidArgumentException("The operations could not be used:\n- " . implode("\n- ", $normalized['errors']));
        }

        $wantedLocations = [];

        foreach ($locations as $location => $take) {
            if (! is_string($location) || ! is_bool($take)) {
                throw new \InvalidArgumentException('locations must map a theme location name to true or false.');
            }

            $wantedLocations[$location] = $take;
        }

        $before = $this->menus->items($menuId);
        $applied = $this->menus->apply($menuId, $normalized['operations'], $dryRun, $force);
        $locationResult = $wantedLocations === []
            ? ['changed' => [], 'unknown' => []]
            : $this->menus->setLocations($menuId, $wantedLocations, $dryRun);

        $result = [
            'dry_run'   => $dryRun,
            'menu'      => ['id' => $menuId, 'name' => $menu->name, 'slug' => $menu->slug, 'cs_ref' => 'menu:' . $menuId],
            'applied'   => $applied['applied'],
            'locations' => [
                'changed' => $locationResult['changed'],
                'current' => $this->menus->locations($menuId),
            ],
            'items_before' => count($before),
        ];

        if (! $dryRun) {
            $after = $this->menus->items($menuId);
            $result['items_after'] = count($after);
            $result['items'] = MenuItems::tree($after);
        }

        $warnings = $applied['errors'];

        foreach ($locationResult['unknown'] as $unknown) {
            $warnings[] = sprintf('"%s" is not a theme location this theme registers, so it was skipped.', $unknown);
        }

        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::write('Update Menu', true, false);
    }

    public function requiredCapability(): string
    {
        return 'edit_theme_options';
    }
}
