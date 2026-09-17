<?php

declare(strict_types=1);

use ProExtended\Site\MaxPackages;

T::group('MaxPackages');

$stored = [
    [
        'slug'         => 'cornerstone-charts',
        'plugin'       => 'cornerstone-charts/cornerstone-charts.php',
        'title'        => 'Cornerstone Charts',
        'new_version'  => '1.2.7',
        'package'      => 'https://packages.example.invalid/charts.zip?key=SECRET-KEY',
        'edge'         => ['package' => 'https://packages.example.invalid/charts-beta.zip', 'new_version' => '1.3.0-beta'],
        'purchased'    => true,
        'x-extension'  => ['logo_url' => 'https://cdn.example.invalid/logo.png', 'external_id' => 'x'],
    ],
    [
        'slug'        => 'csai',
        'plugin'      => 'csai/csai.php',
        'title'       => 'CSAI',
        'new_version' => 'https://evil.example.invalid/?package=1',
        'purchased'   => false,
    ],
    'not an entry',
];

$summary = MaxPackages::summarize($stored, ['cornerstone-charts/cornerstone-charts.php', 'csai/csai.php'], ['cornerstone-charts/cornerstone-charts.php']);
$json = (string) json_encode($summary);

T::same(2, count($summary), 'summarizes each entry and skips junk');
T::same(['slug', 'title', 'plugin', 'version', 'purchased', 'installed', 'active'], array_keys($summary[0]), 'returns only whitelisted fields');
T::same('cornerstone-charts', $summary[0]['slug'], 'sorts by slug');
T::same('1.2.7', $summary[0]['version'], 'reports the version');
T::same([true, true, true], [$summary[0]['purchased'], $summary[0]['installed'], $summary[0]['active']], 'reports purchase, install and activation');
T::same([false, true, false], [$summary[1]['purchased'], $summary[1]['installed'], $summary[1]['active']], 'an inactive, unpurchased package');
T::same(null, $summary[1]['version'], 'drops a URL found in a whitelisted field');
T::ok(! str_contains($json, 'http') && ! str_contains($json, 'SECRET') && ! str_contains($json, 'package') && ! str_contains($json, 'example.invalid'), 'no URL, key or package field leaks', $json);
T::same([], MaxPackages::summarize('', [], []), 'an empty option gives an empty list');
T::same([], MaxPackages::summarize(null, [], []), 'a missing option gives an empty list');
