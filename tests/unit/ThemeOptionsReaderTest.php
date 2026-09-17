<?php

declare(strict_types=1);

use ProExtended\Settings\ThemeOptionsReader;

T::group('ThemeOptionsReader');

$controls = [[
    'type'     => 'group-module',
    'label'    => 'Options',
    'controls' => [
        [
            'type'     => 'group-sub-module',
            'label'    => 'Typography',
            'options'  => ['tag' => 'typography', 'name' => 'x-theme-options:typography'],
            'controls' => [
                ['type' => 'group', 'label' => 'Body', 'controls' => [
                    ['key' => 'x_body_font_family_selection', 'type' => 'font-family', 'label' => 'Font Family'],
                    ['keys' => ['value' => 'x_body_font_color', 'alt' => 'x_body_font_color_alt'], 'type' => 'color', 'label' => 'Color'],
                ]],
            ],
        ],
        [
            'type'     => 'group-sub-module',
            'label'    => 'Custom Assets',
            'options'  => [],
            'controls' => [
                ['key' => 'cs_custom_scripts', 'type' => 'list', 'label' => 'Scripts', 'controls' => [
                    ['key' => 'src', 'label' => 'Source URL'],
                ]],
            ],
        ],
        'junk',
    ],
]];

$registered = ['x_body_font_family_selection', 'x_body_font_color', 'cs_custom_scripts', 'x_stack'];
$sections = ThemeOptionsReader::parseSections($controls, $registered);

T::same(['typography', 'custom-assets'], array_keys($sections), 'reads section tags, deriving one from the label when missing');
T::same('Typography', $sections['typography']['label'], 'keeps the section label');
T::same(['x_body_font_family_selection' => 'Font Family', 'x_body_font_color' => 'Color'], $sections['typography']['keys'], 'maps key and keys controls, registered keys only');
T::same(['cs_custom_scripts' => 'Scripts'], $sections['custom-assets']['keys'], 'skips list item keys that are not options');

// describe()
$row = ThemeOptionsReader::describe('x_body_font_color', '#333', '#000', 'style:color');
T::same(['key' => 'x_body_font_color', 'designation' => 'style:color', 'changed' => true, 'value' => '#333', 'default' => '#000'], $row, 'describes a changed value');
T::same(false, ThemeOptionsReader::describe('x_enable', '', false, 'markup')['changed'], 'an empty string equals a false default');
T::same(false, ThemeOptionsReader::describe('x_enable', '1', true, 'markup')['changed'], '"1" equals a true default');
T::same(false, ThemeOptionsReader::describe('x_size', '16', 16, 'markup')['changed'], '"16" equals 16');
T::same(true, ThemeOptionsReader::describe('x_list', ['a'], [], 'markup')['changed'], 'a changed list is changed');

foreach (['x_product_validation_key', 'cs_api_endpoints', 'x_google_api_key', 'some_token', 'x_license'] as $secretKey) {
    $secret = ThemeOptionsReader::describe($secretKey, 'SECRET-VALUE', '', 'markup');
    T::ok(($secret['redacted'] ?? false) === true && $secret['value'] === null && ! str_contains((string) json_encode($secret), 'SECRET-VALUE'), "redacts {$secretKey}");
}

T::same(true, ThemeOptionsReader::describe('cs_api_endpoints', [['headers' => 'x']], [], 'markup')['set'], 'reports that a redacted value is set');
T::ok(! isset(ThemeOptionsReader::describe('x_keystone_mode', 'on', 'off', 'markup')['redacted']), 'does not redact a key that merely contains "key"');

$code = ThemeOptionsReader::describe('x_custom_styles', 'body { color: red; }', '', 'markup');
T::ok($code['value'] === null && $code['bytes'] === 20, 'reports Global CSS by size');

$big = ThemeOptionsReader::describe('cs_twig_templates', array_fill(0, 500, 'template body text'), [], 'markup');
T::ok($big['value'] === null && ($big['truncated'] ?? false) === true && $big['bytes'] > ThemeOptionsReader::MAX_VALUE_BYTES, 'reports a large value by size');

T::same('1', ThemeOptionsReader::normalize(true), 'normalize true');
T::same('', ThemeOptionsReader::normalize(null), 'normalize null');
T::same('["a"]', ThemeOptionsReader::normalize(['a']), 'normalize a list');
