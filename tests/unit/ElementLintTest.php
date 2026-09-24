<?php

declare(strict_types=1);

use ProExtended\Elements\ElementLint;
use ProExtended\Elements\LintContext;

T::group('ElementLint');

$context = new LintContext(
    ['section' => 2, 'text' => 1, 'bar' => 1, 'layout-grid' => 1, 'layout-column' => 2, 'nav-collapsed' => 1],
    '4_4',
    ['content-area', 'nav-modal'],
    ['csv' => false, 'external_api' => false, 'woocommerce' => false],
    static fn(string $rule): ?bool => in_array($rule, ['global_user_loggedin', 'expression_number_gt', 'current_post_specific_post_of_type', 'current_post_parent'], true),
    static fn(string $type): ?bool => $type === 'super-looper',
);
$lint = new ElementLint($context);

// A clean element: stamped, nothing else.
$clean = ['_type' => 'text', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_content' => 'Hello {{dc:post:title}}'];

$codesOf = static function (array $issues): array {
    $codes = array_values(array_unique(array_column($issues, 'code')));
    sort($codes);

    return $codes;
};

$only = static function (array $element) use ($lint, $codesOf): array {
    return $codesOf($lint->tree([$element]));
};

T::same([], $only($clean), 'a stamped, well-formed element has no issues');

// Fixtures per code: [code, element, label].
$fixtures = [
    ['missing-migration-marker', ['_type' => 'bar', '_bp_base' => '4_4'], 'a bar without _m'],
    ['missing-breakpoint-base', ['_type' => 'headline'], 'an element without _bp_base'],
    ['breakpoint-base-mismatch', ['_type' => 'headline', '_bp_base' => '3_4'], 'another site\'s breakpoint tag'],
    ['breakpoint-base-mismatch', ['_type' => 'headline', '_bp_base' => 'desktop'], 'a _bp_base that is not a tag'],
    ['breakpoint-data-key', ['_type' => 'headline', '_bp_base' => '4_4', '_bp_data3_4' => ['x' => [null, null, null, null]]], 'responsive data for another tag'],
    ['breakpoint-data-key', ['_type' => 'headline', '_bp_base' => '4_4', '_bp_data' => []], 'an unrecognised _bp_data key'],
    ['breakpoint-data-length', ['_type' => 'headline', '_bp_base' => '4_4', '_bp_data4_4' => ['x' => ['1em', null, null, null]]], 'four values for five breakpoints'],
    ['breakpoint-data-base-slot', ['_type' => 'headline', '_bp_base' => '4_4', '_bp_data4_4' => ['x' => [null, null, null, null, '2em']]], 'a value in the base slot'],
    ['classic-element', ['_type' => 'classic:text', '_bp_base' => '4_4'], 'a classic element'],
    ['deprecated-element', ['_type' => 'content-area', '_bp_base' => '4_4'], 'a deprecated element'],
    ['legacy-element', ['_type' => 'row', '_bp_base' => '4_4'], 'a v2 row'],
    ['internal-element', ['_type' => 'region', '_bp_base' => '4_4'], 'a region inside a tree'],
    ['condition-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => ['condition' => 'global:user-loggedin']], 'show_condition as an object'],
    ['condition-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['condition' => 'global:user-loggedin', 'value' => '', 'toggle' => 'false']]], 'a string toggle'],
    ['condition-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['condition' => 'global:user-loggedin']]], 'a rule without value'],
    ['condition-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['value' => '1']]], 'a rule without condition'],
    ['condition-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => 'not json'], 'a string that is not JSON'],
    ['condition-unknown', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['condition' => 'global:no-such-rule', 'value' => '', 'group' => true]]], 'an unknown condition'],
    ['condition-unknown', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['condition' => 'expression:number', 'operator' => 'between', 'operand' => '1', 'value' => '2']]], 'an unknown operator'],
    ['condition-post-id', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['condition' => 'current-post:specific-post-of-type|page', 'value' => '42']]], 'a post ID given as a string'],
    ['condition-post-id', ['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['condition' => 'current-post:parent', 'value' => '7']]], 'a parent ID given as a string'],
    ['looper-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'looper_provider' => 'true'], 'a string looper flag'],
    ['looper-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'looper_consumer' => true, 'looper_consumer_repeat' => 'all'], 'a non-numeric repeat'],
    ['looper-shape', ['_type' => 'headline', '_bp_base' => '4_4', 'looper_provider' => true, 'looper_provider_type' => 'nope'], 'an unregistered provider'],
    ['looper-feature-off', ['_type' => 'headline', '_bp_base' => '4_4', 'looper_provider' => true, 'looper_provider_type' => 'csv'], 'a CSV looper with CSV off'],
    ['looper-feature-off', ['_type' => 'headline', '_bp_base' => '4_4', 'looper_provider' => true, 'looper_provider_type' => 'apiglobal'], 'an API looper with the API off'],
    ['looper-feature-off', ['_type' => 'headline', '_bp_base' => '4_4', 'looper_provider' => true, 'looper_provider_type' => 'wc-upsell'], 'a WooCommerce looper without WooCommerce'],
    ['token-syntax', ['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => 'Hi {{dc:post:title'], 'an unclosed token'],
    ['token-syntax', ['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => "{{dc:post:title\n}}"], 'a token across lines'],
    ['token-syntax', ['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => '{{dc:post:meta key=price}}'], 'an unquoted argument'],
    ['token-untrusted', ['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => "{{dc:url:param key='q'}}"], 'a URL parameter token'],
    ['token-untrusted', ['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => "{{dc:cookie:get key='x'}}"], 'a cookie token'],
    ['token-untrusted', ['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => "{{dc:post:meta key='x'}}"], 'a meta token'],
    ['custom-atts-type', ['_type' => 'headline', '_bp_base' => '4_4', 'custom_atts' => ['data-x' => '1']], 'custom_atts as an object'],
    ['custom-atts-type', ['_type' => 'headline', '_bp_base' => '4_4', 'custom_atts' => '{bad json'], 'custom_atts that is not JSON'],
    ['custom-atts-type', ['_type' => 'headline', '_bp_base' => '4_4', 'anchor_custom_atts' => '["a"]'], 'a prefixed custom_atts list'],
    ['parameters-type', ['_type' => 'headline', '_bp_base' => '4_4', '_p_json' => ['title' => 'text']], '_p_json as an object'],
    ['parameters-type', ['_type' => 'headline', '_bp_base' => '4_4', '_p_json' => '{"title":'], 'invalid _p_json'],
    ['parameters-type', ['_type' => 'component', '_bp_base' => '4_4', 'component_id' => 'x', '_p_data' => '{"a":1}'], '_p_data as a string'],
    ['table-cell-text', ['_type' => 'layout-table-cell', '_bp_base' => '4_4', '_modules' => [$clean]], 'a cell with children and default text'],
    ['table-cell-span', ['_type' => 'layout-table-cell', '_bp_base' => '4_4', 'text_content' => '', 'layout_table_cell_colspan' => 13], 'a colspan of 13'],
    ['table-cell-span', ['_type' => 'layout-table-cell', '_bp_base' => '4_4', 'text_content' => '', 'layout_table_cell_rowspan' => '2x'], 'a non-numeric rowspan'],
    ['table-cell-tag', ['_type' => 'layout-table-cell', '_bp_base' => '4_4', 'text_content' => '', 'layout_table_cell_tag' => 'div'], 'a div cell tag'],
    ['table-section-tag', ['_type' => 'layout-table-section', '_bp_base' => '4_4', 'tag' => 'tr'], 'a tr section tag'],
    ['table-section-tag', ['_type' => 'layout-table-section', '_bp_base' => '4_4', 'layout_table_section_tag' => 'thead'], 'the prefixed section tag key'],
    ['link-without-href', ['_type' => 'layout-div', '_m' => ['e' => 1], '_bp_base' => '4_4', 'layout_div_tag' => 'a'], 'a div link without href'],
    ['background-layers-off', ['_type' => 'section', '_m' => ['e' => 2], '_bp_base' => '4_4', 'bg_lower_type' => 'image'], 'a layer with the switch off'],
];

$seen = [];

foreach ($fixtures as [$code, $element, $label]) {
    $codes = $only($element);
    $seen[$code] = true;
    T::ok(in_array($code, $codes, true), "{$code}: {$label}", 'got ' . implode(', ', $codes));
}

$nestedLink = [
    '_type'          => 'layout-div',
    '_bp_base'       => '4_4',
    'layout_div_tag' => 'a',
    'layout_div_href' => 'https://example.com/a',
    '_modules'       => [[
        '_type'          => 'layout-div',
        '_bp_base'       => '4_4',
        'layout_div_tag' => 'a',
        'layout_div_href' => 'https://example.com/b',
    ]],
];
$issues = $lint->tree([$nestedLink]);
T::same(['nested-link'], $codesOf($issues), 'nested-link: a link inside a link');
T::same('0._modules.0', $issues[0]['path'] ?? null, 'nested-link points at the inner element');
$seen['nested-link'] = true;


// css-over-control needs a site that can say which settings an element has.

$withSurface = new ElementLint(new LintContext(
    ['text' => 1],
    '4_4',
    [],
    [],
    null,
    null,
    static fn (string $type): array => $type === 'headline'
        ? ['font-size' => 'text_font_size', 'color' => 'text_text_color']
        : [],
));

$styled = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'css' => '$el { font-size: 2em; color: red; }'];
$issues = $withSurface->tree([$styled]);
T::same(['css-over-control'], $codesOf($issues), 'css-over-control: a declaration the element has a setting for');
T::same(2, count($issues), 'one issue per property');
T::ok(str_contains($issues[0]['message'], 'text_font_size'), 'the message names the key to set instead');
$seen['css-over-control'] = true;

$unmatched = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'css' => '$el { backdrop-filter: blur(4px); }'];
T::same([], $codesOf($withSurface->tree([$unmatched])), 'a property with no setting is left alone');

$noSurface = ['_type' => 'section', '_m' => ['e' => 2], '_bp_base' => '4_4', 'css' => '$el { font-size: 2em; }'];
T::same([], $codesOf($withSurface->tree([$noSurface])), 'an element type the registry knows nothing about is left alone');

$selector = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'css' => '$el .color { background: url(a.png); }'];
T::same([], $codesOf($withSurface->tree([$selector])), 'a selector that reads like a property is not a declaration');

$blank = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'css' => '   '];
T::same([], $codesOf($withSurface->tree([$blank])), 'empty css is not reported');

T::same([], $codesOf($lint->tree([$styled])), 'without a registry the check stays quiet');


// literal-color and literal-font-family need a site that can say what each of
// an element's keys sets.

$withKeys = new ElementLint(new LintContext(
    ['text' => 1],
    '4_4',
    [],
    [],
    null,
    null,
    null,
    static fn (string $type): array => $type === 'headline'
        ? [
            'text_text_color'   => 'color',
            'text_bg_color'     => 'background-color',
            'text_font_family'  => 'font-family',
            'text_font_weight'  => 'font-weight',
            'text_box_shadow'   => 'box-shadow',
        ]
        : [],
));

$hard = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_text_color' => '#1a73e8'];
$issues = $withKeys->tree([$hard]);
T::same(['literal-color'], $codesOf($issues), 'literal-color: a hex in a colour setting');
T::ok(str_contains($issues[0]['message'], 'global-color:'), 'the message names the reference form');
$seen['literal-color'] = true;

$stack = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_font_family' => '"Barlow Condensed", sans-serif'];
$issues = $withKeys->tree([$stack]);
T::same(['literal-font-family'], $codesOf($issues), 'literal-font-family: a stack in a font setting');
T::ok(str_contains($issues[0]['message'], 'global-ff:'), 'the message names the reference form');
$seen['literal-font-family'] = true;

$rgba = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_bg_color' => 'rgba(0, 0, 0, 0.4)'];
T::same(['literal-color'], $codesOf($withKeys->tree([$rgba])), 'an rgba background is a literal too');

$referenced = [
    '_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4',
    'text_text_color'  => 'global-color:brand',
    'text_bg_color'    => 'global-color:surface:0.5',
    'text_font_family' => 'global-ff:heading',
];
T::same([], $codesOf($withKeys->tree([$referenced])), 'a reference is what the lint is asking for');

$keywords = [
    '_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4',
    'text_text_color' => 'inherit',
    'text_bg_color'   => 'transparent',
];
T::same([], $codesOf($withKeys->tree([$keywords])), 'a keyword is not a hard-coded colour');

$variable = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_text_color' => 'var(--brand)'];
T::same([], $codesOf($withKeys->tree([$variable])), 'a global variable is already a reference');

$token = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_text_color' => '{{dc:p:brand}}'];
T::same([], $codesOf($withKeys->tree([$token])), 'a dynamic content token resolves later');

$weight = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_font_weight' => '700'];
T::same([], $codesOf($withKeys->tree([$weight])), 'font weight is deliberately not tokenized');

$shadow = ['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4', 'text_box_shadow' => '0 1px 2px rgba(0,0,0,.2)'];
T::same([], $codesOf($withKeys->tree([$shadow])), 'a shadow is not a colour setting');

$unknown = ['_type' => 'section', '_m' => ['e' => 2], '_bp_base' => '4_4', 'text_text_color' => '#fff'];
T::same([], $codesOf($withKeys->tree([$unknown])), 'an element the registry cannot describe is left alone');

T::same([], $codesOf($lint->tree([$hard])), 'without a registry the token checks stay quiet');

T::same([], array_values(array_diff(array_keys(ElementLint::CODES), array_keys($seen))), 'every code has a fixture');

// Things that must not warn.
$quiet = [
    [['_type' => 'layout-column', '_bp_base' => '4_4'], 'a column without _m (nothing visible changes)'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => ''], 'an empty show_condition'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => "{{dc:post:title}}"], 'show_condition from a token'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'show_condition' => [['group' => true, 'condition' => 'global:user-loggedin', 'value' => '', 'toggle' => false], ['condition' => 'current-post:specific-post-of-type|page', 'value' => 42]]], 'well-formed conditions with an integer ID'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'looper_provider' => true, 'looper_provider_type' => 'super-looper', 'looper_consumer' => true, 'looper_consumer_repeat' => '-1'], 'a registered provider'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'looper_consumer' => true, 'looper_consumer_repeat' => ''], 'repeat ""'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => "{{dc:looper:field key=\"{{dc:p:image_key}}\"}} and {{dc:post:title fallback='x'}}"], 'nested and quoted tokens'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'text_content' => '{{dc:post:title type=\"json\"}}'], 'a backslash-quoted argument'],
    [['_type' => 'headline', '_bp_base' => '4_4', 'custom_atts' => '{"data-x":"1"}', 'button_custom_atts' => ''], 'valid and empty custom_atts'],
    [['_type' => 'headline', '_bp_base' => '4_4', '_p_json' => '{"title":"text|Hello"}', '_p_data' => ['title' => 'x']], 'valid parameters'],
    [['_type' => 'layout-table-cell', '_bp_base' => '4_4', 'text_content' => '', 'layout_table_cell_colspan' => 2, 'layout_table_cell_tag' => 'th', '_modules' => [$clean]], 'a cell with children and empty text'],
    [['_type' => 'layout-table-section', '_bp_base' => '4_4', 'tag' => 'thead'], 'a thead section'],
    [['_type' => 'layout-div', '_bp_base' => '4_4', 'layout_div_tag' => 'div'], 'a div that is not a link'],
    [['_type' => 'section', '_m' => ['e' => 2], '_bp_base' => '4_4', 'bg_lower_type' => 'image', 'section_bg_advanced' => true], 'layers with the switch on'],
    [['_type' => 'section', '_m' => ['e' => 2], '_bp_base' => '4_4', 'bg_lower_type' => 'none'], 'a layer set to none'],
    [['_type' => 'headline', '_bp_base' => '4_4', '_bp_data4_4' => ['x' => ['1em', null, '2em', null, null]]], 'well-formed responsive data'],
];

foreach ($quiet as [$element, $label]) {
    T::same([], $only($element), "no warning for {$label}");
}

// A missing _bp_base on a grid says the layout is rewritten.
$grid = $lint->tree([['_type' => 'layout-grid', '_m' => ['e' => 1]]]);
T::ok(str_contains($grid[0]['message'] ?? '', 'overwrites its layout'), 'a grid without _bp_base mentions the layout rewrite');

// Paths.
$regionIssues = $lint->tree([['_type' => 'bar', '_bp_base' => '4_4', '_modules' => [['_type' => 'nav-collapsed', '_bp_base' => '4_4']]]], 'regions.top');
T::same(['regions.top.0', 'regions.top.0._modules.0'], array_column($regionIssues, 'path'), 'paths follow update_layout notation in regions');
T::ok(str_contains($regionIssues[1]['message'], 'toggle'), 'the nav warning says it turns into a toggle');

$flatMap = [
    'e0' => ['_type' => 'root', '_modules' => ['e1']],
    'e1' => ['_type' => 'region', '_region' => 'content', '_modules' => ['e2']],
    'e2' => ['_type' => 'layout-div', '_bp_base' => '4_4', 'layout_div_tag' => 'a', 'layout_div_href' => '#', '_modules' => ['e3'], '_parent' => 'e1'],
    'e3' => ['_type' => 'layout-div', '_bp_base' => '4_4', 'layout_div_tag' => 'a', 'layout_div_href' => '#b', '_parent' => 'e2'],
    'e4' => ['_type' => 'text', '_parent' => 'e3'],
];
$flatIssues = $lint->flat($flatMap);
T::same(['elements.e3', 'elements.e4', 'elements.e4'], array_column($flatIssues, 'path'), 'flat maps skip root and region and use elements.<id> paths');
T::same(['nested-link', 'missing-migration-marker', 'missing-breakpoint-base'], array_column($flatIssues, 'code'), 'flat maps find nested links through _parent');

// Summaries.
$many = $lint->tree([
    ['_type' => 'headline'], ['_type' => 'headline'], ['_type' => 'headline'], ['_type' => 'headline'], ['_type' => 'icon'],
]);
$lines = ElementLint::summarize($many);
T::same(2, count($lines), 'summarize groups by code and type');
T::ok(str_starts_with($lines[0], '[missing-breakpoint-base] "headline" (4 elements, at 0, 1, 2, ...)'), 'summarize counts and lists paths', $lines[0]);
T::ok(str_contains($lines[1], '(at 4)'), 'a single issue shows its path', $lines[1]);

// Token helpers.
T::same([], ElementLint::tokenProblems('No tokens {{ here }}'), 'plain braces are not tokens');
T::same(1, count(ElementLint::tokenProblems('{{dc:a:b}} {{dc:c:d')), 'finds the unclosed token after a closed one');
T::same(['url:param', 'query:search_terms', 'form:data'], ElementLint::untrustedTokens("{{dc:url:param key='a'}} {{dc:query:search_terms}} {{dc:form:data key='x'}} {{dc:post:title}}"), 'untrustedTokens lists the outside-input tokens');

// No context: the registry-dependent checks stay quiet.
$bare = new ElementLint(new LintContext());
T::same(['missing-breakpoint-base'], $codesOf($bare->tree([['_type' => 'bar', 'show_condition' => [['condition' => 'x:y', 'value' => '']], 'looper_provider' => true, 'looper_provider_type' => 'nope']])), 'without site context only data checks run');
