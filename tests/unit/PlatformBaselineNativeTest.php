<?php

declare(strict_types=1);

use ProExtended\Site\PlatformBaseline;

T::group('PlatformBaseline native registries');

$base = [
    'cornerstone_version' => '7.9.4',
    'element_types'       => ['headline'],
    'twig_functions'      => ['date', 'range'],
    'twig_filters'        => ['date', 'readtime'],
    'twig_tests'          => ['empty'],
    'condition_rules'     => ['global:today', 'single:post-type'],
    'looper_providers'    => ['json', 'query-recent'],
    'parameter_types'     => ['bg-image', 'text'],
];

T::same(false, PlatformBaseline::diff($base, $base)['drifted'], 'identical native fingerprints do not drift');

$withFilter = $base;
$withFilter['twig_filters'][] = 'slugify';
$diff = PlatformBaseline::diff($base, $withFilter);
T::ok($diff['drifted'], 'a Twig filter added by a release is drift');
T::same([['kind' => 'added', 'what' => 'twig_filters', 'items' => ['slugify']]], $diff['changes'], 'reported by name under twig_filters');

$lessRules = $base;
$lessRules['condition_rules'] = ['global:today'];
$lessRules['looper_providers'][] = 'csv';
$changes = PlatformBaseline::diff($base, $lessRules)['changes'];
T::ok(in_array(['kind' => 'removed', 'what' => 'condition_rules', 'items' => ['single:post-type']], $changes, true), 'a dropped condition rule is reported');
T::ok(in_array(['kind' => 'added', 'what' => 'looper_providers', 'items' => ['csv']], $changes, true), 'a new looper provider is reported');

$twigOff = $base;
$twigOff['twig_functions'] = null;
$twigOff['twig_filters'] = null;
$twigOff['twig_tests'] = null;
T::same(false, PlatformBaseline::diff($base, $twigOff)['drifted'], 'Twig switched off (null) is not read as removed functions');
T::same(false, PlatformBaseline::diff($twigOff, $base)['drifted'], 'nor switching it on as added ones');

$old = $base;
unset($old['twig_functions'], $old['twig_filters'], $old['twig_tests'], $old['condition_rules'], $old['looper_providers'], $old['parameter_types']);
T::same(false, PlatformBaseline::diff($old, $base)['drifted'], 'a baseline saved before these were recorded does not drift');

$types = $base;
$types['parameter_types'] = ['bg-image', 'text', 'text-format'];
T::same('added', PlatformBaseline::diff($base, $types)['changes'][0]['kind'], 'a new parameter type is drift');
