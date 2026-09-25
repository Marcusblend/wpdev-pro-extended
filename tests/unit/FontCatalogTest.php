<?php

declare(strict_types=1);

use ProExtended\Cornerstone\FontCatalog;
use ProExtended\Cornerstone\NativeReference;

T::group('FontCatalog');

// Stored lists in the shapes Cornerstone 7.9.4 keeps (cornerstone_font_items,
// cornerstone_font_config), one custom item written from the old guidance.
$items = [
    ['_id' => 'body', 'title' => 'Body', 'family' => 'Brand Sans', 'source' => 'custom', 'name' => 'brand-sans', 'stack' => '"Brand Sans"', 'weightNormal' => '400', 'weightBold' => '700'],
    ['_id' => 'heading', 'title' => 'Heading', 'family' => 'Barlow Condensed', 'source' => 'google', 'name' => 'barlowcondensed', 'locked' => true],
    ['_id' => 'legacy', 'title' => 'Legacy', 'family' => 'Old Face', 'source' => 'custom', 'fallback' => 'serif'],
    ['_id' => 'type', 'title' => 'Typography', 'children' => ['body', 'heading']],
];
$config = [
    'googleDisabled'    => false,
    'googleSubsets'     => ['latin-ext'],
    'typekitKitID'      => 'abc123',
    'typekitItems'      => [['family' => 'Agenda One', 'stack' => '"agenda-one", sans-serif']],
    'fontDisplay'       => 'swap',
    'customFontFaceCSS' => '@font-face{}',
    'customFontItems'   => [
        ['_id' => 'brand-sans', 'family' => 'Brand Sans', 'stack' => '"Brand Sans"', 'fallback' => '"Helvetica Neue", Arial, sans-serif', 'files' => [
            ['weight' => '400', 'style' => 'normal', 'filename' => 'b.woff2', 'url' => '/b.woff2'],
            ['weight' => '700', 'style' => 'italic', 'filename' => 'bi.woff2', 'url' => '/bi.woff2'],
        ]],
        ['_id' => 'old-face', 'family' => 'Old Face', 'stack' => '"Old Face", Georgia, serif', 'files' => [['weight' => '100 900', 'style' => 'normal', 'filename' => 'v.woff2', 'url' => '/v.woff2']]],
    ],
];

// A resolver standing in for GlobalFonts::getDataForFontItem().
$resolve = static fn (array $item): ?array => match ($item['_id']) {
    'body'    => ['source' => 'custom', 'family' => 'Brand Sans', 'stack' => '"Brand Sans", "Helvetica Neue", Arial, sans-serif', 'weights' => ['400', '700'], 'weightNormal' => '400', 'weightBold' => '700'],
    'heading' => ['source' => 'google', 'family' => 'Barlow Condensed', 'stack' => '"Barlow Condensed", sans-serif', 'weights' => ['300', '600'], 'weightNormal' => '300', 'weightBold' => '600'],
    'legacy'  => ['source' => 'system', 'family' => 'Helvetica', 'stack' => 'Helvetica, Arial, sans-serif', 'weights' => ['400'], 'weightNormal' => '400', 'weightBold' => '700'],
    default   => null,
};

$shaped = FontCatalog::shape($items, $config, $resolve);
$fonts = array_column($shaped['fonts'], null, '_id');

T::same(3, $shaped['count'], 'every font item is listed, the group is not');
T::same(['body', 'heading', 'legacy'], array_keys($fonts), 'in stored order');
T::same('"Brand Sans", "Helvetica Neue", Arial, sans-serif', $fonts['body']['stack'], 'the stack is the one Cornerstone resolves');
T::same(['300', '600'], [$fonts['heading']['weightNormal'], $fonts['heading']['weightBold']], 'and so are the weights fw-normal and fw-bold become');
T::same('cornerstone', $fonts['heading']['resolved_by'], 'resolved values say where they come from');
T::same('type', $fonts['body']['group'], 'a grouped font names its group');
T::ok($fonts['heading']['locked'] ?? false, 'a locked font says so');
T::ok(str_contains($fonts['legacy']['problem'] ?? '', 'fallback font'), 'a font Cornerstone resolves to its fallback is flagged', $fonts['legacy']['problem'] ?? '');
T::same('serif', $fonts['legacy']['fallback'], 'a font item\'s own fallback is shown');
T::same([['_id' => 'type', 'title' => 'Typography', 'children' => ['body', 'heading']]], $shaped['groups'], 'groups are listed apart');

$custom = array_column($shaped['custom_font_items'], null, '_id');
T::same('"Brand Sans"', $custom['brand-sans']['font_face_family'], 'a custom item shows the family its @font-face declares');
T::same('"Brand Sans", "Helvetica Neue", Arial, sans-serif', $custom['brand-sans']['element_stack'], 'and the stack elements get (stack, then fallback)');
T::same(['400', '700 italic'], $custom['brand-sans']['weights'], 'and its weights');
T::same(['body'], $custom['brand-sans']['used_by'], 'and the global fonts that use it');
T::ok(! isset($custom['brand-sans']['problem']), 'a single quoted family has no problem');
T::ok(str_contains($custom['old-face']['problem'] ?? '', 'invalid'), 'a comma stack is flagged as an invalid @font-face', $custom['old-face']['problem'] ?? '');
T::same('"Old Face", Georgia, serif, sans-serif', $custom['old-face']['element_stack'], 'without a fallback Cornerstone appends the system fallback');
T::same(['legacy'], $custom['old-face']['used_by'], 'used_by matches by family, as Cornerstone does');

T::same(['google_fonts_enabled' => true, 'google_subsets' => ['latin-ext'], 'google_fonts_url_set' => false, 'typekit_kit_set' => true, 'typekit_load_as_css' => false, 'typekit_families' => ['Agenda One'], 'font_display' => 'swap', 'custom_font_face_css_bytes' => 12], $shaped['config'], 'the Google and Adobe Fonts settings are reported as flags, not the kit id');

$reference = $shaped['reference'];
T::same('<_id>', $reference['element_family'], 'reference: an element family is the bare _id');
T::same(['fw-normal', 'fw-bold'], $reference['element_weight'], 'reference: an element weight is fw-normal or fw-bold');
T::same('<_id>', $reference['theme_option_family'], 'reference: a theme option family is the bare _id');
T::same(['fw-normal', 'fw-bold'], $reference['theme_option_weight'], 'reference: a theme option weight is fw-normal or fw-bold');
T::same(['text_font_family' => 'body', 'text_font_weight' => 'fw-bold', 'x_body_font_family_selection' => 'body', 'x_body_font_weight_selection' => 'fw-normal'], $reference['example'], 'the example uses the site\'s first font');
T::ok(! str_contains((string) json_encode($reference['example']), 'global-f'), 'and no global-ff:/global-fw: form');

$stored = FontCatalog::shape($items, $config, null);
$storedFonts = array_column($stored['fonts'], null, '_id');
T::same('stored', $storedFonts['body']['resolved_by'], 'without Cornerstone the stored values are reported');
T::same('"Brand Sans"', $storedFonts['body']['stack'], 'as stored');

$empty = FontCatalog::shape([], [], null);
T::same(0, $empty['count'], 'an empty site has no fonts');
T::same('body', $empty['reference']['example']['text_font_family'], 'and the example falls back to "body"');

T::ok(in_array('fonts', NativeReference::SECTIONS, true), 'get_native_reference has a fonts section');
