<?php

declare(strict_types=1);

use ProExtended\Settings\GlobalParameters;
use ProExtended\Settings\VariableItems;

T::group('VariableItems');

$stored = [
    ['id' => 'brand', 'value' => '#0a7d4b'],
    ['id' => 'gutter', 'value' => '2rem'],
];

// Adding and changing -------------------------------------------------------

$merged = VariableItems::merge($stored, ['accent' => '#c0392b']);
T::same([], $merged['errors'], 'a map of name => value is accepted');
T::same(['accent'], $merged['added'], 'the new name is reported as added');
T::same(3, count($merged['items']), 'and joins the stored list');
T::same('brand', $merged['items'][0]['id'], 'existing variables keep their order');
T::same('accent', $merged['items'][2]['id'], 'new ones go last');

$changed = VariableItems::merge($stored, ['brand' => '#000000']);
T::same(['brand'], $changed['changed'], 'an existing name with a new value is a change');
T::same([], $changed['added'], 'and is not an addition');
T::same(2, count($changed['items']), 'the list does not grow');

$noop = VariableItems::merge($stored, ['brand' => '#0a7d4b']);
T::same([], $noop['changed'], 'the same value is not a change');

$list = VariableItems::merge($stored, [['id' => 'accent', 'value' => 'red']]);
T::same(['accent'], $list['added'], 'a list of objects works too');

// Names ---------------------------------------------------------------------

$dashes = VariableItems::merge([], ['--brand' => 'red']);
T::same('brand', $dashes['items'][0]['id'], 'a leading -- is dropped from the stored name');
T::same('--brand', VariableItems::property('brand'), 'the property adds it back');
T::same('var(--brand)', VariableItems::reference('brand'), 'and the reference wraps it');
T::same('--brand', VariableItems::property('--brand'), 'a name that already has it is not doubled');

foreach (['9lead', 'has space', '', 'has.dot', '-'] as $bad) {
    $result = VariableItems::merge([], [$bad => 'x']);
    T::same(1, count($result['errors']), sprintf('"%s" is refused as a variable name', $bad));
}

T::same([], VariableItems::merge([], ['_private' => 'x'])['errors'], 'a leading underscore is allowed');

// Breakpoints ---------------------------------------------------------------

$responsive = VariableItems::merge($stored, [['id' => 'gutter', 'value' => '2rem', 'breakpoints' => ['1.5rem', '1rem']]]);
T::same(['1.5rem', '1rem'], $responsive['items'][1]['_bp']['value'], 'per-breakpoint values are stored under _bp');
T::same(['gutter'], $responsive['changed'], 'adding breakpoints counts as a change');

$badBp = VariableItems::merge($stored, [['id' => 'gutter', 'value' => '2rem', 'breakpoints' => 'nope']]);
T::same(1, count($badBp['errors']), 'breakpoints must be a list');

// Removing ------------------------------------------------------------------

$removed = VariableItems::merge($stored, [], ['brand']);
T::same(['brand'], $removed['removed'], 'a stored name is removed');
T::same(1, count($removed['items']), 'and leaves the rest');
T::same([], VariableItems::merge($stored, [], ['nothere'])['removed'], 'removing a name that is not there is quiet');

T::group('GlobalParameters');

// The _bp_base trap ---------------------------------------------------------

$prepared = GlobalParameters::prepare(
    ['gutter' => ['type' => 'unit']],
    ['gutter' => '2rem', '_bp_data4_4' => ['gutter' => ['1rem']]],
    '4_4'
);
T::same([], $prepared['errors'], 'a schema and values pair is accepted');
T::same(['gutter'], $prepared['parameters'], 'the schema names the parameters');
T::same(['gutter'], $prepared['responsive'], 'and the data says which vary by breakpoint');
T::same('4_4', $prepared['data']['_bp_base'], 'a missing _bp_base is filled in');
T::same(1, count($prepared['warnings']), 'and saying so is a warning');

$withBase = GlobalParameters::prepare(
    ['gutter' => []],
    ['gutter' => '2rem', '_bp_base' => '4_4', '_bp_data4_4' => ['gutter' => ['1rem']]],
    '4_4'
);
T::same([], $withBase['warnings'], 'a correct _bp_base needs no warning');

$otherBase = GlobalParameters::prepare(['gutter' => []], ['_bp_base' => '3_4'], '4_4');
T::same(1, count($otherBase['warnings']), 'another site\'s breakpoint tag is flagged');

$unknown = GlobalParameters::prepare(['gutter' => []], ['_bp_base' => '4_4', '_bp_data4_4' => ['ghost' => ['1rem']]], '4_4');
T::same(1, count($unknown['warnings']), 'a responsive value with no parameter behind it is flagged');

$flat = GlobalParameters::prepare(['a' => [], 'b' => []], ['a' => 1], '4_4');
T::same([], $flat['warnings'], 'values with no breakpoints need no base');
T::same(['a', 'b'], $flat['parameters'], 'every schema key is a parameter');

// Bad input -----------------------------------------------------------------

T::same(1, count(GlobalParameters::prepare('{not json', [], '4_4')['errors']), 'a schema that is not JSON is refused');
T::same(1, count(GlobalParameters::prepare(5, [], '4_4')['errors']), 'a schema that is not an object or string is refused');
T::same(1, count(GlobalParameters::prepare([], 'nope', '4_4')['errors']), 'values that are not JSON are refused');
T::same([], GlobalParameters::prepare('', null, '4_4')['errors'], 'empty input is allowed, and clears them');
