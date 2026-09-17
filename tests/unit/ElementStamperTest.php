<?php

declare(strict_types=1);

use ProExtended\Elements\ElementStamper;

T::group('ElementStamper');

$versions = ['section' => 2, 'layout-row' => 2, 'layout-column' => 2, 'text' => 1, 'bar' => 1, 'container' => 2, 'layout-grid' => 1];

$tree = [[
    '_type'    => 'section',
    '_modules' => [[
        '_type'    => 'layout-row',
        '_modules' => [[
            '_type'    => 'layout-column',
            '_modules' => [
                ['_type' => 'text', 'text_content' => 'Hi'],
                ['_type' => 'headline', 'text_content' => 'Title'],
            ],
        ]],
    ]],
]];

$stamper = new ElementStamper($versions, '4_4');
$out = $stamper->stampTree($tree);
T::same(['e' => 2], $out[0]['_m'], 'stamps the section with its migration version');
T::same('4_4', $out[0]['_bp_base'], 'stamps the section with the site breakpoint tag');
T::same(['e' => 1], $out[0]['_modules'][0]['_modules'][0]['_modules'][0]['_m'], 'stamps nested elements');
T::ok(! isset($out[0]['_modules'][0]['_modules'][0]['_modules'][1]['_m']), 'a type without migrations gets no _m');
T::same('4_4', $out[0]['_modules'][0]['_modules'][0]['_modules'][1]['_bp_base'], 'every element gets _bp_base');
T::same(['elements' => 5, '_m' => 4, '_bp_base' => 5], $stamper->counts(), 'counts the stamps');
T::same('Hi', $out[0]['_modules'][0]['_modules'][0]['_modules'][0]['text_content'], 'keeps the element values');

// Existing markers are never changed.
$legacy = [['_type' => 'section', '_m' => ['e' => 1], '_bp_base' => '3_4', '_modules' => []]];
$stamper = new ElementStamper($versions, '4_4');
T::same($legacy, $stamper->stampTree($legacy), 'keeps existing _m and _bp_base values');
T::same(['elements' => 1, '_m' => 0, '_bp_base' => 0], $stamper->counts(), 'counts nothing for a marked element');

$partial = ['_type' => 'text', '_m' => []];
$stamped = (new ElementStamper($versions, '4_4'))->stampElement($partial);
T::same(['e' => 1], $stamped['_m'], 'fills a _m without "e"');

// Responsive data keeps its own tag.
$tagged = ['_type' => 'text', '_bp_data3_4' => ['text_font_size' => ['1em', null, null, null, null]]];
$stamped = (new ElementStamper($versions, '4_4'))->stampElement($tagged);
T::same('3_4', $stamped['_bp_base'], 'uses the tag of the element\'s own _bp_data key');
T::same('3_4', ElementStamper::dataTag($tagged), 'dataTag reads a single _bp_data key');
T::same(null, ElementStamper::dataTag(['_bp_data4_4' => [], '_bp_data3_4' => []]), 'dataTag is null for two keys');
T::same(null, ElementStamper::dataTag(['_type' => 'text']), 'dataTag is null without data');

// Regions and flat component maps.
$regions = ['top' => [['_type' => 'bar', '_region' => 'top', '_modules' => [['_type' => 'container', '_region' => 'top']]]], 'left' => []];
$stamped = (new ElementStamper($versions, '4_4'))->stampRegions($regions);
T::same(['e' => 1], $stamped['top'][0]['_m'], 'stamps a bar in a header region');
T::same(['e' => 2], $stamped['top'][0]['_modules'][0]['_m'], 'stamps the container in the bar');
T::same([], $stamped['left'], 'keeps empty regions');

$flat = [
    'e0' => ['_type' => 'root', '_modules' => ['e1']],
    'e1' => ['_type' => 'region', '_region' => 'content', '_modules' => ['e2']],
    'e2' => ['_type' => 'layout-grid', '_modules' => ['e3'], '_parent' => 'e1'],
    'e3' => ['_type' => 'text', '_modules' => [], '_parent' => 'e2'],
];
$stamper = new ElementStamper($versions, '4_4');
$stamped = $stamper->stampFlat($flat);
T::ok(! isset($stamped['e0']['_bp_base']) && ! isset($stamped['e1']['_bp_base']), 'root and region are not stamped');
T::same(['e' => 1], $stamped['e2']['_m'], 'stamps flat elements');
T::same(['e3'], $stamped['e2']['_modules'], 'leaves flat child ID lists alone');
T::same(['elements' => 2, '_m' => 2, '_bp_base' => 2], $stamper->counts(), 'counts flat stamps');

// Dry run.
$dry = new ElementStamper($versions, '4_4', true);
T::same($tree, $dry->stampTree($tree), 'a dry run changes nothing');
T::same(['elements' => 5, '_m' => 4, '_bp_base' => 5], $dry->counts(), 'a dry run counts what is missing');

// Odd input.
$odd = [['no_type' => true], 'string', ['_type' => '', 'x' => 1], ['_type' => 'text', '_modules' => 'e4']];
$stamped = (new ElementStamper($versions, '4_4'))->stampTree($odd);
T::same($odd[0], $stamped[0], 'skips an element without _type');
T::same('string', $stamped[1], 'skips non-array entries');
T::same('e4', $stamped[3]['_modules'], 'ignores a non-array _modules');

// Any storage shape.
$stamper = new ElementStamper($versions, '4_4');
$doc = $stamper->stampData(['settings' => ['x' => 1], 'regions' => $regions], false);
T::same(['e' => 1], $doc['regions']['top'][0]['_m'], 'stampData stamps a regions envelope');
T::same(['x' => 1], $doc['settings'], 'stampData keeps settings');
$doc = (new ElementStamper($versions, '4_4'))->stampData(['elements' => $flat, 'settings' => []], true);
T::same(['e' => 1], $doc['elements']['e2']['_m'], 'stampData stamps a component envelope');
$doc = (new ElementStamper($versions, '4_4'))->stampData($flat, true);
T::same(['e' => 1], $doc['e2']['_m'], 'stampData stamps a bare flat map');
$doc = (new ElementStamper($versions, '4_4'))->stampData($tree, false);
T::same(['e' => 2], $doc[0]['_m'], 'stampData stamps a page list');
$doc = (new ElementStamper($versions, '4_4'))->stampData(['_type' => 'bar'], false);
T::same(['e' => 1], $doc['_m'], 'stampData stamps a single element');
T::same(['foo' => 'bar'], (new ElementStamper($versions, '4_4'))->stampData(['foo' => 'bar'], false), 'stampData leaves unknown shapes alone');
