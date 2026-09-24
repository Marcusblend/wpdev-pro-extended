<?php

declare(strict_types=1);

use ProExtended\Cornerstone\Prefabs;

T::group('Prefabs');

// The cache a read fills, and a write reads -----------------------------------

WpStub::reset();
$prefabs = new Prefabs();

T::same(null, $prefabs->cached('layout', 'row'), 'nothing is cached before a read');
T::ok(! $prefabs->cachedAll(), 'and the cache does not claim to hold the registry');

WpStub::$transients[Prefabs::CACHE] = ['version' => '', 'complete' => true, 'prefabs' => ['layout' => ['row' => ['_type' => 'layout-row']]]];
T::same(['_type' => 'layout-row'], $prefabs->cached('layout', 'row'), 'a cached prefab is returned');
T::ok($prefabs->cachedAll(), 'a cache filled from the whole registry says so');
T::same(null, $prefabs->cached('layout', 'nope'), 'a prefab it does not hold is null');

WpStub::$transients[Prefabs::CACHE]['version'] = '0.0.1-older';
T::same(null, $prefabs->cached('layout', 'row'), 'a cache from another Cornerstone version is ignored');
T::ok(! $prefabs->cachedAll(), 'entirely');

WpStub::reset();

// A write request never enters builder context ---------------------------------

// In its own process, because it has to define cornerstone(), which the rest
// of the suite must not see.
$output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/prefab-requests.php') . ' 2>&1');
$report = json_decode((string) $output, true);

T::ok(is_array($report), 'the request fixture ran', (string) $output);

if (is_array($report)) {
    T::same(0, $report['cold']['fired'], 'a write with nothing cached does not fire cs_before_late_data');
    T::ok(str_contains($report['cold']['errors'][0] ?? '', 'call list_prefabs with group "layout" and name "row"'), 'it is refused, pointing at list_prefabs');
    T::same(1, $report['read']['fired'], 'list_prefabs, a read, does enter builder context');
    T::same(0, $report['warm']['fired'], 'the write after it still does not');
    T::same([], $report['warm']['errors'], 'and inserts the prefab');
    T::same('layout-row', $report['warm']['data'][0]['_type'] ?? null, 'with the values the read cached');
    T::same(0, $report['missing']['fired'], 'a prefab the site does not have is looked up without it too');
    T::ok(str_contains($report['missing']['errors'][0] ?? '', 'No prefab "nope"'), 'and reported as missing, since the read cached the whole registry');
}
