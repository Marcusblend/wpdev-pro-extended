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

// Ids from older builders ("Hind Semi Bold") are not slugs.
$errors = [];
T::same(null, FontItems::validateShape(['_id' => 'Hind Semi Bold', 'title' => 'Hind'], 'fonts[0]', $errors), 'a new font id with spaces is refused');
$errors = [];
T::same('Hind Semi Bold', FontItems::validateShape(['_id' => 'Hind Semi Bold', 'stack' => '"Hind", sans-serif'], 'fonts[0]', $errors, ['Hind Semi Bold'])['_id'] ?? null, 'a stored font id with spaces can be updated');
T::same([], $errors, 'without errors');
$errors = [];
$merged = FontItems::mergeConfig(['customFontItems' => [['_id' => 'Hind Semi Bold', 'family' => 'Hind', 'files' => []]]], ['customFontItems' => [['_id' => 'Hind Semi Bold', 'fallback' => 'sans-serif']]], $errors);
T::same([], $errors, 'a stored custom font item id with spaces can be updated');
$errors = [];
FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'Brand Sans', 'family' => 'Brand Sans', 'files' => []]]], $errors);
T::ok($errors !== [], 'a new custom font item id with spaces is refused');

// Config.
$stored = ['googleSubsets' => [], 'typekitKitID' => 'abc', 'typekitKitLoadAsCSS' => false, 'customFontItems' => [['_id' => 'brand1', 'family' => 'Brand Sans', 'files' => []]]];
$errors = [];
$merged = FontItems::mergeConfig($stored, ['fontDisplay' => 'swap', 'customFontItems' => [['_id' => 'brand1', 'stack' => '"Brand Sans", serif'], ['_id' => 'brand2', 'family' => 'Other', 'files' => [['weight' => '400', 'style' => 'regular', 'filename' => 'o.woff', 'url' => '/wp-content/uploads/o.woff']]]]], $errors);
T::same([], $errors, 'merges a partial config');
T::same(['fontDisplay', 'customFontItems'], $merged['changed'], 'reports changed keys');
T::same(false, $merged['config']['typekitKitLoadAsCSS'], 'keeps keys it did not change');
T::same('"Brand Sans"', $merged['config']['customFontItems'][0]['stack'], 'merges custom items by _id, with the stack cut to its first family');
T::same('serif', $merged['config']['customFontItems'][0]['fallback'], 'and the rest moved to fallback');
T::same(2, count($merged['config']['customFontItems']), 'appends new custom items');
T::same([['item' => 'brand1', 'key' => 'stack', 'from' => '"Brand Sans", serif', 'to' => '"Brand Sans"'], ['item' => 'brand1', 'key' => 'fallback', 'from' => null, 'to' => 'serif']], array_map(static fn (array $n): array => array_diff_key($n, ['reason' => 1]), $merged['normalized']), 'and reports both changes');
T::same([], $merged['warnings'], 'no warnings for plain weights');

// Custom items: stack, fallback and files ----------------------------------------

$file = ['weight' => '400', 'style' => 'normal', 'filename' => 'b.woff2', 'url' => '/wp-content/uploads/b.woff2', 'id' => 12];

$errors = [];
$split = FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-sans', 'family' => 'Archivo', 'stack' => '"Archivo", "Helvetica Neue", Arial, sans-serif', 'fallback' => 'Arial, system-ui', 'files' => [$file]]]], $errors);
$item = $split['config']['customFontItems'][0];
T::same([], $errors, 'a comma stack is not an error');
T::same('"Archivo"', $item['stack'], 'the stack keeps the first family, quoted');
T::same('Arial, system-ui, "Helvetica Neue", sans-serif', $item['fallback'], 'the rest is appended after the fallback given, without repeats');
T::same(['stack', 'fallback'], array_column($split['normalized'], 'key'), 'both are reported as normalised');

$errors = [];
$quoted = FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-sans', 'family' => 'Brand Sans', 'stack' => 'Brand Sans', 'files' => [$file]]]], $errors);
T::same('"Brand Sans"', $quoted['config']['customFontItems'][0]['stack'], 'an unquoted single family is quoted');
T::same(['stack'], array_column($quoted['normalized'], 'key'), 'and reported');
T::ok(! array_key_exists('fallback', $quoted['config']['customFontItems'][0]), 'with no fallback invented');

$errors = [];
$single = FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-sans', 'family' => 'Brand Sans', 'stack' => "'Brand Sans'", 'fallback' => 'sans-serif', 'files' => [$file]]]], $errors);
T::same([], $single['normalized'], 'a single quoted family is left as it is');
T::same('sans-serif', $single['config']['customFontItems'][0]['fallback'], 'fallback is stored');

foreach ([', sans-serif' => 'an empty first family', '' => 'an empty stack', 'sans-serif' => 'a generic family', '"Brand", "Sans' => 'a broken quote'] as $stack => $what) {
    $errors = [];
    FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-sans', 'family' => 'Brand Sans', 'stack' => $stack, 'files' => [$file]]]], $errors);
    T::ok($errors !== [] && str_contains($errors[0], '.stack'), "{$what} is refused", implode(' ', $errors));
}

$errors = [];
FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-sans', 'family' => 'Brand Sans', 'fallback' => 'a;b', 'files' => [$file]]]], $errors);
T::ok($errors !== [] && str_contains($errors[0], 'fallback'), 'an unsafe fallback is refused');

$errors = [];
FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-sans', 'family' => 'Brand Sans', 'title' => 'x', 'files' => [$file]]]], $errors);
T::ok($errors !== [] && str_contains($errors[0], 'allowed: _id, family, stack, fallback, files'), 'keys Cornerstone does not read are refused', implode(' ', $errors));

// A stored item from 1.5.0's guidance is fixed when it is next touched.
$legacy = ['customFontItems' => [['_id' => 'brand-sans', 'family' => 'Brand Sans', 'stack' => '"Brand Sans", sans-serif', 'files' => [$file], 'extra' => 'kept']]];
$errors = [];
$touched = FontItems::mergeConfig($legacy, ['customFontItems' => [['_id' => 'brand-sans', 'fallback' => 'Arial']]], $errors);
T::same('"Brand Sans"', $touched['config']['customFontItems'][0]['stack'], 'a stored comma stack is split when the item is updated');
T::same('Arial, sans-serif', $touched['config']['customFontItems'][0]['fallback'], 'after the fallback being written');
T::same('kept', $touched['config']['customFontItems'][0]['extra'], 'stored keys the tool does not know are kept');

T::same(['"Brand Sans"', 'Arial', "'A, B'", ''], FontItems::splitFontList('"Brand Sans", Arial, \'A, B\','), 'splitFontList respects quotes');

// Variable fonts: a range weight is passed through, with a warning when it is the only weight.
$range = ['weight' => '100 900', 'style' => 'normal', 'filename' => 'var.woff2', 'url' => '/wp-content/uploads/var.woff2'];

$errors = [];
$variable = FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-var', 'family' => 'Brand Var', 'stack' => '"Brand Var"', 'files' => [$range]]]], $errors);
T::same([], $errors, 'a range weight is accepted');
T::same('100 900', $variable['config']['customFontItems'][0]['files'][0]['weight'], 'and stored untouched');
T::ok(count($variable['warnings']) === 1 && str_contains($variable['warnings'][0], 'fw-normal renders 100') && str_contains($variable['warnings'][0], '"400"'), 'a range as the only weight warns that fw-normal resolves to its lower end', implode(' ', $variable['warnings']));

$errors = [];
$mixed = FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-var', 'family' => 'Brand Var', 'stack' => '"Brand Var"', 'files' => [$range, ['weight' => '400'] + $range, ['weight' => '700'] + $range]]]], $errors);
T::same([], $mixed['warnings'], 'the range with numeric entries for the same file does not warn');

foreach (['900 100', '0 900', '100 1100', '100-900', '450'] as $weight) {
    $errors = [];
    FontItems::mergeConfig([], ['customFontItems' => [['_id' => 'brand-var', 'family' => 'Brand Var', 'files' => [['weight' => $weight] + $range]]]], $errors);
    T::ok($errors !== [], "a weight of \"{$weight}\" is refused");
}

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
