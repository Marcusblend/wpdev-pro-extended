<?php

declare(strict_types=1);

use ProExtended\Cornerstone\ComponentScanner;

T::group('ComponentScanner');

$elements = [
    'e0' => ['_type' => 'root', '_modules' => ['e1']],
    'e1' => ['_type' => 'region', '_region' => 'content', '_modules' => ['e2', 'e5', 'e7']],
    'e2' => ['_type' => 'layout-div', '_c_export' => true, '_c_id' => 'peTestCard ', '_label' => 'Card', '_c_prefab' => true, '_p_json' => '{"cardSetup#":{"title":"text|Hello"},"items[]":{},"plain":"text|x"}', '_modules' => ['e3']],
    'e3' => ['_type' => 'layout-div', '_c_slot' => true, '_c_id' => 'slotA', '_label' => 'Body', '_modules' => ['e4']],
    'e4' => ['_type' => 'text'],
    'e5' => ['_type' => 'layout-div', '_c_export' => true, '_c_id' => 'wrapper', '_label' => 'Wrapper', '_c_slot' => true, '_modules' => []],
    'e6' => ['_type' => 'text', '_c_export' => true, '_c_id' => 'orphan', '_label' => 'Not reachable'],
    'e7' => ['_type' => 'text', '_c_export' => true, '_c_id' => 'nolabel', '_label' => ''],
];

$scan = ComponentScanner::scan($elements);
T::same(['peTestCard', 'wrapper'], array_keys($scan['components']), 'finds reachable, labelled exports and trims IDs');
T::same(['e3' => 'slotA'], $scan['components']['peTestCard']['slots'], 'finds slots');
T::same(false, $scan['components']['peTestCard']['children'], 'a component with a slot inside does not take children directly');
T::same(true, $scan['components']['wrapper']['children'], 'a component that is its own slot accepts children');
T::same(true, $scan['components']['peTestCard']['prefab'], 'reads the prefab flag');
T::same(['cardSetup', 'items', 'plain'], ComponentScanner::declaredParameters($elements['e2']['_p_json']), 'normalizes # and [] parameter keys');
T::same(null, ComponentScanner::declaredParameters(null), 'no _p_json means no declared parameters');
T::same(null, ComponentScanner::declaredParameters('{broken'), 'invalid _p_json is unreadable');

$dupes = ComponentScanner::scan([
    'e0' => ['_type' => 'root', '_modules' => ['e1', 'e2']],
    'e1' => ['_type' => 'x', '_c_export' => true, '_c_id' => 'same', '_label' => 'One'],
    'e2' => ['_type' => 'x', '_c_export' => true, '_c_id' => 'same', '_label' => 'Two'],
]);
T::same(['same' => 2], $dupes['duplicates'], 'reports a _c_id used twice in one document');

$cyclic = ComponentScanner::scan(['e0' => ['_type' => 'root', '_modules' => ['e0']]]);
T::same([], $cyclic['components'], 'survives a cycle');
