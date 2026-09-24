<?php

declare(strict_types=1);

use ProExtended\Elements\ShortcodeOrigin;

T::group('ShortcodeOrigin');

// Tags --------------------------------------------------------------------

T::same(['site_promo'], ShortcodeOrigin::tags('Save now [site_promo]'), 'a bare tag is found');
T::same(['gallery', 'x_button'], ShortcodeOrigin::tags('[gallery ids="1,2"] then [x_button/] and [gallery]'), 'tags with attributes and self-closing tags, once each');
T::same(['contact-form-7'], ShortcodeOrigin::tags('[contact-form-7 id="12" title="Contact"]'), 'a hyphenated tag');
T::same([], ShortcodeOrigin::tags('[[site_promo]] prints literally'), 'an escaped tag runs nothing');
T::same(['half'], ShortcodeOrigin::tags('x[[half]'), 'only a fully doubled tag is an escape');
T::same([], ShortcodeOrigin::tags('[/site_promo] closes'), 'a closing tag is not a use');
T::same([], ShortcodeOrigin::tags('See note [1] and [2]'), 'footnote marks are not tags');
T::same([], ShortcodeOrigin::tags('{{dc:post:title}} and [dc:post:title]'), 'token syntax is not a tag');
T::same([], ShortcodeOrigin::tags('{% set days = ["mon", "tue"] %}{{ days[0] }}'), 'Twig lists are not tags');
T::same([], ShortcodeOrigin::tags('No brackets at all'), 'plain text has none');

// Defining files ------------------------------------------------------------

$base = dirname(__DIR__) . '/fixtures/shortcode-origin';
$wp = $base . '/wp';

require_once $wp . '/wp-includes/shortcodes.php';
require_once $wp . '/wp-content/plugins/site-helpers/site-helpers.php';
require_once $wp . '/wp-content/plugins/cornerstone-forms/cornerstone-forms.php';
require_once $wp . '/wp-content/plugins/max-product/max-product.php';
require_once $wp . '/wp-content/themes/pro/cornerstone/includes/shortcodes.php';
require_once $wp . '/wp-content/themes/pro-child/functions.php';
require_once $base . '/elsewhere/library.php';
$muClosure = require $wp . '/wp-content/mu-plugins/tweaks.php';

$helpers = $wp . '/wp-content/plugins/site-helpers/site-helpers.php';

T::same($helpers, ShortcodeOrigin::definingFile('pe_fixture_site_promo'), 'a function name resolves to its file');
T::same($helpers, ShortcodeOrigin::definingFile('PeFixtureSiteHelpers::year'), 'a "Class::method" string');
T::same($helpers, ShortcodeOrigin::definingFile(['PeFixtureSiteHelpers', 'year']), 'a static method pair');
T::same($helpers, ShortcodeOrigin::definingFile([new PeFixtureSiteHelpers(), 'hours']), 'an object method pair');
T::same($helpers, ShortcodeOrigin::definingFile(new PeFixtureInvokable()), 'an invokable object');
T::same($wp . '/wp-content/mu-plugins/tweaks.php', ShortcodeOrigin::definingFile($muClosure), 'a closure');
T::same('', ShortcodeOrigin::definingFile('strtoupper'), 'a PHP function has no file');
T::same(null, ShortcodeOrigin::definingFile('pe_fixture_no_such_function'), 'an unknown function cannot be told');
T::same(null, ShortcodeOrigin::definingFile([new PeFixtureSiteHelpers(), 'nope']), 'nor a missing method');
T::same(null, ShortcodeOrigin::definingFile(42), 'nor something that is not callable');

// Classification ------------------------------------------------------------

$roots = [
    'abspath'         => [$wp . '/'],
    'core'            => [$wp . '/wp-includes', $wp . '/wp-admin'],
    'themeco'         => [$wp . '/wp-content/themes/pro/cornerstone', $wp . '/wp-content/themes/pro'],
    'mu_plugins'      => [$wp . '/wp-content/mu-plugins'],
    'plugins'         => [$wp . '/wp-content/plugins/'],
    'themes'          => [$wp . '/wp-content/themes'],
    'themeco_plugins' => ['max-product'],
];

$cases = [
    ['pe_fixture_core_caption', 'core', 'WordPress core'],
    ['pe_fixture_cornerstone_shortcode', 'themeco', 'Cornerstone inside the Pro theme'],
    ['pe_fixture_cornerstone_forms', 'themeco', 'a cornerstone-* plugin'],
    ['pe_fixture_max_product', 'themeco', 'a Max product folder'],
    ['pe_fixture_site_promo', 'plugin', 'a site plugin'],
    ['pe_fixture_child_theme', 'theme', 'a child theme\'s functions.php'],
    ['pe_fixture_elsewhere', 'other', 'a file outside the install'],
    ['strtoupper', 'internal', 'a PHP function'],
];

foreach ($cases as [$callback, $origin, $label]) {
    $file = (string) ShortcodeOrigin::definingFile($callback);
    T::same($origin, ShortcodeOrigin::classify($file, $roots), "classify: {$label}");
}

T::same('mu-plugin', ShortcodeOrigin::classify((string) ShortcodeOrigin::definingFile($muClosure), $roots), 'classify: an mu-plugin closure');

T::same('plugins/site-helpers/site-helpers.php', ShortcodeOrigin::label($helpers, $roots), 'a plugin file is named from the plugins folder');
T::same('mu-plugins/tweaks.php', ShortcodeOrigin::label($wp . '/wp-content/mu-plugins/tweaks.php', $roots), 'an mu-plugin from its folder');
T::same('themes/pro-child/functions.php', ShortcodeOrigin::label($wp . '/wp-content/themes/pro-child/functions.php', $roots), 'a theme file from the themes folder');
T::same('PHP itself', ShortcodeOrigin::label('', $roots), 'a PHP function says so');

T::same('plugin', ShortcodeOrigin::classify('C:\\site\\wp-content\\plugins\\helpers\\helpers.php', ['plugins' => ['C:/site/wp-content/plugins']]), 'Windows separators are normalized');
T::same('plugin', ShortcodeOrigin::classify('/srv/wp-content/plugins/cornerstonex/x.php', ['plugins' => ['/srv/wp-content/plugins']]), 'a folder that only starts with "cornerstone" is not Themeco\'s');
T::same('other', ShortcodeOrigin::classify('/srv/wp-content/plugins-old/x.php', ['plugins' => ['/srv/wp-content/plugins']]), 'a sibling folder with a longer name is not inside the root');
T::ok(ShortcodeOrigin::isThemecoPlugin('cornerstone'), 'the standalone Cornerstone plugin is Themeco\'s');
T::same(['core', 'themeco', 'internal'], ShortcodeOrigin::PLATFORM, 'core, Themeco and PHP are the platform');
