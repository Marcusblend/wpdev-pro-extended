<?php

declare(strict_types=1);

use ProExtended\Mcp\Tools\CreateComponent;

T::group('CreateComponent');

// A component document holds a flat map, not a nested tree ------------------

$flat = CreateComponent::flatten([
    ['_type' => 'layout-div', '_modules' => [
        ['_type' => 'headline', 'text_content' => 'Title'],
        ['_type' => 'text', 'text_content' => 'Body', '_modules' => []],
    ]],
    ['_type' => 'text', 'text_content' => 'After'],
]);

T::same('root', $flat['e0']['_type'], 'the map starts with a root');
T::same(['e1'], $flat['e0']['_modules'], 'which holds one region');
T::same('region', $flat['e1']['_type'], 'the region is next');
T::same('content', $flat['e1']['_region'], 'in the content region');
T::same('e0', $flat['e1']['_parent'], 'parented to the root');

T::same(['e2', 'e5'], $flat['e1']['_modules'], 'the top-level elements hang off the region, in order');
T::same('layout-div', $flat['e2']['_type'], 'the first keeps its type');
T::same('e1', $flat['e2']['_parent'], 'and is parented to the region');
T::same(['e3', 'e4'], $flat['e2']['_modules'], 'its children become ids');
T::same('headline', $flat['e3']['_type'], 'the child is in the map');
T::same('e2', $flat['e3']['_parent'], 'parented to its element');
T::same([], $flat['e3']['_modules'], 'a leaf has no children');
T::same('e3', $flat['e3']['_id'], 'every element carries its own id');
T::same('Body', $flat['e4']['text_content'], 'element values are kept');
T::same('After', $flat['e5']['text_content'], 'and so is the second top-level element');
T::same(6, count($flat), 'root, region and four elements');

foreach ($flat as $id => $element) {
    T::same($id, $element['_id'], sprintf('%s is keyed by its own id', $id));
}

// Markers survive the flattening --------------------------------------------

$marked = CreateComponent::flatten([
    ['_type' => 'layout-div', '_c_export' => true, '_c_id' => 'card', '_label' => 'Card', '_p_json' => '{"a":"text|x"}', '_modules' => []],
]);

T::ok($marked['e2']['_c_export'], 'an export marker is kept');
T::same('card', $marked['e2']['_c_id'], 'with its id');
T::same('Card', $marked['e2']['_label'], 'and its label');
T::same('{"a":"text|x"}', $marked['e2']['_p_json'], 'and its parameter schema');

// Odd input -----------------------------------------------------------------

$empty = CreateComponent::flatten([]);
T::same([], $empty['e1']['_modules'], 'no elements leaves an empty region');
T::same(2, count($empty), 'with just the root and the region');

$skipped = CreateComponent::flatten([['_type' => 'text'], 'not an element', 5]);
T::same(1, count($skipped['e1']['_modules']), 'anything that is not an element is dropped');
