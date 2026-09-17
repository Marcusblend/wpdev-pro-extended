<?php

declare(strict_types=1);

use ProExtended\Layouts\LayoutOutline;

T::group('LayoutOutline');

$page = [
    ['_type' => 'section', '_label' => 'Hero', '_modules' => [
        ['_type' => 'layout-row', '_modules' => [
            ['_type' => 'layout-column', '_modules' => [
                ['_type' => 'headline', '_modules' => []],
                ['_type' => 'component', 'component_id' => 'peTestCard'],
            ]],
        ]],
    ]],
];

$o = LayoutOutline::outline($page, 4);
T::same(['0', '0._modules.0', '0._modules.0._modules.0', '0._modules.0._modules.0._modules.0', '0._modules.0._modules.0._modules.1'], array_column($o['nodes'], 'path'), 'lists page paths in update_layout format');
T::same('peTestCard', $o['nodes'][4]['component_id'], 'shows component IDs');
T::same(false, $o['truncated'], 'not truncated within max_depth');
$o = LayoutOutline::outline($page, 2);
T::same(true, $o['truncated'], 'reports truncation');
T::same(2, count($o['nodes']), 'stops at max_depth');
T::same(1, $o['nodes'][1]['children'], 'still counts children beyond max_depth');
T::same('headline', LayoutOutline::subtree($page, '0._modules.0._modules.0._modules.0')['_type'], 'returns a subtree by path');
T::throws(static fn() => LayoutOutline::subtree($page, '0._modules.9'), 'unknown paths are errors', 'not found');

$header = ['settings' => ['customCSS' => ''], 'regions' => ['top' => [['_type' => 'bar', '_modules' => [['_type' => 'container']]]], 'left' => []]];
$o = LayoutOutline::outline($header, 4);
T::same(['regions.top', 'regions.top.0', 'regions.top.0._modules.0', 'regions.left'], array_column($o['nodes'], 'path'), 'outlines regions');
T::same('container', LayoutOutline::subtree($header, 'regions.top.0._modules.0')['_type'], 'resolves region paths');
T::same(['regions.top.0', 'regions.top.0._modules.0'], array_column(LayoutOutline::outline($header, 4, 'regions.top.0')['nodes'], 'path'), 'outlines from a path');

$component = ['elements' => [
    'e0' => ['_type' => 'root', '_modules' => ['e1']],
    'e1' => ['_type' => 'region', '_region' => 'content', '_modules' => ['e2']],
    'e2' => ['_type' => 'layout-div', '_c_id' => 'abc', '_label' => 'Card', '_modules' => ['e3']],
    'e3' => ['_type' => 'text'],
    'e9' => ['_type' => 'text'],
], 'settings' => []];
$o = LayoutOutline::outline($component, 4);
T::same(['e0', 'e1', 'e2', 'e3'], array_column($o['nodes'], 'path'), 'outlines flat maps by element ID');
$sub = LayoutOutline::subtree($component, 'e2');
T::same(['e2', 'e3'], array_keys($sub['elements']), 'an element ID returns that element and its descendants');
T::same(['e2', 'e3'], array_column(LayoutOutline::outline($component, 4, 'e2')['nodes'], 'path'), 'outlines a flat subtree');
T::same([], LayoutOutline::subtree($component, 'settings'), 'dot paths still work on flat documents');

T::same(5, LayoutOutline::countElements($page), 'counts every element in a page tree');
T::same(2, LayoutOutline::countElements($header), 'counts the elements in a layout, not its settings or regions');
T::same(3, LayoutOutline::countElements($component), 'counts flat-map elements without the root and region');
T::same(0, LayoutOutline::countElements('nope'), 'counts nothing in non-arrays');
