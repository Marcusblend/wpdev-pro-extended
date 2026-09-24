<?php

declare(strict_types=1);

use ProExtended\Elements\HierarchyValidator;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\Tools\UpdateLayout;

T::group('UpdateLayout');

$tool = new UpdateLayout(new LayoutService(), new HierarchyValidator(new SchemaExtractor()));

/**
 * A section holding four text elements, A to D, and a second section.
 *
 * @return array<int, array<string, mixed>>
 */
function update_layout_fixture(): array
{
    $texts = array_map(static fn (string $label): array => ['_type' => 'text', '_label' => $label], ['A', 'B', 'C', 'D']);

    return [
        ['_type' => 'section', '_label' => 'S1', '_modules' => $texts],
        ['_type' => 'section', '_label' => 'S2', '_modules' => [['_type' => 'text', '_label' => 'E']]],
    ];
}

/**
 * @param  array<int, array<string, mixed>> $elements
 * @return string[]
 */
function update_layout_labels(array $elements): array
{
    return array_map(static fn (array $element): string => (string) ($element['_label'] ?? '?'), $elements);
}

// move: "to" is read before the element is taken out -------------------------

$moved = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.0', 'to' => '0._modules.2']]);
T::same([], $moved['errors'], 'a move down the same list succeeds');
T::same(['B', 'A', 'C', 'D'], update_layout_labels($moved['data'][0]['_modules']), 'moving 0 to 2 lands between the old 2nd and 3rd siblings');

$toEnd = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.0', 'to' => '0._modules.4']]);
T::same(['B', 'C', 'D', 'A'], update_layout_labels($toEnd['data'][0]['_modules']), 'moving to the length of the list puts it last');

$up = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.3', 'to' => '0._modules.1']]);
T::same(['A', 'D', 'B', 'C'], update_layout_labels($up['data'][0]['_modules']), 'a move up the list lands where the target was');

$next = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.1', 'to' => '0._modules.2']]);
T::same(['A', 'B', 'C', 'D'], update_layout_labels($next['data'][0]['_modules']), 'moving onto the next sibling leaves it where it was');

$across = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.1', 'to' => '1._modules.0']]);
T::same(['A', 'C', 'D'], update_layout_labels($across['data'][0]['_modules']), 'a move to another parent takes it out of the first');
T::same(['B', 'E'], update_layout_labels($across['data'][1]['_modules']), 'and puts it in the second, ahead of the element there');

// A top-level move whose target runs through a later sibling: the sibling
// shifts up once the element is gone, and the target follows it.
$outer = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0', 'to' => '1._modules.1']]);
T::same([], $outer['errors'], 'a move into a later sibling succeeds');
T::same(['S2'], update_layout_labels($outer['data']), 'the moved section leaves the top level');
T::same(['E', 'S1'], update_layout_labels($outer['data'][0]['_modules']), 'and lands inside the sibling it was aimed at');

// Removing first and then reading "to" would have looked for "1" in a list
// that by then held one element, and found nothing there.
T::same(['0', '_modules', '0'], UpdateLayout::afterRemoval(['0'], ['1', '_modules', '0']), 'a target inside a later sibling is shifted up');
T::same(['0', '_modules', '1'], UpdateLayout::afterRemoval(['0', '_modules', '0'], ['0', '_modules', '2']), 'a later sibling is shifted up');
T::same(['0', '_modules', '0'], UpdateLayout::afterRemoval(['0', '_modules', '2'], ['0', '_modules', '0']), 'an earlier sibling is left alone');
T::same(['1', '_modules', '3'], UpdateLayout::afterRemoval(['0', '_modules', '0'], ['1', '_modules', '3']), 'a target under another parent is left alone');
T::same(['0'], UpdateLayout::afterRemoval(['0', '_modules', '0'], ['0']), 'an ancestor is left alone');

// Refusals ------------------------------------------------------------------

$self = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0', 'to' => '0._modules.1']]);
T::ok(str_contains($self['errors'][0] ?? '', 'cannot move into itself'), 'a move into its own subtree is still refused');

$past = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.0', 'to' => '0._modules.9']]);
T::ok(str_contains($past['errors'][0] ?? '', 'past the end'), 'a target past the end of its list is refused');
T::same(update_layout_fixture(), $past['data'], 'and nothing moves');

$nowhere = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.0', 'to' => '7._modules.0']]);
T::ok($nowhere['errors'] !== [], 'a target whose parent does not exist is refused');
T::same(update_layout_fixture(), $nowhere['data'], 'before anything is taken out');

$noTo = $tool->patch(update_layout_fixture(), [['op' => 'move', 'path' => '0._modules.0']]);
T::ok(str_contains($noTo['errors'][0] ?? '', 'needs "to"'), 'a move without "to" is refused');

// wrap: the wrapper is stamped like an added element -------------------------

$stamper = new ProExtended\Elements\ElementStamper(['layout-div' => 1, 'text' => 1], '4_4');
$legacy = [['_type' => 'text', '_label' => 'Old']];

$wrapped = $tool->patch($legacy, [['op' => 'wrap', 'path' => '0', 'value' => ['_type' => 'layout-div', '_label' => 'Wrapper']]], false, $stamper);
T::same([], $wrapped['errors'], 'a wrap succeeds');
T::same(['e' => 1], $wrapped['data'][0]['_m'] ?? null, 'the wrapper carries the migration marker');
T::same('4_4', $wrapped['data'][0]['_bp_base'] ?? null, 'and the breakpoint marker');
T::same($legacy[0], $wrapped['data'][0]['_modules'][0], 'the wrapped element keeps exactly the markers it had');
T::same(1, $stamper->counts()['elements'], 'only the wrapper was stamped');

$kept = $tool->patch($legacy, [['op' => 'wrap', 'path' => '0', 'value' => ['_type' => 'layout-div', '_m' => ['e' => 0], '_bp_base' => '3_3']]], false, new ProExtended\Elements\ElementStamper(['layout-div' => 1], '4_4'));
T::same(['e' => 0], $kept['data'][0]['_m'], 'markers the wrapper already has are left alone');
T::same('3_3', $kept['data'][0]['_bp_base'], 'including its breakpoint tag');

$unstamped = $tool->patch($legacy, [['op' => 'wrap', 'path' => '0', 'value' => ['_type' => 'layout-div']]]);
T::ok(! isset($unstamped['data'][0]['_m']), 'with stamp_new off nothing is stamped');

$added = $tool->patch($legacy, [['op' => 'add', 'path' => '1', 'value' => ['_type' => 'layout-div']]], false, new ProExtended\Elements\ElementStamper(['layout-div' => 1], '4_4'));
T::same(['e' => 1], $added['data'][1]['_m'] ?? null, 'an added element is stamped the same way');
T::same('4_4', $added['data'][1]['_bp_base'] ?? null, 'with both markers');
