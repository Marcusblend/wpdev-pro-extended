<?php

declare(strict_types=1);

use ProExtended\Settings\ThemeOptionsWriter;

T::group('ThemeOptionsWriter');

$registered = ['x_layout_site', 'x_layout_content', 'x_navbar_width', 'x_custom_styles', 'x_stack'];
$current = ['x_layout_site' => 'full-width', 'x_layout_content' => 'sidebar', 'x_navbar_width' => '1200px'];

// What gets written -------------------------------------------------------

$plan = ThemeOptionsWriter::plan(['x_layout_site' => 'boxed'], $registered, $current);
T::same([], $plan['errors'], 'a registered key with a new value is allowed');
T::same(1, count($plan['writes']), 'and is planned as one write');
T::same('full-width', $plan['writes'][0]['from'], 'the write carries the old value');
T::same('boxed', $plan['writes'][0]['to'], 'and the new one');
T::ok(! $plan['writes'][0]['responsive'], 'a plain key is not responsive');

$same = ThemeOptionsWriter::plan(['x_layout_site' => 'full-width'], $registered, $current);
T::same([], $same['writes'], 'a value that already holds is not written');
T::same(['x_layout_site'], $same['unchanged'], 'and is reported as unchanged');

$numeric = ThemeOptionsWriter::plan(['x_navbar_width' => '1200px'], $registered, $current);
T::same([], $numeric['writes'], 'scalars are compared as strings, so 1200px matches 1200px');

// Responsive variants -------------------------------------------------------

$responsive = ThemeOptionsWriter::plan(['x_navbar_width_bp_data4_4' => ['900px', '100%']], $registered, $current);
T::same([], $responsive['errors'], 'a responsive variant of a registered key is allowed');
T::ok($responsive['writes'][0]['responsive'], 'and is marked responsive');
T::same('x_navbar_width', ThemeOptionsWriter::baseKey('x_navbar_width_bp_data4_4'), 'the base key is found');
T::same('x_layout_site', ThemeOptionsWriter::baseKey('x_layout_site'), 'a plain key is its own base');

// What gets refused ---------------------------------------------------------

foreach (['x_custom_styles', 'cornerstone_color_items', 'cornerstone_font_items', 'x_breakpoint_base', 'x_breakpoint_ranges', 'x_stack'] as $refused) {
    $result = ThemeOptionsWriter::plan([$refused => 'x'], $registered, $current);
    T::same(1, count($result['errors']), sprintf('"%s" is refused', $refused));
    T::same([], $result['writes'], sprintf('"%s" plans no write', $refused));
}

$unknown = ThemeOptionsWriter::plan(['not_registered' => 'x'], $registered, $current);
T::same(1, count($unknown['errors']), 'an unregistered key is refused');

$responsiveRefused = ThemeOptionsWriter::plan(['x_custom_styles_bp_data4_4' => 'x'], $registered, $current);
T::same(1, count($responsiveRefused['errors']), 'a responsive variant of a refused key is refused too');

$object = ThemeOptionsWriter::plan(['x_layout_site' => new stdClass()], $registered, $current);
T::same(1, count($object['errors']), 'a value that cannot be stored is refused');

$nested = ThemeOptionsWriter::plan(['x_layout_site' => ['a' => ['b' => 1, 'c' => null]]], $registered, $current);
T::same([], $nested['errors'], 'a nested array of scalars is storable');

// A batch keeps the good and reports the bad --------------------------------

$mixed = ThemeOptionsWriter::plan(
    ['x_layout_site' => 'boxed', 'x_stack' => 'ethos', 'nope' => 1],
    $registered,
    $current
);
T::same(2, count($mixed['errors']), 'every bad key is reported');
T::same(1, count($mixed['writes']), 'and the good one is still planned, for the caller to refuse on');
