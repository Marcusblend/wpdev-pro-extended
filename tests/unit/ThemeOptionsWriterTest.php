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

// Where the refused keys are written instead --------------------------------------

foreach (['x_custom_scripts', 'cs_v1_custom_js'] as $script) {
    T::ok(str_contains(ThemeOptionsWriter::REFUSED[$script], 'set_global_js'), sprintf('"%s" is pointed at set_global_js', $script));
    T::ok(! str_contains(ThemeOptionsWriter::REFUSED[$script], 'set_global_css'), sprintf('not at set_global_css, which does not write JS (%s)', $script));
}

foreach (['x_custom_styles', 'cs_v1_custom_css'] as $style) {
    T::ok(str_contains(ThemeOptionsWriter::REFUSED[$style], 'set_global_css'), sprintf('"%s" is still pointed at set_global_css', $style));
}

// Font keys (1.5.1) ------------------------------------------------------------------

$fontOptions = [];

foreach (file(dirname(__DIR__) . '/fixtures/cornerstone-7.9.4/font-theme-options.txt', FILE_IGNORE_NEW_LINES) ?: [] as $row) {
    if ($row !== '' && $row[0] !== '#') {
        [$option, $kind] = explode("\t", $row);
        $fontOptions[$option] = $kind;
    }
}

T::same(8, count($fontOptions), 'the fixture lists the four family and four weight keys');

$fontIds = ['body', 'heading'];
$fontRegistered = array_keys($fontOptions);

foreach ($fontOptions as $option => $kind) {
    $given = $kind === 'family' ? 'global-ff:heading' : 'global-fw:heading|fw-bold';
    $want = $kind === 'family' ? 'heading' : 'fw-bold';
    $plan = ThemeOptionsWriter::plan([$option => $given], $fontRegistered, [], $fontIds);
    T::same([], $plan['errors'], "{$option}: {$given} is accepted");
    T::same($want, $plan['writes'][0]['to'] ?? null, "{$option}: written as {$want}");
    T::same([['key' => $option, 'from' => $given, 'to' => $want]], $plan['normalized'], "{$option}: and the change is reported");
}

$piped = ThemeOptionsWriter::plan(['x_body_font_weight_selection' => 'body|fw-normal'], $fontRegistered, [], $fontIds);
T::same('fw-normal', $piped['writes'][0]['to'], 'a weight joined to its family with | loses the family');

$already = ThemeOptionsWriter::plan(['x_body_font_family_selection' => 'global-ff:body'], $fontRegistered, ['x_body_font_family_selection' => 'body'], $fontIds);
T::same(['x_body_font_family_selection'], $already['unchanged'], 'a normalised value that is already stored is unchanged');
T::same(1, count($already['normalized']), 'and the normalisation is still reported');

$right = ThemeOptionsWriter::plan(['x_body_font_family_selection' => 'body', 'x_body_font_weight_selection' => 'fw-normal', 'x_headings_font_family_selection' => 'inherit', 'x_logo_font_family_selection' => 'system:helveticaneue'], $fontRegistered, [], $fontIds);
T::same([], $right['errors'], 'an _id, inherit and a source:name family are accepted');
T::same([], $right['normalized'], 'and need no normalising');

$missing = ThemeOptionsWriter::plan(['x_body_font_family_selection' => 'global-ff:nope'], $fontRegistered, [], $fontIds);
T::same(1, count($missing['errors']), 'a family that is not in the Font Manager is refused');
T::ok(str_contains($missing['errors'][0], '"body", "heading"') && str_contains($missing['errors'][0], '"nope"'), 'and the error lists the valid ids', $missing['errors'][0]);
T::same([], $missing['writes'], 'and nothing is planned for it');

$stack = ThemeOptionsWriter::plan(['x_body_font_family_selection' => '"Brand Sans", sans-serif'], $fontRegistered, [], $fontIds);
T::same(1, count($stack['errors']), 'a literal stack is refused too');

$noFonts = ThemeOptionsWriter::plan(['x_body_font_family_selection' => 'body'], $fontRegistered, [], []);
T::ok(str_contains($noFonts['errors'][0] ?? '', 'add one with set_fonts'), 'with no fonts the error says to add one');

$unknownIds = ThemeOptionsWriter::plan(['x_body_font_family_selection' => 'anything'], $fontRegistered, []);
T::same([], $unknownIds['errors'], 'without the font list the family is not checked');

$responsiveFont = ThemeOptionsWriter::plan(['x_body_font_weight_selection_bp_data4_4' => [null, 'global-fw:body|fw-bold', 'fw-normal', null, null]], $fontRegistered, [], $fontIds);
T::same([null, 'fw-bold', 'fw-normal', null, null], $responsiveFont['writes'][0]['to'], 'a responsive variant is normalised value by value');
T::same('x_body_font_weight_selection_bp_data4_4', $responsiveFont['normalized'][0]['key'], 'and reported under its own key');

$responsiveBad = ThemeOptionsWriter::plan(['x_body_font_family_selection_bp_data4_4' => [null, 'global-ff:body', 'nope', null, null]], $fontRegistered, [], $fontIds);
T::same(1, count($responsiveBad['errors']), 'a responsive family value outside the Font Manager is refused');

$other = ThemeOptionsWriter::plan(['x_layout_site' => 'global-ff:body'], $registered, $current, $fontIds);
T::same([], $other['normalized'], 'other keys are never normalised');
