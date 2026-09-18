<?php

declare(strict_types=1);

use ProExtended\Site\Extensions;
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

T::group('Extensions');

$present = [
    'active_plugins' => ['cornerstone-data-tables/cornerstone-data-tables.php'],
    'classes'        => ['Tribe__Events__Main'],
    'constants'      => ['CS_DATA_TABLES_VERSION' => '1.4.0', 'TRIBE_EVENTS_FILE' => '/x/y.php'],
    'elements'       => ['data-table', 'headline'],
    'dc_groups'      => ['datatable', 'post'],
    'loopers'        => ['posts'],
    'post_types'     => ['tribe_events', 'page'],
];

$report = Extensions::describe($present);

T::ok($report['data_tables']['active'], 'an extension is found by its plugin file');
T::same('1.4.0', $report['data_tables']['version'], 'and reports its version');
T::same('plugin', $report['data_tables']['detected_by'], 'and how it was found');
T::same(['data-table'], $report['data_tables']['elements']['present'], 'the elements it contributes that this site has');
T::ok(in_array('cs-data-table', $report['data_tables']['elements']['missing'], true), 'and the ones it does not');
T::same(['datatable'], $report['data_tables']['dynamic_content']['present'], 'its token group is found');

T::ok($report['events_calendar']['active'], 'an extension is also found by its class');
T::same('class', $report['events_calendar']['detected_by'], 'and says so');
T::same(['tribe_events'], $report['events_calendar']['post_types']['present'], 'its post types are reported');

T::ok(! $report['charts']['active'], 'an absent extension is reported inactive');
T::same(null, $report['charts']['version'], 'with no version');
T::ok(! isset($report['charts']['elements']), 'and nothing is claimed about what it contributes');

T::same(count(Extensions::KNOWN), count($report), 'every known extension is reported either way');
T::same([], Extensions::describe([])['forms']['active'] ? ['unexpected'] : [], 'an empty site activates nothing');
