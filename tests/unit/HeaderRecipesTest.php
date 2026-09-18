<?php

declare(strict_types=1);

use ProExtended\Recipes\HeaderRecipes;

T::group('HeaderRecipes');

$r = HeaderRecipes::build('header.simple', ['menu' => 12, 'logo' => '5:full']);
$bar = $r['data']['regions']['top'][0];
T::same('bar', $bar['_type'], 'the top region holds a bar');
T::same('container', $bar['_modules'][0]['_type'], 'the bar holds a container');
T::same([], $r['data']['regions']['right'], 'the other regions are empty');
T::same([], $r['warnings'], 'a menu ID needs no warning');

$container = $bar['_modules'][0];
T::same('image', $container['_modules'][0]['_type'], 'the logo comes first');
T::same('5:full', $container['_modules'][0]['image_src'], 'the logo keeps its attachment reference');

$group = $container['_modules'][1];
T::same('nav-inline', $group['_modules'][0]['_type'], 'the navigation group starts with the inline nav');
T::same('menu:12', $group['_modules'][0]['menu'], 'a term ID becomes a menu reference');
T::same('xs sm md', $group['_modules'][0]['hide_bp'], 'the inline nav hides on small screens');
T::same('nav-collapsed', $group['_modules'][1]['_type'], 'a collapsed nav is added for small screens');
T::same('lg xl', $group['_modules'][1]['hide_bp'], 'the collapsed nav hides on large screens');

$r = HeaderRecipes::build('header.simple', ['menu' => 12, 'collapsed' => false]);
$group = $r['data']['regions']['top'][0]['_modules'][0]['_modules'][0];
T::same(1, count($group['_modules']), 'collapsed: false leaves only the inline nav');

$r = HeaderRecipes::build('header.simple', []);
T::ok($r['warnings'] !== [], 'a missing menu warns');
T::same('sample:default', $r['data']['regions']['top'][0]['_modules'][0]['_modules'][0]['_modules'][0]['menu'], 'and falls back to the sample menu');

$r = HeaderRecipes::build('header.simple', ['menu' => 'primary']);
T::same('location:primary', $r['data']['regions']['top'][0]['_modules'][0]['_modules'][0]['_modules'][0]['menu'], 'a bare name becomes a theme location');

// Mega menu ------------------------------------------------------------------

$panel = HeaderRecipes::build('mega_menu_panel', [
    'trigger' => 'Programs',
    'columns' => [
        ['heading' => 'Alpine', 'links' => [['label' => 'U10', 'href' => '/alpine/u10/'], ['label' => 'U12', 'href' => '/alpine/u12/']]],
        ['heading' => 'Freeski', 'links' => [['label' => 'Team', 'href' => '/freeski/team/']]],
    ],
])['data'];

T::same('layout-dropdown', $panel['_type'], 'a mega menu is a dropdown');
T::same('Programs', $panel['dropdown_anchor_text_primary_content'], 'the trigger label is set');

$grid = $panel['_modules'][0];
T::same('layout-grid', $grid['_type'], 'the panel holds a grid');
T::same('1fr 1fr', $grid['layout_grid_template_columns'], 'one grid column per menu column');
T::same(2, count($grid['_modules']), 'each column is a cell');
T::same('headline', $grid['_modules'][0]['_modules'][0]['_type'], 'a column starts with its heading');
T::same('Alpine', $grid['_modules'][0]['_modules'][0]['text_content'], 'the heading text is kept');
T::same('button', $grid['_modules'][0]['_modules'][1]['_type'], 'links are anchors');
T::same('/alpine/u10/', $grid['_modules'][0]['_modules'][1]['anchor_href'], 'a link keeps its href');
T::same(3, count($grid['_modules'][0]['_modules']), 'heading plus two links');

$mega = HeaderRecipes::build('header.mega', ['menu' => 12, 'trigger' => 'Programs', 'columns' => []]);
$group = $mega['data']['regions']['top'][0]['_modules'][0]['_modules'][0];
T::same('layout-dropdown', $group['_modules'][1]['_type'], 'header.mega puts the panel beside the navigation');

$threw = false;

try {
    HeaderRecipes::build('header.nope', []);
} catch (InvalidArgumentException) {
    $threw = true;
}

T::ok($threw, 'an unknown preset is refused');
