<?php

declare(strict_types=1);

use ProExtended\Settings\ItemMerger;

T::group('ItemMerger');

$stored = [
    ['_id' => 'first', 'title' => 'First', 'value' => '#000'],
    ['_id' => 'kit', 'title' => 'Kit', 'locked' => true, 'children' => ['first', 'locked1']],
    ['value' => '#fff', 'title' => 'Locked', 'locked' => true, '_id' => 'locked1'],
];

// The item at index 0 is updated in place, not appended (Cornerstone's empty($index) bug).
$m = ItemMerger::merge($stored, [['_id' => 'first', 'value' => '#111']], null, false);
T::same([], $m['errors'], 'updates the index-0 item without errors');
T::same(3, count($m['items']), 'does not duplicate the index-0 item');
T::same('#111', $m['items'][0]['value'], 'writes the new value');
T::same('First', $m['items'][0]['title'], 'keeps keys it was not asked to change');
T::same(['first'], array_column($m['updated'], '_id'), 'reports the update');

// Locked items.
$m = ItemMerger::merge($stored, [['_id' => 'locked1', 'value' => '#eee']], null, false);
T::ok($m['errors'] !== [], 'refuses to change a locked item');
$m = ItemMerger::merge($stored, [['_id' => 'locked1', 'value' => '#eee']], null, true);
T::same([], $m['errors'], 'changes a locked item with allow_locked');
T::same(true, $m['items'][2]['locked'], 'keeps the locked flag');
T::same(['value', 'title', 'locked', '_id'], array_keys($m['items'][2]), 'keeps the stored key order');
$m = ItemMerger::merge($stored, [['_id' => 'locked1', 'value' => '#fff']], null, false);
T::same([], $m['errors'], 'an identical value for a locked item is not a change');
T::same(1, $m['unchanged'], 'counts it as unchanged');

// New items and groups.
$m = ItemMerger::merge($stored, [
    ['_id' => 'newA', 'title' => 'A', 'value' => '#a00'],
    ['_id' => 'newB', 'title' => 'B', 'value' => '#0b0'],
], ['_id' => 'newGroup', 'title' => 'New'], false);
T::same([], $m['errors'], 'adds new items and a new group');
T::same(['first', 'kit', 'locked1', 'newA', 'newB', 'newGroup'], array_column($m['items'], '_id'), 'appends items in order, then the group');
T::same(['newA', 'newB'], $m['items'][5]['children'], 'the new group lists the new items');
T::same('created', $m['group']['action'], 'reports the new group');

$m = ItemMerger::merge($stored, [['_id' => 'newA', 'title' => 'A', 'value' => '#a00']], ['_id' => 'kit', 'title' => 'Kit'], false);
T::ok($m['errors'] !== [], 'adding to a locked group needs allow_locked');
$m = ItemMerger::merge($stored, [['_id' => 'newA', 'title' => 'A', 'value' => '#a00']], ['_id' => 'kit', 'title' => 'Kit'], true);
T::same(['first', 'locked1', 'newA'], $m['items'][1]['children'], 'appends new items to an existing group');

$m = ItemMerger::merge($stored, [['_id' => 'kit', 'title' => 'x']], null, true);
T::ok($m['errors'] !== [], 'refuses to edit a group through the items list');
$m = ItemMerger::merge($stored, [['_id' => 'dup', 'value' => '#1'], ['_id' => 'dup', 'value' => '#2']], null, false);
T::ok($m['errors'] !== [], 'refuses the same _id twice');
$m = ItemMerger::merge($stored, [['_id' => 'same', 'value' => '#1']], ['_id' => 'same', 'title' => 'Same'], false);
T::ok($m['errors'] !== [], 'refuses a group ID that is also an item ID');
$m = ItemMerger::merge($stored, [['_id' => 'x', 'value' => '#1']], ['_id' => 'first', 'title' => 'Nope'], false);
T::ok($m['errors'] !== [], 'refuses a group ID that belongs to a color');
$m = ItemMerger::merge([], [['_id' => 'only', 'title' => 'Only', 'value' => '#123']], ['_id' => 'grp', 'title' => 'G'], false);
T::same(['only', 'grp'], array_column($m['items'], '_id'), 'works on an empty palette');
