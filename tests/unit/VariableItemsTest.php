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

// Stored items the call does not touch ------------------------------------------

$odd = [
    ['id' => 'brand', 'value' => '#0a7d4b'],
    ['id' => '9lead', 'value' => '1px', 'note' => ['kept' => true]],
    ['id' => '--spacing', 'value' => '1rem', '_bp' => ['value' => ['1rem', '0.5rem']]],
    'not an item',
    ['value' => 'no id at all'],
    ['id' => 'brand', 'value' => '#111111'],
    ['id' => 'has space', 'value' => 'x'],
];

$unrelated = VariableItems::merge($odd, ['accent' => 'red']);
T::same([], $unrelated['errors'], 'an unrelated write is not refused over stored names the pattern rejects');
T::same(count($odd) + 1, count($unrelated['items']), 'nothing stored is dropped');
T::same(serialize(array_slice($odd, 0, count($odd))), serialize(array_slice($unrelated['items'], 0, count($odd))), 'every stored item survives byte-identical, in its place');
T::same(['id' => 'accent', 'value' => 'red'], $unrelated['items'][count($odd)], 'and the new one is added after them');

$renamedNot = VariableItems::merge($odd, ['spacing' => '2rem']);
T::same('--spacing', $renamedNot['items'][2]['id'], 'a stored name with a leading "--" keeps its spelling when its value changes');
T::same('2rem', $renamedNot['items'][2]['value'], 'and takes the new value');
T::same(['1rem', '0.5rem'], $renamedNot['items'][2]['_bp']['value'], 'and keeps its breakpoints');
T::same(['spacing'], $renamedNot['changed'], 'it is reported by its name');
T::same($odd[1], $renamedNot['items'][1], 'while the item the pattern rejects is untouched');

$both = VariableItems::merge($odd, ['brand' => '#222222']);
T::same('#222222', $both['items'][0]['value'], 'a duplicated name is changed where it first appears');
T::same('#222222', $both['items'][5]['value'], 'and where it appears again, so the two cannot disagree');
T::same(['brand'], $both['changed'], 'and reported once');

$dropBrand = VariableItems::merge($odd, [], ['brand']);
T::same(count($odd) - 2, count($dropBrand['items']), 'removing a name removes every item that carries it');
T::same($odd[1], $dropBrand['items'][0], 'and nothing else');

$badNew = VariableItems::merge($odd, ['also bad' => 'x']);
T::same(1, count($badNew['errors']), 'a name the pattern rejects is still refused when it is being added');

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

// The schema keeps its shape --------------------------------------------------------

$storedSchema = '{"card#":{},"title":"text|Café \/ Bar","panel":{"type":"group","params":{},"initial":{}},"rows":{"type":"group[]","initial":[]}}';

$dataOnly = GlobalParameters::prepare($storedSchema, ['title' => 'New'], '4_4');
T::same([], $dataOnly['errors'], 'a data-only write against a stored schema is accepted');
T::same($storedSchema, $dataOnly['json'], 'and leaves the stored schema string byte-identical');

T::same('{}', GlobalParameters::prepare('{}', ['a' => 1], '4_4')['json'], 'an empty schema stays {}');
T::same('', GlobalParameters::prepare('', ['a' => 1], '4_4')['json'], 'an empty option stays empty');

$decoded = json_decode($storedSchema, true);
$reencoded = GlobalParameters::encodeSchema($decoded);
T::same('{"card#":{},"title":"text|Café / Bar","panel":{"type":"group","params":{},"initial":{}},"rows":{"type":"group[]","initial":[]}}', $reencoded, 'a schema that arrives decoded is encoded with its objects put back');
T::ok(str_contains((string) $reencoded, '"card#":{}'), 'a "name#" group\'s params are an object');
T::ok(str_contains((string) $reencoded, '"params":{}') && str_contains((string) $reencoded, '"initial":{}'), 'as are a group\'s params and initial value');
T::ok(str_contains((string) $reencoded, '"initial":[]'), 'while a group[]\'s initial rows stay a list');
T::same('{}', GlobalParameters::encodeSchema([]), 'an empty decoded schema is {}');
T::same('{"a":{"type":"select","options":[]}}', GlobalParameters::encodeSchema(['a' => ['type' => 'select', 'options' => []]]), 'a list of options stays a list');
T::same('x', GlobalParameters::encodeSchema('x'), 'a string is never re-encoded');

$fromArray = GlobalParameters::prepare(['gutter' => ['type' => 'group', 'params' => []]], [], '4_4');
T::same('{"gutter":{"type":"group","params":{}}}', $fromArray['json'], 'prepare() encodes a decoded schema the same way');

// The tool keeps a string it is given as it is ---------------------------------------

WpStub::reset();
WpStub::$options[GlobalParameters::JSON_OPTION] = $storedSchema;

$setParameters = new ProExtended\Mcp\Tools\SetGlobalParameters(
    new ProExtended\Cornerstone\DocumentGateway(),
    new ProExtended\Settings\SettingsBackups(new ProExtended\Cornerstone\DocumentGateway()),
    new ProExtended\Cornerstone\ElementContext(new ProExtended\Elements\SchemaExtractor())
);

$preview = $setParameters->execute(['data' => ['title' => 'New'], 'dry_run' => true]);
T::same(strlen($storedSchema), $preview['json_bytes'], 'set_global_parameters with only data would write the stored schema unchanged');

$asString = $setParameters->execute(['json' => '{"a":{}}', 'dry_run' => true]);
T::same(strlen('{"a":{}}'), $asString['json_bytes'], 'and a schema passed as a string is kept as that string');

WpStub::reset();
