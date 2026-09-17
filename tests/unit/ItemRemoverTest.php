<?php

declare(strict_types=1);

use ProExtended\Settings\ItemRemover;

T::group('ItemRemover');

$stored = [
    ['_id' => 'first', 'title' => 'First', 'value' => '#000'],
    ['_id' => 'second', 'title' => 'Second', 'value' => '#111'],
    ['_id' => 'grp', 'title' => 'Group', 'children' => ['first', 'second']],
    ['_id' => 'kit', 'title' => 'Kit', 'locked' => true, 'children' => ['locked1', 'second']],
    ['_id' => 'locked1', 'title' => 'Locked', 'value' => '#fff', 'locked' => true],
];

$r = ItemRemover::remove($stored, ['first'], false);
T::same([], $r['errors'], 'removes an unlocked item');
T::same(['second', 'grp', 'kit', 'locked1'], array_column($r['items'], '_id'), 'keeps every other entry in order');
T::same(['second'], $r['items'][1]['children'], 'drops the item from its group');
T::same(['first'], $r['removed'], 'reports the removal');
T::same(['grp'], $r['groups_changed'], 'reports the changed group');

$r = ItemRemover::remove($stored, ['second'], false);
T::ok($r['errors'] !== [], 'refuses to change a locked group\'s members without allow_locked');
$r = ItemRemover::remove($stored, ['second'], true);
T::same([], $r['errors'], 'changes a locked group with allow_locked');
T::same(['locked1'], $r['items'][2]['children'], 'drops the item from the locked group');
T::same(true, $r['items'][2]['locked'], 'keeps the lock');

$r = ItemRemover::remove($stored, ['locked1'], false);
T::ok($r['errors'] !== [], 'refuses to remove a locked item');
$r = ItemRemover::remove($stored, ['locked1'], true);
T::same([], $r['errors'], 'removes a locked item with allow_locked');

$r = ItemRemover::remove($stored, ['grp'], false);
T::same([], $r['errors'], 'removes a group');
T::same(['grp'], $r['removed_groups'], 'reports the removed group');
T::same([], $r['removed'], 'a group is not an item');
T::same(['first', 'second', 'kit', 'locked1'], array_column($r['items'], '_id'), 'the group\'s members stay');

$r = ItemRemover::remove($stored, ['nope'], false);
T::ok($r['errors'] !== [], 'refuses an unknown ID');
$r = ItemRemover::remove($stored, ['first', 'first'], false);
T::ok($r['errors'] !== [], 'refuses an ID listed twice');
$r = ItemRemover::remove($stored, [5], false);
T::ok($r['errors'] !== [], 'refuses a non-string ID');

T::same(3, ItemRemover::countItems($stored), 'countItems ignores groups');
T::same(0, ItemRemover::countItems([]), 'countItems of an empty list');
