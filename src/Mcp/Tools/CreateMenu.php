<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Menus\MenuGateway;
use ProExtended\Menus\MenuItems;
use ProExtended\Support\Args;

final class CreateMenu implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly MenuGateway $menus) {}

    public function name(): string
    {
        return 'create_menu';
    }

    public function description(): string
    {
        return 'Create a WordPress navigation menu, optionally with items and theme locations. items are the same operations update_menu takes, so a whole menu can be built in one call: {"op": "add", "title": "Rentals", "url": "/rentals/", "ref": "rentals"} and then {"op": "add", "title": "Skis", "url": "/skis/", "parent": "rentals"}. locations is a map of registered theme location => true; nothing else is reassigned. With if_not_exists: true an existing menu with the same name is returned instead of a second one. Reference the menu in Cornerstone navigation elements as "menu:<id>". Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'Menu name, as it appears in Appearance > Menus.',
                ],
                'items' => [
                    'type'        => 'array',
                    'maxItems'    => 200,
                    'items'       => ['type' => 'object'],
                    'description' => 'Optional. Item operations, applied in order. Only "add" makes sense on a new menu.',
                ],
                'locations' => [
                    'type'        => 'object',
                    'description' => 'Optional. Registered theme location => true to assign this menu, false to clear it. Locations not listed are left alone.',
                ],
                'if_not_exists' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Return the existing menu of the same name instead of creating another. Default: false.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report what would be written and change nothing. Default: false.',
                ],
            ],
            'required'   => ['name'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['name', 'items', 'locations', 'if_not_exists', 'dry_run'], 'arguments');

        $name = (string) Args::string($arguments, 'name', null, 200, false);
        $dryRun = Args::bool($arguments, 'dry_run', false);
        $ifNotExists = Args::bool($arguments, 'if_not_exists', false);
        $items = Args::list($arguments, 'items', 0, 200) ?? [];
        $locations = Args::object($arguments, 'locations') ?? [];

        $normalized = MenuItems::normalize($items);

        if ($normalized['errors'] !== []) {
            throw new \InvalidArgumentException("The items could not be used:\n- " . implode("\n- ", $normalized['errors']));
        }

        $wantedLocations = [];

        foreach ($locations as $location => $take) {
            if (! is_string($location) || ! is_bool($take)) {
                throw new \InvalidArgumentException('locations must map a theme location name to true or false.');
            }

            $wantedLocations[$location] = $take;
        }

        $existing = $this->menus->locate($name);

        if ($existing !== null && ! $ifNotExists) {
            throw new \InvalidArgumentException(sprintf('A menu named "%s" already exists (ID %d). Use update_menu, or pass if_not_exists: true.', $name, (int) $existing->term_id));
        }

        if ($dryRun) {
            return [
                'dry_run'    => true,
                'exists'     => $existing !== null,
                'menu'       => $existing !== null ? ['id' => (int) $existing->term_id, 'name' => $existing->name] : ['name' => $name],
                'operations' => count($normalized['operations']),
                'locations'  => array_keys($wantedLocations),
            ];
        }

        if ($existing !== null) {
            $menu = ['id' => (int) $existing->term_id, 'name' => $existing->name, 'slug' => $existing->slug];
            $created = false;
        } else {
            $menu = $this->menus->create($name);
            $created = true;
        }

        $applied = $this->menus->apply($menu['id'], $normalized['operations'], false, false);
        $locationResult = $wantedLocations === [] ? ['changed' => [], 'unknown' => []] : $this->menus->setLocations($menu['id'], $wantedLocations, false);

        $result = [
            'created'    => $created,
            'menu'       => $menu + ['cs_ref' => 'menu:' . $menu['id']],
            'applied'    => $applied['applied'],
            'locations'  => $this->menus->locations($menu['id']),
            'items'      => MenuItems::tree($this->menus->items($menu['id'])),
        ];

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
        return Annotations::write('Create Menu', false, true);
    }

    public function requiredCapability(): string
    {
        return 'edit_theme_options';
    }
}
