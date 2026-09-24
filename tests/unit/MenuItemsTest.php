<?php

declare(strict_types=1);

use ProExtended\Menus\MenuItems;

T::group('MenuItems');

// Adds, refs and parents -----------------------------------------------------

$r = MenuItems::normalize([
    ['op' => 'add', 'ref' => 'services', 'title' => 'Services', 'url' => '/services/'],
    ['op' => 'add', 'title' => 'Rentals', 'url' => '/rentals/', 'parent' => 'services', 'position' => 1],
]);
T::same([], $r['errors'], 'accepts an add and a child that points at its ref');
T::same(['services'], $r['refs'], 'reports the refs it defined');
T::same(['kind' => 'ref', 'ref' => 'services'], $r['operations'][1]['parent'], 'resolves a bare ref name');
T::same(1, $r['operations'][1]['position'], 'keeps the position');

$r = MenuItems::normalize([['op' => 'add', 'title' => 'Child', 'url' => '/c/', 'parent' => 'ref:missing']]);
T::ok($r['errors'] !== [], 'refuses a ref no earlier operation added');

$r = MenuItems::normalize([
    ['op' => 'add', 'ref' => 'a', 'title' => 'A', 'url' => '/a/'],
    ['op' => 'add', 'ref' => 'a', 'title' => 'B', 'url' => '/b/'],
]);
T::ok($r['errors'] !== [], 'refuses a duplicate ref');

$r = MenuItems::normalize([['op' => 'add', 'ref' => 'bad ref', 'title' => 'A', 'url' => '/a/']]);
T::ok($r['errors'] !== [], 'refuses a ref with a space');

$r = MenuItems::normalize([['op' => 'add', 'title' => 'No link']]);
T::ok($r['errors'] !== [], 'a custom link needs a url');

$r = MenuItems::normalize([['op' => 'add', 'title' => 'Page', 'type' => 'post_type', 'object' => 'page']]);
T::ok($r['errors'] !== [], 'a post type item needs an object_id');

$r = MenuItems::normalize([['op' => 'add', 'title' => 'Page', 'type' => 'post_type', 'object' => 'page', 'object_id' => 42]]);
T::same([], $r['errors'], 'accepts a post type item with an object_id');
T::same(42, $r['operations'][0]['fields']['object_id'], 'keeps the object_id');

$r = MenuItems::normalize([['op' => 'add', 'title' => 'X', 'url' => '/x/', 'type' => 'widget']]);
T::ok($r['errors'] !== [], 'refuses an unknown item type');

$r = MenuItems::normalize([['op' => 'add', 'title' => 'X', 'url' => '/x/', 'target' => 'new']]);
T::ok($r['errors'] !== [], 'refuses a target other than "" or _blank');

$r = MenuItems::normalize([['op' => 'add', 'title' => 'X', 'url' => '/x/', 'position' => 0]]);
T::ok($r['errors'] !== [], 'refuses position 0, since 1 is first');

// Existing items -------------------------------------------------------------

$r = MenuItems::normalize([['op' => 'update', 'item' => 12, 'title' => 'Renamed']]);
T::same([], $r['errors'], 'updates an item by ID');
T::same(['kind' => 'id', 'id' => 12], $r['operations'][0]['item'], 'keeps the item ID');

$r = MenuItems::normalize([['op' => 'update', 'item' => 12]]);
T::ok($r['errors'] !== [], 'an update that changes nothing is refused');

$r = MenuItems::normalize([['op' => 'move', 'item' => 12, 'parent' => 0]]);
T::same([], $r['errors'], 'moves an item to the top level with parent 0');
T::same(['kind' => 'root'], $r['operations'][0]['parent'], 'reports the root parent');

$r = MenuItems::normalize([['op' => 'move', 'item' => 12]]);
T::ok($r['errors'] !== [], 'a move needs a parent or a position');

$r = MenuItems::normalize([['op' => 'remove', 'item' => 12]]);
T::same([], $r['errors'], 'accepts a remove');

$r = MenuItems::normalize([['op' => 'remove']]);
T::ok($r['errors'] !== [], 'a remove needs an item');

$r = MenuItems::normalize([['op' => 'rename', 'item' => 12]]);
T::ok($r['errors'] !== [], 'refuses an unknown op');

// Anchor graphics ------------------------------------------------------------

$r = MenuItems::normalize([['op' => 'set_graphic', 'item' => 12, 'graphic' => ['icon' => 'l-star', 'display' => 'on', 'image_width' => 48]]]);
T::same([], $r['errors'], 'accepts graphic fields');
T::same('l-star', $r['operations'][0]['graphic']['menu-item-anchor_graphic_icon'], 'maps icon to its meta key');
T::same('on', $r['operations'][0]['graphic']['menu-item-anchor_graphic_menu_item_display'], 'maps display to its meta key');
T::same('48', $r['operations'][0]['graphic']['menu-item-anchor_graphic_image_width'], 'casts a numeric width to a string');

$r = MenuItems::normalize([['op' => 'set_graphic', 'item' => 12, 'graphic' => ['image_width' => '48px']]]);
T::ok($r['errors'] !== [], 'refuses a width with a unit');

$r = MenuItems::normalize([['op' => 'set_graphic', 'item' => 12, 'graphic' => ['display' => 'maybe']]]);
T::ok($r['errors'] !== [], 'refuses a display other than on or off');

$r = MenuItems::normalize([['op' => 'set_graphic', 'item' => 12, 'graphic' => ['badge' => 'x']]]);
T::ok($r['errors'] !== [], 'refuses an unknown graphic field');

$r = MenuItems::normalize([['op' => 'set_graphic', 'item' => 12, 'graphic' => []]]);
T::ok($r['errors'] !== [], 'a set_graphic with no fields is refused');

// Tree -----------------------------------------------------------------------

$tree = MenuItems::tree([
    ['id' => 3, 'parent' => 1, 'order' => 2, 'title' => 'Bikes'],
    ['id' => 1, 'parent' => 0, 'order' => 1, 'title' => 'Rentals'],
    ['id' => 2, 'parent' => 1, 'order' => 1, 'title' => 'Skis'],
    ['id' => 4, 'parent' => 0, 'order' => 2, 'title' => 'About'],
]);
T::same(['Rentals', 'About'], array_column($tree, 'title'), 'top level is ordered by menu order');
T::same(['Skis', 'Bikes'], array_column($tree[0]['children'], 'title'), 'children are nested and ordered');
T::ok(! isset($tree[1]['children']), 'an item without children has no children key');

// The parent every write sends ----------------------------------------------

$existing = [12 => ['id' => 12, 'parent' => 5], 5 => ['id' => 5, 'parent' => 0]];
T::same(5, MenuItems::parentId(null, 12, $existing), 'an update that names no parent keeps the item\'s own');
T::same(0, MenuItems::parentId(0, 12, $existing), 'naming the top level moves it there');
T::same(7, MenuItems::parentId(7, 12, $existing), 'naming another parent moves it there');
T::same(0, MenuItems::parentId(null, 0, $existing), 'a new item with no parent goes at the top level');

// Renaming a child through the gateway keeps it where it is --------------------

WpStub::reset();
WpStub::$menuItems = [
    (object) ['menu' => 3, 'ID' => 5, 'title' => 'Rentals', 'url' => '/rentals/', 'menu_item_parent' => '0', 'menu_order' => 1, 'type' => 'custom', 'object' => 'custom', 'object_id' => 5, 'target' => '', 'classes' => [''], 'description' => '', 'attr_title' => '', 'xfn' => '', 'post_status' => 'publish'],
    (object) ['menu' => 3, 'ID' => 12, 'title' => 'Skis', 'url' => '/skis/', 'menu_item_parent' => '5', 'menu_order' => 2, 'type' => 'custom', 'object' => 'custom', 'object_id' => 12, 'target' => '', 'classes' => [''], 'description' => '', 'attr_title' => '', 'xfn' => '', 'post_status' => 'publish'],
];

$gateway = new ProExtended\Menus\MenuGateway();
$renamed = $gateway->apply(3, MenuItems::normalize([['op' => 'update', 'item' => 12, 'title' => 'Ski rentals']])['operations'], false, false);
T::same([], $renamed['errors'], 'renaming a child item succeeds');
T::same('5', (string) (WpStub::$menuWrites[0]['args']['menu-item-parent-id'] ?? 'missing'), 'and sends the parent it already has');
T::same('Ski rentals', WpStub::$menuWrites[0]['args']['menu-item-title'] ?? null, 'with the new title');

WpStub::$menuWrites = [];
$twice = $gateway->apply(3, MenuItems::normalize([
    ['op' => 'move', 'item' => 12, 'parent' => 0],
    ['op' => 'update', 'item' => 12, 'title' => 'Skis'],
])['operations'], false, false);
T::same([], $twice['errors'], 'a move and then an update succeed');
T::same(0, (int) WpStub::$menuWrites[1]['args']['menu-item-parent-id'], 'the update keeps the parent the move gave it in the same call');

WpStub::$menuWrites = [];
$preview = $gateway->apply(3, MenuItems::normalize([['op' => 'update', 'item' => 12, 'title' => 'X']])['operations'], true, false);
T::same(5, $preview['applied'][0]['would_write']['menu-item-parent-id'] ?? null, 'a dry run shows the parent it would keep');
T::same([], WpStub::$menuWrites, 'and writes nothing');

WpStub::reset();
