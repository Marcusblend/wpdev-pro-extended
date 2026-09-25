<?php

declare(strict_types=1);

use ProExtended\Elements\ElementLint;
use ProExtended\Elements\LintContext;

T::group('ElementLint native-first codes');

$codes = static function (array $issues): array {
    $found = array_values(array_unique(array_column($issues, 'code')));
    sort($found);

    return $found;
};

$seen = [];

// custom-shortcode ------------------------------------------------------------

$sources = [
    'site_promo'     => ['origin' => 'plugin', 'file' => 'plugins/site-helpers/site-helpers.php'],
    'opening_hours'  => ['origin' => 'mu-plugin', 'file' => 'mu-plugins/tweaks.php'],
    'child_year'     => ['origin' => 'theme', 'file' => 'themes/pro-child/functions.php'],
    'caption'        => ['origin' => 'core', 'file' => 'wp-includes/media.php'],
    'x_button'       => ['origin' => 'themeco', 'file' => 'themes/pro/cornerstone/includes/shortcodes/button.php'],
    'cs_form'        => ['origin' => 'themeco', 'file' => 'plugins/cornerstone-forms/cornerstone-forms.php'],
];
$asked = [];
$shortcodes = new ElementLint(new LintContext(shortcodeSource: static function (string $tag) use ($sources, &$asked): ?array {
    $asked[] = $tag;

    return $sources[$tag] ?? null;
}));

$element = static fn(array $data, string $type = 'text'): array => ['_type' => $type, '_m' => ['e' => 1], '_bp_base' => '4_4'] + $data;

$issues = $shortcodes->tree([$element(['text_content' => 'Summer deal: [site_promo] and again [site_promo]'])]);
T::same(['custom-shortcode'], $codes($issues), 'custom-shortcode: a tag from a site plugin');
T::same(1, count($issues), 'one warning per tag, not per use');
T::ok(str_contains($issues[0]['message'], 'plugins/site-helpers/site-helpers.php'), 'the message names the file');
T::ok(str_contains($issues[0]['message'], 'Dynamic Content or Twig'), 'and the native replacement');
T::ok(str_starts_with($issues[0]['message'], 'text_content: [site_promo]'), 'and where it is used');
$seen['custom-shortcode'] = true;

T::same(['custom-shortcode'], $codes($shortcodes->tree([$element(['text_content' => '[opening_hours]'])])), 'an mu-plugin tag');
T::same(['custom-shortcode'], $codes($shortcodes->tree([$element(['anchor_text_primary_content' => '© [child_year]'], 'button')])), 'a child theme tag, in any copy key');
T::same([], $codes($shortcodes->tree([$element(['text_content' => '[caption id="1"]Photo[/caption]'])])), 'a core tag is fine');
T::same([], $codes($shortcodes->tree([$element(['text_content' => '[x_button] [cs_form]'])])), 'Pro and Themeco extension tags are fine');
T::same([], $codes($shortcodes->tree([$element(['text_content' => 'Read [this] and [[site_promo]]'])])), 'unregistered text and escaped tags are fine');

$asked = [];
$shortcodes->tree([$element([
    'css'            => '$el [site_promo] { color: red; }',
    'show_condition' => [['condition' => 'expression:string', 'operand' => '[site_promo]', 'operator' => 'is', 'value' => '1', 'group' => true]],
    'custom_atts'    => '{"data-x":"[site_promo]"}',
    '_p_data'        => ['label' => '[site_promo]'],
    'looper_provider_json' => '[{"a":"[site_promo]"}]',
])]);
T::same([], $asked, 'css, conditions, attributes, parameters and looper data are not scanned for shortcodes');

$noSource = new ElementLint(new LintContext());
T::same([], $codes($noSource->tree([$element(['text_content' => '[site_promo]'])])), 'without a shortcode registry the check stays quiet');

// hardcoded-date --------------------------------------------------------------

$dates = new ElementLint(new LintContext());

$positive = [
    '© 2024 Example Co.'                                   => 'a year after ©',
    '&copy; 2023 Example Co.'                              => 'an entity copyright mark',
    'Copyright 2022. All rights reserved.'                 => 'a year after Copyright',
    '<p>(c) 2021 Example</p>'                              => '(c) in markup',
    '© 2015–2024 Example'                                  => 'a range with a typed end year',
    '2024 © Example'                                       => 'a year before ©',
    'Book before November 1 and save 15%'                  => 'before a month and day',
    'Offer valid until 12/31'                              => 'until a numeric date',
    'Early rates through Nov 1st'                          => 'through an abbreviated month',
    'Sale ends on Sept. 30, 2026'                          => 'ends on a date with a year',
    'Discount expires 1 March'                             => 'a day-first date',
    '<strong>Save 20%</strong> until&nbsp;<em>October 15</em>' => 'a date split by markup',
];

foreach ($positive as $text => $label) {
    $issues = $dates->tree([$element(['text_content' => $text])]);
    T::same(['hardcoded-date'], $codes($issues), "hardcoded-date: {$label}");
}

$seen['hardcoded-date'] = true;

$issues = $dates->tree([$element(['text_content' => '© 2024 Example'])]);
T::ok(str_contains($issues[0]['message'], '{{dc:global:date format="Y"}}'), 'the copyright message gives the token');
$issues = $dates->tree([$element(['text_content' => 'Book before November 1'])]);
T::ok(str_contains($issues[0]['message'], 'Global Parameter') && str_contains($issues[0]['message'], 'global:today'), 'the promo message gives the parameter and condition');
T::ok(str_contains($issues[0]['message'], '"before November 1"'), 'and quotes the wording');

$negative = [
    '© {{dc:global:date format="Y"}} Example'           => 'a token year',
    '© {{ "now"|date("Y") }} Example'                   => 'a Twig year',
    '© 2015–{{dc:global:date format="Y"}} Example'      => 'a founding year with a live end year',
    '© Example Co. All rights reserved.'                => 'a mark without a year',
    'Founded in 1998, serving the valley since.'        => 'a year without a copyright mark',
    'Walk through the door before you pay'              => 'deadline words without a date',
    'Open until 9pm on weekdays'                        => 'a time, not a date',
    'Book before {{dc:global:promo_end}}'               => 'a date from a parameter',
    'Get through 2/3 of the course in a week'           => 'a fraction',
    'We may ship through May'                           => 'a month without a day',
    'Ends Sunday'                                       => 'a weekday',
    'Rated 5 of 5 until now'                            => 'numbers that are not dates',
];

foreach ($negative as $text => $label) {
    T::same([], $codes($dates->tree([$element(['text_content' => $text])])), "no hardcoded-date for {$label}");
}

T::same([], $codes($dates->tree([$element([
    'text_content'   => 'Hello',
    'show_condition' => [['condition' => 'global:today', 'value' => 'before November 1, 2026', 'toggle' => true, 'group' => true]],
    '_p_data'        => ['note' => 'Book before November 1'],
    'css'            => '/* © 2024 */',
])])), 'conditions, parameters and css are not copy');

// html-in-text ----------------------------------------------------------------

$html = new ElementLint(new LintContext());

$inherit = [
    'text_font_size'  => 'inherit',
    'text_text_color' => 'inherit',
];

$pasted = $element(['text_content' => '<div class="hero"><h2 class="hero__title">Stay</h2><ul><li>One</li></ul></div>'] + $inherit);
$issues = $html->tree([$pasted]);
T::same(['html-in-text'], $codes($issues), 'html-in-text: block HTML in a Text whose typography inherits');
T::ok(str_contains($issues[0]['message'], '<div>') && str_contains($issues[0]['message'], 'all inherit'), 'the message names the tag and the reason');
T::ok(str_contains($issues[0]['message'], 'component'), 'and suggests elements and components');
$seen['html-in-text'] = true;

$headline = $element(['text_content' => '<section><h1>Title</h1></section>', 'text_line_height' => 'inherit'] + $inherit, 'headline');
T::same(['html-in-text'], $codes($html->tree([$headline])), 'a Headline with its line height inherited too');

$contents = $element(['text_content' => '<p class="lead">Intro</p><table><tr><td>1</td></tr></table>', 'css' => '$el { display: contents; }']);
$issues = $html->tree([$contents]);
T::same(['html-in-text'], $codes($issues), 'display: contents counts too, with typography left on');
T::ok(str_contains($issues[0]['message'], 'display: contents'), 'and says so');

T::same(['html-in-text'], $codes($html->tree([$element(['text_content' => '<p class="kicker">Hi</p>'] + $inherit)])), 'a p with a class is block markup');

$quiet = [
    [$element(['text_content' => '<p>One</p><p>Two <strong>bold</strong></p>'] + $inherit), 'plain paragraphs'],
    [$element(['text_content' => '<div>Styled</div>', 'text_font_size' => '1.25rem', 'text_text_color' => 'inherit']), 'a text with its own font size'],
    [$element(['text_content' => '<ul><li>A</li></ul>']), 'a list in a text at its defaults (size 1em, colour set)'],
    [$element(['text_content' => '<h2>Title</h2>', 'text_font_family' => 'body'] + $inherit), 'a font reference is not inherit'],
    [$element(['text_content' => '<div class="x">Hi</div>'] + $inherit, 'headline'), 'a Headline keeps its 1.4 line height unless it is set to inherit'],
    [$element(['text_content' => '<div class="x">Hi</div>'] + $inherit, 'layout-div'), 'other element types'],
];

foreach ($quiet as [$data, $label]) {
    T::same([], $codes($html->tree([$data])), "no html-in-text for {$label}");
}

T::same('p class', ElementLint::blockTag('<p class="a">x</p>'), 'blockTag reports a classed paragraph');
T::same(null, ElementLint::blockTag('<span class="a">x</span><br><strong>y</strong>'), 'inline markup is not block markup');

// Coverage ------------------------------------------------------------------

T::same([], array_values(array_diff(array_keys(ElementLint::NATIVE_CODES), array_keys($seen))), 'every native-first code has a fixture');
T::same([], array_values(array_diff(array_keys(ElementLint::NATIVE_CODES), array_keys(ElementLint::CODES))), 'and every one is a published code');
