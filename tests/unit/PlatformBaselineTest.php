<?php

declare(strict_types=1);

use ProExtended\Site\PlatformBaseline;

T::group('PlatformBaseline');

$before = [
    'cornerstone_version' => '7.9.3',
    'pro_version'         => '6.9.3',
    'breakpoint_tag'      => '4_4',
    'element_types'       => ['headline', 'section', 'layout-div'],
    'document_types'      => ['layout:header', 'layout:footer'],
    'migrations'          => ['headline' => 1, 'section' => 2],
    'theme_option_keys'   => ['x_layout_site'],
    'dynamic_content_groups' => ['post', 'user'],
    'looper_types'        => ['posts'],
    'permissions'         => ['layout'],
];

T::same(['drifted' => false, 'changes' => []], PlatformBaseline::diff(null, $before), 'with no baseline there is nothing to compare');
T::same(false, PlatformBaseline::diff($before, $before)['drifted'], 'an identical snapshot has not drifted');
T::same('No drift since the stored baseline.', PlatformBaseline::summarize([]), 'and says so');

// A release that adds an element and moves a migration ----------------------

$after = $before;
$after['cornerstone_version'] = '7.9.4';
$after['element_types'][] = 'table';
$after['migrations']['section'] = 3;

$diff = PlatformBaseline::diff($before, $after);
T::ok($diff['drifted'], 'a new version and a new element count as drift');

$kinds = array_column($diff['changes'], 'kind');
T::ok(in_array('version', $kinds, true), 'the version change is reported');
T::ok(in_array('added', $kinds, true), 'the added element is reported');
T::ok(in_array('migrations', $kinds, true), 'the migration bump is reported');

foreach ($diff['changes'] as $change) {
    if ($change['kind'] === 'added') {
        T::same(['table'], $change['items'], 'by name');
    }

    if ($change['kind'] === 'migrations') {
        T::same('section', $change['items'][0]['type'], 'and the migration says which type');
        T::same(3, $change['items'][0]['to'], 'and where it moved to');
    }
}

// Removal is drift too ------------------------------------------------------

$removed = $before;
$removed['element_types'] = ['headline', 'section'];
$removedDiff = PlatformBaseline::diff($before, $removed);
T::ok($removedDiff['drifted'], 'a type that disappeared is drift');
T::same('removed', $removedDiff['changes'][0]['kind'], 'reported as removed');

// Order does not matter -----------------------------------------------------

$reordered = $before;
$reordered['element_types'] = ['section', 'layout-div', 'headline'];
T::same(false, PlatformBaseline::diff($before, $reordered)['drifted'], 'the lists are compared as sets');

$newMigration = $before;
$newMigration['migrations']['table'] = 1;
T::same(false, PlatformBaseline::diff($before, $newMigration)['drifted'], 'a migration for a type that is new is left to the element list to report');

T::ok(str_contains(PlatformBaseline::summarize($diff['changes']), 'version'), 'the summary names what changed');
