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

$uses = [
    'brand' => ['count' => 3, 'locations' => [
        ['type' => 'post', 'post_id' => 12, 'post_type' => 'page', 'title' => 'T', 'field' => '_cornerstone_data', 'count' => 2],
        ['type' => 'option', 'option' => 'x_design_bg_color', 'count' => 1],
    ], 'more' => 0],
    'unused' => ['count' => 0, 'locations' => [], 'more' => 0],
];
T::same('"brand" is used 3 times (page #12, option x_design_bg_color)', R::describe($uses), 'describe lists the uses');
