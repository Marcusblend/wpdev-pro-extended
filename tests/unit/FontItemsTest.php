<?php

declare(strict_types=1);

use ProExtended\Settings\FontItems;

T::group('FontItems');

$catalog = [
    'barlowcondensed' => ['source' => 'google', 'family' => 'Barlow Condensed', 'stack' => '"Barlow Condensed", sans-serif', 'weights' => ['300', '400', '400i', '700']],
    'arial'           => ['source' => 'system', 'family' => 'Arial', 'stack' => 'Arial, sans-serif', 'weights' => ['400', '400italic', '700', '700italic']],
];

$errors = [];
$font = FontItems::complete(['_id' => 'peTestHead', 'title' => 'Head', 'family' => 'Barlow Condensed', 'source' => 'google'], [], $catalog, true, $errors);
T::same([], $errors, 'completes a Google font');
T::same('barlowcondensed', $font['name'], 'derives the Google name');
T::same('"Barlow Condensed", sans-serif', $font['stack'], 'takes the stack from the font list');
T::same(['400', '700'], [$font['weightNormal'], $font['weightBold']], 'picks the closest weights');
T::same(['400', '700'], $font['weightSelection'], 'defaults the selection');

$errors = [];
FontItems::complete(['_id' => 'peBad', 'title' => 'X', 'family' => 'Barlow Condensed', 'source' => 'google', 'name' => 'barlow'], [], $catalog, true, $errors);
T::ok($errors !== [], 'rejects a wrong Google name');

$errors = [];
FontItems::complete(['_id' => 'peMissing', 'title' => 'X', 'family' => 'Nope Sans', 'source' => 'google'], ['googleDisabled' => true], $catalog, true, $errors);
T::ok($errors !== [] && str_contains($errors[0], 'disabled'), 'explains that Google Fonts are disabled', implode(' ', $errors));

$errors = [];
$sys = FontItems::complete(['_id' => 'peSys', 'title' => 'Sys', 'family' => 'Arial', 'source' => 'system', 'weightSelection' => ['400', '400italic']], [], $catalog, true, $errors);
T::same([], $errors, 'accepts system fonts with italic weight names');
T::same('arial', $sys['name'], 'derives the system name');

$errors = [];
$kit = FontItems::complete(['_id' => 'peKit', 'title' => 'Kit', 'family' => 'Agenda One', 'source' => 'typekit'], ['typekitItems' => [['family' => 'Agenda One', 'stack' => '"agenda-one",sans-serif', 'weights' => ['400', '700']]]], $catalog, true, $errors);
T::same([], $errors, 'completes an Adobe Fonts entry from typekitItems');
T::same('Agenda One', $kit['name'], 'typekit name is the family');
T::same('"agenda-one",sans-serif', $kit['stack'], 'typekit stack comes from the kit');

$errors = [];
FontItems::complete(['_id' => 'peKit2', 'title' => 'Kit', 'family' => 'Unknown Kit', 'source' => 'typekit'], [], $catalog, true, $errors);
T::ok($errors !== [], 'an unknown typekit family needs a stack');

$errors = [];
$custom = FontItems::complete(['_id' => 'peCustom', 'title' => 'C', 'family' => 'Brand Sans', 'source' => 'custom'], ['customFontItems' => [['_id' => 'brand1', 'family' => 'Brand Sans', 'files' => [['weight' => '500', 'style' => 'normal', 'filename' => 'b.woff2', 'url' => 'https://x.test/b.woff2']]]]], null, true, $errors);
T::same([], $errors, 'completes a custom font');
T::same('brand1', $custom['name'], 'custom name is the item _id');
T::same('500', $custom['weightNormal'], 'custom weights come from its files');

$errors = [];
FontItems::complete(['_id' => 'peNoTitle', 'family' => 'Arial', 'source' => 'system'], [], $catalog, true, $errors);
T::ok($errors !== [], 'new fonts need a title');

$errors = [];
T::same(null, FontItems::validateShape(['_id' => 'peX', 'weightSelection' => ['400', 'bold']], 'fonts[0]', $errors), 'rejects bad weight names');
$errors = [];
T::same(null, FontItems::validateShape(['_id' => 'peX', 'color' => 'red'], 'fonts[0]', $errors), 'rejects unknown font keys');
$errors = [];
T::same(null, FontItems::validateShape(['_id' => 'peX', 'stack' => 'a;b'], 'fonts[0]', $errors), 'rejects unsafe stacks');

// Config.
$stored = ['googleSubsets' => [], 'typekitKitID' => 'abc', 'typekitKitLoadAsCSS' => false, 'customFontItems' => [['_id' => 'brand1', 'family' => 'Brand Sans', 'files' => []]]];
$errors = [];
$merged = FontItems::mergeConfig($stored, ['fontDisplay' => 'swap', 'customFontItems' => [['_id' => 'brand1', 'stack' => '"Brand Sans", serif'], ['_id' => 'brand2', 'family' => 'Other', 'files' => [['weight' => '400', 'style' => 'regular', 'filename' => 'o.woff', 'url' => '/wp-content/uploads/o.woff']]]]], $errors);
T::same([], $errors, 'merges a partial config');
T::same(['fontDisplay', 'customFontItems'], $merged['changed'], 'reports changed keys');
T::same(false, $merged['config']['typekitKitLoadAsCSS'], 'keeps keys it did not change');
T::same('"Brand Sans", serif', $merged['config']['customFontItems'][0]['stack'], 'merges custom items by _id');
T::same(2, count($merged['config']['customFontItems']), 'appends new custom items');

$errors = [];
FontItems::mergeConfig($stored, ['fontDisplay' => 'fast'], $errors);
T::ok($errors !== [], 'rejects an unknown fontDisplay');
$errors = [];
FontItems::mergeConfig($stored, ['googleFontsURL' => 'http://insecure.test/css'], $errors);
T::ok($errors !== [], 'requires https for googleFontsURL');
$errors = [];
FontItems::mergeConfig($stored, ['customFontFaceCSS' => '</style><script>x</script>'], $errors);
T::ok($errors !== [], 'refuses markup in customFontFaceCSS');
$errors = [];
FontItems::mergeConfig($stored, ['typekitItems' => []], $errors);
T::ok($errors !== [], 'rejects config keys outside the allowlist');
$errors = [];
$same = FontItems::mergeConfig($stored, ['typekitKitID' => 'abc'], $errors);
T::same([], $same['changed'], 'an identical value is not a change');

// Editing a Google font while Google Fonts are off --------------------------

// With googleDisabled, Cornerstone drops Google families from its font list,
// so a catalog lookup for a stored font finds nothing.
$offCatalog = ['arial' => ['source' => 'system', 'family' => 'Arial', 'stack' => 'Arial,sans-serif', 'weights' => ['400', '700']]];
$stored = ['_id' => 'exp03Base', 'title' => 'Base', 'family' => 'Jost', 'source' => 'google', 'name' => 'jost', 'stack' => '"Jost",sans-serif'];

$errors = [];
$renamed = FontItems::complete(
    ['_id' => 'exp03Base', 'title' => 'Base Copy', 'family' => 'Jost', 'source' => 'google'],
    ['googleDisabled' => true],
    $offCatalog,
    false,
    $errors,
    $stored
);

T::same([], $errors, 'a title-only change to a stored Google font is allowed while Google Fonts are off');
T::same('jost', $renamed['name'], 'and keeps the stored name');
T::same('"Jost",sans-serif', $renamed['stack'], 'and the stored stack');

$errors = [];
FontItems::complete(
    ['_id' => 'exp03Base', 'title' => 'Base', 'family' => 'Inter', 'source' => 'google'],
    ['googleDisabled' => true],
    $offCatalog,
    false,
    $errors,
    $stored
);

T::same(1, count($errors), 'changing the family is still checked against the font list');
T::ok(str_contains($errors[0], 'Google Fonts are disabled'), 'and the refusal says why the family was not found');

$errors = [];
FontItems::complete(
    ['_id' => 'peNew', 'title' => 'New', 'family' => 'Jost', 'source' => 'google'],
    ['googleDisabled' => true],
    $offCatalog,
    true,
    $errors,
    null
);

T::same(1, count($errors), 'a new Google font is still checked');
