<?php

declare(strict_types=1);

use ProExtended\Settings\ReferenceScanner as R;

T::group('ReferenceScanner patterns');

$c = R::KIND_COLOR;
$f = R::KIND_FONT;

T::same(1, R::countInText('global-color:brand', $c, 'brand'), 'a bare color reference');
T::same(1, R::countInText('0px 3px 6px global-color:brand:0.5', $c, 'brand'), 'a reference with alpha inside a shadow');
T::same(0, R::countInText('global-color:brand-dark', $c, 'brand'), 'a longer ID does not match');
T::same(0, R::countInText('global-color:mybrand', $c, 'brand'), 'a longer prefix does not match');
T::same(1, R::countInText('{{dc:global:color id="brand"}}', $c, 'brand'), 'a Dynamic Content color token');
T::same(1, R::countInText("{{dc:site:color id='brand' a='0.5'}}", $c, 'brand'), 'a site token with alpha');
T::same(1, R::countInText('{"text_content":"{{dc:global:color id=\"brand\"}}"}', $c, 'brand'), 'a token inside JSON text');
T::same(1, R::countInText('{{dc:global:color id=&quot;brand&quot;}}', $c, 'brand'), 'a token with &quot; quotes');
T::same(0, R::countInText('{{dc:global:color id="brand2"}}', $c, 'brand'), 'a token for another ID');
T::same(1, R::countInText("{{ global.color({id: 'brand'}) }}", $c, 'brand'), 'the Twig form');
T::same(2, R::countInText('global-color:brand; global-color:brand:0.2', $c, 'brand'), 'counts every occurrence');

T::same(1, R::countInText('global-ff:serif1', $f, 'serif1'), 'a font family reference');
T::same(1, R::countInText('global-fw:serif1|fw-bold', $f, 'serif1'), 'a font weight reference');
T::same(0, R::countInText('global-ff:serif10', $f, 'serif1'), 'a longer font ID does not match');
T::same(1, R::countInText('{{dc:global:font-family id="serif1"}}', $f, 'serif1'), 'a font family token');
T::same(1, R::countInText("{{dc:global:font-weight id='serif1' weight='fw-bold'}}", $f, 'serif1'), 'a font weight token');
T::same(1, R::countInText("{{dc:global:font id='serif1'}}", $f, 'serif1'), 'a font token');
T::same(1, R::countInText("{{ 'serif1|fw-bold'|cs_font_weight }}", $f, 'serif1'), 'the Twig filter form');
T::same(0, R::countInText('global-color:serif1', $f, 'serif1'), 'a color reference is not a font use');

$element = [
    '_type'              => 'headline',
    'text_font_family'   => 'serif1',
    'text_font_weight'   => 'fw-bold',
    'title'              => 'serif1',
    '_bp_data4_4'        => ['text_font_family' => [null, 'serif1', null, null, null]],
    'text_color'         => 'global-color:brand',
    '_modules'           => [['_type' => 'text', 'text_content' => '{{dc:global:color id="brand"}}']],
];

T::same(2, R::countInData($element, $f, 'serif1'), 'bare font IDs count only under font family keys, responsive data included');
T::same(2, R::countInData($element, $c, 'brand'), 'color references anywhere in the data, children included');
T::same(1, R::countInData(['x_body_font_family_selection' => 'serif1'], $f, 'serif1'), 'a theme option holding the font ID');
T::same(0, R::countInData(['x_body_font_family_selection' => 'serif1'], $c, 'serif1'), 'a font key does not count for colors');
T::same(1, R::countInData(['value' => ['type' => 'linear', 'colors' => [['color' => 'global-color:brand']]]], $c, 'brand'), 'a gradient stop');
T::same(0, R::countInData(null, $c, 'brand'), 'null data');
T::same(0, R::countInText('', $c, 'brand'), 'empty text');

// Fonts by bare _id, the form Cornerstone resolves -----------------------------

$bare = [
    '_type'                              => 'button',
    'anchor_text_primary_font_family'    => 'serif1',
    'anchor_text_primary_font_family_alt' => 'serif1',
    'anchor_text_primary_font_weight'    => 'fw-bold',
    'anchor_text_secondary_font_family'  => 'serif10',
    'anchor_text_content'                => 'serif1',
    '_bp_data4_4'                        => ['anchor_text_primary_font_family' => [null, null, 'serif1', null, null]],
];
T::same(3, R::countInData($bare, $f, 'serif1'), 'a bare _id under *_font_family, its _alt twin and a breakpoint value; not in copy, not a longer id');

foreach (file(dirname(__DIR__) . '/fixtures/cornerstone-7.9.4/font-theme-options.txt', FILE_IGNORE_NEW_LINES) ?: [] as $row) {
    if ($row === '' || $row[0] === '#') {
        continue;
    }

    [$option, $kind] = explode("\t", $row);

    if ($kind === 'family') {
        T::same(1, R::countInData([$option => 'serif1'], $f, 'serif1'), "{$option} holding the bare _id is a use");
        T::same(1, R::countInData([$option => 'global-ff:serif1'], $f, 'serif1'), "{$option} holding the legacy global-ff: form still is");
    } else {
        T::same(0, R::countInData([$option => 'fw-bold'], $f, 'serif1'), "{$option} holding fw-bold names no font");
    }
}

$component = [
    '_type'   => 'layout-div',
    '_p_json' => '{"heading":"font-family|serif1","body":{"type":"font-family","initial":"sans1"},"label":"text|serif1","typo":{"type":"typography"}}',
    '_p_data' => ['heading' => 'serif1', 'body' => 'serif1', 'label' => 'serif1', 'typo' => ['fontFamily' => 'serif1', 'fontWeight' => 'fw-bold']],
];
T::same(4, R::countInData($component, $f, 'serif1'), 'the schema\'s shorthand initial, font-family parameters, and a typography fontFamily; not a text parameter');
T::same(1, R::countInData($component, $f, 'sans1'), 'a schema initial in full form');
T::same(['heading', 'body'], R::fontParameters($component['_p_json']), 'fontParameters reads both schema forms');
T::same(['brandFont'], R::fontParameters(['brandFont' => 'font-family|serif1', 'x' => ['type' => 'color']]), 'and a decoded schema');

$instance = ['_type' => 'component', 'component_id' => 'c1', '_p_data' => ['font' => 'serif1', 'heading_font_family' => 'serif1']];
T::same(1, R::countInData($instance, $f, 'serif1'), 'an instance\'s _p_data counts where the name says font family (the schema lives in the component)');

T::same(1, R::countInData(['data' => '{"text_font_family":"serif1","text_content":"serif1"}'], $f, 'serif1'), 'a JSON string is decoded and read the same way');
T::same(1, R::countInText('{\"text_font_family\":\"serif1\"', $f, 'serif1'), 'undecodable JSON text still shows a font key holding the id');
T::same(0, R::countInText('{"text_content":"serif1"}', $f, 'serif1'), 'but not another key');
T::same(2, R::countInData(['brandFont' => 'serif1', '_bp_data4_4' => ['brandFont' => [null, 'serif1']]], $f, 'serif1', false, ['brandFont']), 'Global Parameters data: names from the schema count at the top level and per breakpoint');
T::same(0, R::countInData(['brandFont' => 'serif1'], $f, 'serif1'), 'and without the schema a parameter name alone is not a font key');

$uses = [
    'brand' => ['count' => 3, 'locations' => [
        ['type' => 'post', 'post_id' => 12, 'post_type' => 'page', 'title' => 'T', 'field' => '_cornerstone_data', 'count' => 2],
        ['type' => 'option', 'option' => 'x_design_bg_color', 'count' => 1],
    ], 'more' => 0],
    'unused' => ['count' => 0, 'locations' => [], 'more' => 0],
];
T::same('"brand" is used 3 times (page #12, option x_design_bg_color)', R::describe($uses), 'describe lists the uses');
