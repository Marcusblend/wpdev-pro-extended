<?php

declare(strict_types=1);

use ProExtended\Elements\ControlSurface;
use ProExtended\Elements\ElementParents;
use ProExtended\Elements\StyleTemplateFacts;

T::group('ElementParents');

// Shapes as Cornerstone 7.9.4 declares them (options.valid_children / valid_parent).
$definitions = [
    ['id' => 'section', 'group' => 'structure', 'options' => ['valid_children' => '*', 'valid_parent' => 'region']],
    ['id' => 'layout-row', 'group' => 'layout', 'options' => ['valid_children' => ['layout-column']]],
    ['id' => 'layout-column', 'group' => 'layout', 'options' => ['valid_children' => '*']],
    ['id' => 'layout-div', 'group' => 'layout', 'options' => ['valid_children' => '*']],
    ['id' => 'tabs', 'group' => 'content', 'options' => ['valid_children' => ['tab']]],
    ['id' => 'tab', 'group' => 'content', 'options' => ['valid_children' => '*', 'valid_parent' => 'tabs']],
    ['id' => 'headline', 'group' => 'content', 'options' => []],
    ['id' => 'classic:column', 'group' => 'classic', 'options' => ['valid_children' => '*']],
    ['id' => 'content-area', 'group' => 'deprecated', 'options' => ['valid_children' => '*']],
    ['name' => 'map', 'group' => 'media', 'options' => ['valid_children' => 'map-marker']],
    ['id' => 'map-marker', 'group' => 'media', 'options' => ['valid_parent' => 'map']],
    'not a definition',
];
$parents = ElementParents::map($definitions);

T::same(['layout-column', 'layout-div', 'layout-row', 'section', 'tab'], $parents['layout-column']['parents'], 'a column may sit in every "*" container and in the row that lists it');
T::same(['layout-column', 'layout-div', 'section', 'tab'], $parents['headline']['parents'], 'a headline may sit in every "*" container');
T::ok(! in_array('classic:column', $parents['headline']['parents'], true), 'classic containers are left out');
T::ok(! in_array('content-area', $parents['headline']['parents'], true), 'deprecated containers are left out');
T::same(['region'], $parents['section']['parents'], 'a declared valid_parent wins: sections sit directly in a region');
T::same('valid_parent', $parents['section']['source'], 'and says where it came from');
T::same(['tabs'], $parents['tab']['parents'], 'a tab only in tabs');
T::same(['map'], $parents['map-marker']['parents'], 'a map marker only in a map');
T::same('valid_children', $parents['headline']['source'], 'derived lists say so');
T::ok(isset($parents['map']), 'definitions keyed by name are read too');

T::group('StyleTemplateFacts');

$tss = <<<'TSS'
// A comment { with a brace
$gap: get(layout_div_gap);
display: flex;
position: relative;
@include margin(get-base(layout_div_margin), get(layout_div_margin), 0px);
@if get(layout_div_flex_wrap) {
  flex-wrap: wrap;
}
@else {
  flex-wrap: nowrap;
}
#{$prop}: 1px;
content: "a;b{c}";
& > .x-child {
  width: 100%;
  @if get(x) == 'y' { height: 1px; }
}
&:hover { color: global-color(get(layout_div_color_alt)); }
@media (min-width: 600px) { z-index: 1; }
@each $item in $list { & .#{$item} { opacity: 1; } }
TSS;

$facts = StyleTemplateFacts::analyze($tss);
T::same(['display', 'position', 'content'], $facts['always'], 'literal top-level declarations are always emitted; variables and interpolated names are not');
T::same(['margin'], $facts['mixins'], 'mixins are listed by name');
T::ok(in_array('flex-wrap', $facts['conditional'], true), '@if and @else contents are conditional');
T::ok(in_array('z-index', $facts['conditional'], true), '@media contents are conditional');
T::ok(in_array('height', $facts['conditional'], true), 'an @if inside a nested rule is conditional');
$nested = array_column($facts['nested'], null, 'selector');
T::same(['width'], $nested['& > .x-child']['always'], 'a nested rule lists what it always emits');
T::same('0,2,0', $nested['& > .x-child']['specificity'], 'a child class adds one class to the generated one');
T::same('0,2,0', $nested['&:hover']['specificity'], 'a pseudo-class counts as a class');
T::same([], $nested['& .#{$item}']['always'], 'a rule inside @each is not always emitted');

T::same('0,1,0', StyleTemplateFacts::specificity('&'), 'the element itself is one class');
T::same('0,1,1', StyleTemplateFacts::specificity('&::before'), 'a pseudo-element counts as an element');
T::same('0,1,2', StyleTemplateFacts::specificity('& > li a'), 'type selectors count as elements');
T::same('0,2,0', StyleTemplateFacts::specificity(':where(.x, .y) .z'), ':where adds nothing');
T::same('1,1,0', StyleTemplateFacts::specificity('#a, & .b'), 'the highest selector in a list wins');

$source = "@mixin helper() { color: red; }\n@module layout-div(\$x: 1) {\n  display: flex;\n  & .inner { gap: 0; }\n}\n@module other { top: 0; }";
T::same("\n  display: flex;\n  & .inner { gap: 0; }\n", StyleTemplateFacts::moduleBody($source, 'layout-div'), 'a module body is found by name, with arguments');
T::same(' top: 0; ', StyleTemplateFacts::moduleBody($source, 'other'), 'a module without arguments too');
T::same(null, StyleTemplateFacts::moduleBody($source, 'missing'), 'an unknown module is null');
T::same(null, StyleTemplateFacts::moduleBody('@module broken { display: flex;', 'broken'), 'an unclosed module is null');

T::group('ControlSurface tags');

$inspector = [
    'control_nav' => ['layout_div' => 'Div', 'layout_div:setup' => 'Setup'],
    'controls'    => [
        [
            'key'     => 'layout_div_tag',
            'type'    => 'select',
            'label'   => 'Tag',
            'group'   => 'layout_div:setup',
            'options' => ['choices' => [['value' => 'div', 'label' => '<div>'], ['value' => 'section', 'label' => '<section>'], ['value' => 'a', 'label' => '<a>']]],
        ],
        ['key' => 'custom_tag', 'type' => 'text', 'label' => 'Tag', 'group' => 'layout_div:setup'],
        [
            'key'     => 'layout_div_flex_direction',
            'type'    => 'choose',
            'group'   => 'layout_div:setup',
            'options' => ['choices' => [['value' => 'row', 'label' => 'Row'], ['value' => 'column', 'label' => 'Column']]],
        ],
        ['key' => 'layout_div_global_container', 'type' => 'toggle', 'group' => 'layout_div:setup', 'options' => ['choices' => [['value' => false, 'label' => 'Off'], ['value' => true, 'label' => 'On']]]],
    ],
];
$controls = [];

foreach (ControlSurface::build($inspector)['controls'] as $control) {
    $controls[$control['keys'][0]] = $control;
}

T::same(['div' => '<div>', 'section' => '<section>', 'a' => '<a>'], $controls['layout_div_tag']['accepts']['choices'], 'list-shaped choices are keyed by their value, not their position');
T::same(['div', 'section', 'a'], $controls['layout_div_tag']['accepts']['tags'], 'a tag select lists the tags it accepts');
T::same(ControlSurface::LAYOUT_TAGS, $controls['custom_tag']['accepts']['tags'], 'a free-text tag lists the documented layout tags');
T::ok(str_starts_with($controls['custom_tag']['accepts']['tags_source'], 'documented'), 'and says they are documented, not read');
T::ok(! isset($controls['layout_div_flex_direction']['accepts']['tags']), 'a non-tag control has no tag list');
T::same(['row' => 'Row', 'column' => 'Column'], $controls['layout_div_flex_direction']['accepts']['choices'], 'other list-shaped choices are keyed by value too');
T::same(['false' => 'Off', 'true' => 'On'], $controls['layout_div_global_container']['accepts']['choices'], 'boolean values are spelled out');
