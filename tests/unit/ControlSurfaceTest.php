<?php

declare(strict_types=1);

use ProExtended\Elements\ControlSurface;

T::group('ControlSurface');

// Shapes taken from Cornerstone 7.9.4's get_element_inspector_data().
$inspector = [
    'control_nav' => [
        'text'          => 'Primary',
        'text:setup'    => 'Setup',
        'text:text'     => 'Text',
        'effects'       => 'Effects',
        'effects:setup' => '',
        'omega'         => 'Customize',
        'omega:presets' => 'Presets',
    ],
    'controls' => [
        [
            'type'  => 'group',
            'group' => 'text:setup',
            'label' => '',
            'controls' => [
                [
                    'key'     => 'text_base_font_size',
                    'type'    => 'unit-slider',
                    'label'   => 'Font Size',
                    'options' => [
                        'available_units' => ['px', 'em', 'rem'],
                        'valid_keywords'  => ['calc'],
                        'fallback_value'  => '1em',
                    ],
                ],
                [
                    'key'     => 'text_tag',
                    'type'    => 'select',
                    'label'   => 'HTML Tag',
                    'options' => ['choices' => ['h1' => '<h1>', 'h2' => '<h2>']],
                ],
                [
                    'key'        => 'text_content',
                    'type'       => 'text-editor',
                    'label'      => 'Content',
                    'conditions' => [['key' => 'text_type', 'value' => 'standard']],
                ],
            ],
        ],
        [
            'type'  => 'text-format',
            'group' => 'text:text',
            'label' => 'Format',
            'keys'  => [
                'font_size'  => 'text_font_size',
                'text_color' => 'text_text_color',
                'text_align' => 'text_text_align',
            ],
        ],
        [
            'key'   => 'text_border_radius',
            'type'  => 'border-radius',
            'group' => 'text:design',
            'label' => '{{prefix}} Border Radius',
        ],
        [
            'type'  => 'group',
            'group' => 'effects:setup',
            'label' => 'Interaction',
            'controls' => [],
        ],
        [
            'key'     => '__preset_control',
            'type'    => 'preset',
            'group'   => 'omega:presets',
            'label'   => 'Preset',
            'options' => ['ignoreControlData' => true],
        ],
    ],
];

$designations = [
    'text_base_font_size' => 'style',
    'text_tag'            => 'markup',
    'text_content'        => 'markup:text',
    'text_font_size'      => 'style',
    'text_text_color'     => 'style:color',
    'text_text_align'     => 'style',
    'text_border_radius'  => 'style',
];

$defaults = [
    'text_base_font_size' => '1em',
    'text_tag'            => 'h2',
    'text_font_size'      => 'inherit',
];

$surface = ControlSurface::build($inspector, $designations, $defaults);

// Panels -------------------------------------------------------------------

T::same(4, count($surface['panels']), 'only nav ids with a colon are panels');
T::same('text:setup', $surface['panels'][0]['id'], 'the first panel keeps its id');
T::same('Setup', $surface['panels'][0]['name'], 'and its name');
T::same('Primary', $surface['panels'][0]['tab'], 'and the tab it sits under');
T::same('Effects', $surface['panels'][2]['name'], 'a panel with no name of its own takes its tab\'s');

// Controls -----------------------------------------------------------------

$byLabel = [];

foreach ($surface['controls'] as $control) {
    $byLabel[$control['label']] = $control;
}

T::same(6, count($surface['controls']), 'a control that writes no key is left out, nested controls are kept');
T::ok(! isset($byLabel['Interaction']), 'an empty group contributes nothing');

$size = $byLabel['Font Size'];
T::same(['text_base_font_size'], $size['keys'], 'a single-key control reports its key');
T::same('Setup', $size['panel_name'], 'a nested control takes its group\'s panel');
T::same('1em', $size['defaults']['text_base_font_size'], 'the default comes along');
T::same('style', $size['writes'], 'a style key writes style');
T::same(['px', 'em', 'rem'], $size['accepts']['units'], 'accepted units are listed');
T::same(['calc'], $size['accepts']['keywords'], 'so are keywords');
T::same('1em', $size['accepts']['fallback'], 'and the fallback value');

$tag = $byLabel['HTML Tag'];
T::same(['h1' => '<h1>', 'h2' => '<h2>'], $tag['accepts']['choices'], 'a select reports its choices');
T::same('markup', $tag['writes'], 'a markup key writes markup');

T::ok($byLabel['Content']['conditional'], 'a control with conditions is marked conditional');
T::ok(! isset($byLabel['Font Size']['conditional']), 'one without them is not');

$format = $byLabel['Format'];
T::same('text_font_size', $format['named_keys']['font_size'], 'a keys map is kept under named_keys');
T::same('text_text_color', $format['named_keys']['text_color'], 'every name in the map is kept');
T::same(3, count($format['keys']), 'and every key it writes is listed');
T::same('style', $format['writes'], 'a colour designation still counts as style');

T::same('Border Radius', $byLabel['Border Radius']['label'], 'the {{prefix}} placeholder is dropped from a label');

T::ok(isset($byLabel['Preset']), 'the native preset control is surfaced');
T::same('Presets', $byLabel['Preset']['panel_name'], 'under its own panel');

// Search -------------------------------------------------------------------

T::same(1, count(ControlSurface::search($surface, 'font size')['controls']), 'a label match is found');
T::same(1, count(ControlSurface::search($surface, 'TEXT_TAG')['controls']), 'a key match ignores case');
T::same(1, count(ControlSurface::search($surface, 'text_color')['controls']), 'a named key is searchable');
T::same(3, count(ControlSurface::search($surface, 'Setup')['controls']), 'a panel name matches its controls');
T::same(0, count(ControlSurface::search($surface, 'nothing here')['controls']), 'no match returns nothing');
T::same(6, count(ControlSurface::search($surface, '  ')['controls']), 'an empty term returns everything');
T::same(4, count(ControlSurface::search($surface, 'font')['panels']), 'the panels stay with a narrowed surface');

// Missing data -------------------------------------------------------------

$bare = ControlSurface::build([], [], []);
T::same([], $bare['controls'], 'an element with no inspector data yields no controls');
T::same([], $bare['panels'], 'and no panels');
