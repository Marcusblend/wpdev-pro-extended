<?php

declare(strict_types=1);

use ProExtended\Site\PlatformBaseline;
use ProExtended\Site\PlatformSnapshot;

T::group('PlatformSnapshot permissions');

// The fingerprint holds permission names, not the result's own keys ----------

$answered = [
    'available' => true,
    'user_id'   => 1,
    'allowed'   => ['layout', 'content.page', 'global'],
    'denied'    => ['template', 'global.edit_custom_js'],
];

$names = PlatformSnapshot::permissionNames($answered);

T::same(['content.page', 'global', 'global.edit_custom_js', 'layout', 'template'], $names, 'allowed and denied names, sorted together');
T::ok(! in_array('available', $names, true) && ! in_array('allowed', $names, true), 'the result keys are not mistaken for permissions');

// Which way a permission went belongs to the caller, so it is not stored -----

$flipped = $answered;
$flipped['allowed'] = ['layout', 'content.page', 'global', 'template'];
$flipped['denied'] = ['global.edit_custom_js'];

T::same($names, PlatformSnapshot::permissionNames($flipped), 'another user with other answers gives the same names');

// Missing service, odd input -------------------------------------------------

T::same([], PlatformSnapshot::permissionNames(['available' => false, 'user_id' => 0, 'allowed' => [], 'denied' => []]), 'an unreadable service gives no names');
T::same([], PlatformSnapshot::permissionNames([]), 'nor does an empty result');
T::same(['layout'], PlatformSnapshot::permissionNames(['allowed' => ['layout', '', 5, null], 'denied' => ['layout']]), 'blanks, non-strings and repeats are dropped');

// A changed permission set shows up in the diff ------------------------------

$before = ['permissions' => $names];
$after = ['permissions' => PlatformSnapshot::permissionNames([
    'allowed' => ['layout', 'content.page', 'global'],
    'denied'  => ['template', 'global.edit_custom_js', 'global.document_assets'],
])];

$diff = PlatformBaseline::diff($before, $after);
T::ok($diff['drifted'], 'a permission that appears is drift');
T::same(['kind' => 'added', 'what' => 'permissions', 'items' => ['global.document_assets']], $diff['changes'][0] ?? null, 'reported by name');

$gone = PlatformBaseline::diff($before, ['permissions' => array_values(array_diff($names, ['template']))]);
T::same('removed', $gone['changes'][0]['kind'] ?? null, 'and one that disappears is reported as removed');
T::same(['template'], $gone['changes'][0]['items'] ?? null, 'by name');
