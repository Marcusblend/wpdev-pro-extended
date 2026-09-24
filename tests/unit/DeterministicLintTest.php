<?php

declare(strict_types=1);

use ProExtended\Elements\ControlSurface;
use ProExtended\Elements\ElementLint;
use ProExtended\Elements\LintContext;
use ProExtended\Elements\SurfaceStore;

T::group('Deterministic lint');

// An in-memory wp_options table that records how each option was written.
final class SurfaceStoreFakeOptions
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, bool> */
    public array $autoload = [];

    public int $reads = 0;

    public function store(string $cornerstone, string $plugin = '1.5.0'): SurfaceStore
    {
        return new SurfaceStore(
            $cornerstone,
            $plugin,
            function (string $name, mixed $default): mixed {
                $this->reads++;

                return array_key_exists($name, $this->values) ? $this->values[$name] : $default;
            },
            function (string $name, mixed $value, bool $autoload): bool {
                $this->values[$name] = $value;
                $this->autoload[$name] = $autoload;

                return true;
            },
            function (string $name): bool {
                unset($this->values[$name], $this->autoload[$name]);

                return true;
            },
        );
    }
}

// A headline surface in the shape ControlSurface::build() returns.
$surface = ControlSurface::build(
    ['control_nav' => ['text' => 'Primary', 'text:design' => 'Design'], 'controls' => [
        ['type' => 'text-format', 'group' => 'text:design', 'label' => 'Format', 'keys' => [
            'font_family' => 'text_font_family',
            'text_color'  => 'text_text_color',
        ]],
        ['key' => 'text_bg_color', 'type' => 'color', 'group' => 'text:design', 'label' => 'Background'],
        ['key' => 'text_border_color', 'type' => 'color', 'group' => 'text:design', 'label' => 'Border'],
    ]],
    ['text_font_family' => 'style:font-family', 'text_text_color' => 'style:color', 'text_bg_color' => 'style:color', 'text_border_color' => 'style:color'],
    []
);

// SurfaceStore ----------------------------------------------------------------

$options = new SurfaceStoreFakeOptions();
$store = $options->store('7.9.4');

T::same(null, $store->get('headline'), 'nothing is stored at first');

$store->put('headline', $surface);
$name = SurfaceStore::SURFACE_PREFIX . md5('headline');

T::same($surface, $store->get('headline'), 'a stored surface reads back');
T::same(false, $options->autoload[$name] ?? null, 'the surface option is not autoloaded');
T::same(false, $options->autoload[SurfaceStore::INDEX_OPTION] ?? null, 'nor is the index');
T::same(['headline'], $store->types(), 'the index lists the stored types');
T::same('7.9.4', $options->values[SurfaceStore::INDEX_OPTION]['cornerstone'] ?? null, 'the index records the Cornerstone version');

$later = $options->store('7.9.4');
T::same($surface, $later->get('headline'), 'a later request reads the same surface: nothing expires');

$updated = $options->store('7.9.5');
T::same(null, $updated->get('headline'), 'a Cornerstone update makes the stored set stale');
$updated->put('text', $surface);
T::ok(! array_key_exists($name, $options->values), 'the first write under the new version drops the old surfaces');
T::same(['text'], $updated->types(), 'and starts a new index');

$upgraded = $options->store('7.9.5', '1.5.1');
T::same(null, $upgraded->get('text'), 'a Pro Extended update does too, since it can change what a surface holds');

$fresh = new SurfaceStoreFakeOptions();
$fresh->store('7.9.4')->put('headline', $surface);
$fresh->store('7.9.4')->put('text', $surface);
T::same(2, $fresh->store('7.9.4')->clear(), 'clear deletes every stored surface');
T::same([], $fresh->values, 'and the index, leaving no options behind');

$fresh->values[SurfaceStore::INDEX_OPTION] = 'garbage';
T::same(null, $fresh->store('7.9.4')->get('headline'), 'a damaged index reads as empty');

$fresh->store('7.9.4')->put('headline', ['controls' => 'not a list']);
T::same(null, $fresh->store('7.9.4')->get('headline'), 'a damaged surface reads as missing');

// The same layout gives the same warnings on every run -------------------------

$lintFor = static function (SurfaceStore $store): ElementLint {
    return new ElementLint(new LintContext(
        cssProperties: static function (string $type) use ($store): array {
            $surface = $store->get($type);

            return $surface === null ? [] : ControlSurface::cssProperties($surface);
        },
        styleKeys: static function (string $type) use ($store): array {
            $surface = $store->get($type);

            return $surface === null ? [] : ControlSurface::styleProperties($surface);
        },
    ));
};

$layout = [[
    '_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4',
    'text_text_color' => '#1a73e8',
    'css'             => '$el { color: red; }',
]];

$site = new SurfaceStoreFakeOptions();
$site->store('7.9.4')->put('headline', $surface);

$runs = [];

for ($run = 0; $run < 3; $run++) {
    $runs[] = array_column($lintFor($site->store('7.9.4'))->tree($layout), 'code');
}

T::same(['css-over-control', 'literal-color'], $runs[0], 'a stored surface gives both style warnings');
T::ok($runs[0] === $runs[1] && $runs[1] === $runs[2], 'and the same warnings on every run');

// literal-color: interaction values, breakpoints and references ---------------

$lint = new ElementLint(new LintContext(styleKeys: static fn(string $type): array => ControlSurface::styleProperties($surface)));
$codes = static fn(array $element): array => array_column($lint->tree([['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4'] + $element]), 'code');
$messages = static fn(array $element): array => array_column($lint->tree([['_type' => 'headline', '_m' => ['e' => 1], '_bp_base' => '4_4'] + $element]), 'message');

T::same(['literal-color'], $codes(['text_text_color_alt' => '#ff0000']), 'a literal hover colour (_alt) is flagged');
T::ok(str_starts_with($messages(['text_bg_color_alt' => 'rgb(0, 0, 0)'])[0] ?? '', 'text_bg_color_alt is the literal colour'), 'and named by its _alt key');

$responsive = ['_bp_data4_4' => [
    'text_text_color'     => [null, '#fff', null, 'global-color:brand', null],
    'text_bg_color_alt'   => [null, null, 'rgba(0,0,0,.5)', null, null],
    'text_font_family'    => ['Georgia, serif', null, null, null, null],
    'text_font_size'      => [null, '2rem', null, null, null],
]];
T::same(['literal-color', 'literal-color', 'literal-font-family'], $codes($responsive), 'per-breakpoint literals are flagged, references are not');
T::ok(in_array('_bp_data4_4.text_text_color[1] is the literal colour #fff. A palette reference — "global-color:<id>", or "global-color:<id>:0.5" for alpha — follows the palette instead of keeping a copy of it. list_colors has the ids.', $messages($responsive), true), 'the message points at the breakpoint slot');

$references = [
    'text_text_color'     => 'rgba(var(--brand-rgb), 0.5)',
    'text_bg_color'       => 'color-mix(in srgb, var(--surface) 80%, white)',
    'text_border_color'   => 'rgba(global-color:line, 0.4)',
    'text_text_color_alt' => 'VAR(--accent)',
];
T::same([], $codes($references), 'a value with var( or global-color: anywhere is a reference');

foreach (['sans-serif', 'serif', 'monospace', 'system-ui', 'inherit', '"sans-serif"', ' ui-sans-serif '] as $family) {
    T::same([], $codes(['text_font_family' => $family]), "a bare generic family ({$family}) is not a literal stack");
}

T::same(['literal-font-family'], $codes(['text_font_family' => 'Helvetica, sans-serif']), 'a named font with a generic fallback still is');
T::same(['literal-font-family'], $codes(['text_font_family' => 'Georgia']), 'and so is a single named font');
T::same([], $codes(['text_font_family' => 'global-ff:body, sans-serif']), 'a global font with a fallback is a reference');
