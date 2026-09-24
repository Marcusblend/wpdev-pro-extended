<?php

declare(strict_types=1);

use ProExtended\Site\Extensions;

T::group('Extensions');

// Who a file belongs to --------------------------------------------------------

$roots = [
    'cornerstone' => '/srv/wp/wp-content/themes/pro/cornerstone/',
    'plugins'     => '/srv/wp/wp-content/plugins',
    'mu_plugins'  => '/srv/wp/wp-content/mu-plugins',
    'template'    => '/srv/wp/wp-content/themes/pro',
    'stylesheet'  => '/srv/wp/wp-content/themes/pro-child',
];

T::same('cornerstone', Extensions::sourceOf('/srv/wp/wp-content/themes/pro/cornerstone/includes/elements/definitions/headline.php', $roots), 'Cornerstone inside Pro is Cornerstone, not the theme');
T::same('theme:pro', Extensions::sourceOf('/srv/wp/wp-content/themes/pro/framework/functions.php', $roots), 'the rest of Pro is the theme');
T::same('child-theme:pro-child', Extensions::sourceOf('/srv/wp/wp-content/themes/pro-child/functions.php', $roots), 'the child theme is named as such');
T::same('plugin:cornerstone-forms', Extensions::sourceOf('/srv/wp/wp-content/plugins/cornerstone-forms/extension/Elements/Form.php', $roots), 'a plugin is named by its folder');
T::same('mu-plugin:site.php', Extensions::sourceOf('/srv/wp/wp-content/mu-plugins/site.php', $roots), 'a must-use plugin by its file');
T::same('unknown', Extensions::sourceOf('/usr/share/php/lib.php', $roots), 'anything else is unknown');
T::same('unknown', Extensions::sourceOf(null, $roots), 'as is a callback that could not be reflected');
T::same('plugin:x', Extensions::sourceOf('C:\\wp\\wp-content\\plugins\\x\\x.php', ['plugins' => 'C:\\wp\\wp-content\\plugins\\']), 'Windows paths are normalised');
T::same('cornerstone', Extensions::sourceOf('/srv/wp/wp-content/plugins/cornerstone/includes/x.php', ['cornerstone' => '/srv/wp/wp-content/plugins/cornerstone', 'plugins' => '/srv/wp/wp-content/plugins']), 'standalone Cornerstone in the plugins folder is still Cornerstone');
T::same('plugin:cornerstone-forms', Extensions::sourceOf('/srv/wp/wp-content/plugins/cornerstone-forms/boot.php', ['cornerstone' => '/srv/wp/wp-content/plugins/cornerstone', 'plugins' => '/srv/wp/wp-content/plugins']), 'and a plugin whose folder starts the same is not');

// Grouping the registries by source ------------------------------------------

$cs = '/srv/wp/wp-content/themes/pro/cornerstone/includes/x.php';
$forms = '/srv/wp/wp-content/plugins/cornerstone-forms/boot.php';

$report = Extensions::describe([
    'elements'        => ['headline' => $cs, 'cornerstone-form' => $forms, 'cornerstone-form-input' => $forms, 'mystery' => null],
    'dynamic_content' => ['post' => $cs, 'form' => $forms],
    'loopers'         => null,
], $roots, [
    'cornerstone-forms/cornerstone-forms.php' => ['Name' => 'Cornerstone Forms', 'Version' => '2.1.0'],
    'other/other.php'                         => ['Name' => 'Other'],
]);

T::same(['cornerstone', 'plugin:cornerstone-forms', 'unknown'], array_keys($report['sources']), 'Cornerstone first, plugins next, the unattributed last');
T::same(['cornerstone-form', 'cornerstone-form-input'], $report['sources']['plugin:cornerstone-forms']['elements'], 'a plugin\'s elements are grouped under it');
T::same(['form'], $report['sources']['plugin:cornerstone-forms']['dynamic_content'], 'so is its Dynamic Content group');
T::same('Cornerstone Forms', $report['sources']['plugin:cornerstone-forms']['label'], 'labelled with the plugin\'s own name');
T::same('2.1.0', $report['sources']['plugin:cornerstone-forms']['version'], 'and version');
T::same('cornerstone-forms/cornerstone-forms.php', $report['sources']['plugin:cornerstone-forms']['plugin'], 'and plugin file');
T::same(['headline'], $report['sources']['cornerstone']['elements'], 'Cornerstone\'s own entries are grouped too');
T::same(['mystery'], $report['sources']['unknown']['elements'], 'an entry that could not be attributed is reported, not guessed');
T::same(['elements' => 4, 'dynamic_content' => 2, 'loopers' => 0], $report['counts'], 'every registry is counted');
T::same(['loopers'], $report['unreadable'], 'a registry that could not be read says so');
T::same([], $report['sources']['cornerstone']['loopers'], 'and reports nothing it did not read');

// Which callback names the owner ----------------------------------------------

T::same($forms, Extensions::ownerFile([null, $forms, $cs], $roots, false), 'the first callback that resolves names an element\'s owner');
T::same($cs, Extensions::ownerFile([$forms, $cs], $roots, true), 'a plugin adding to one of Cornerstone\'s groups does not take it over');
T::same($forms, Extensions::ownerFile([$forms], $roots, true), 'a group only a plugin serves is the plugin\'s');
T::same(null, Extensions::ownerFile([null, ''], $roots, false), 'nothing resolved, no owner');

// Reflection -------------------------------------------------------------------

function pe_extensions_test_callback(): void {}

final class PeExtensionsTestProvider
{
    public static function make(): void {}

    public function __invoke(): void {}

    public function wrap(): \Closure
    {
        return static fn () => null;
    }
}

T::same(__FILE__, Extensions::callableFile(static fn () => null), 'a closure is traced to its file');
T::same(__FILE__, Extensions::callableFile('pe_extensions_test_callback'), 'so is a function name');
T::same(__FILE__, Extensions::callableFile('PeExtensionsTestProvider::make'), 'a Class::method string');
T::same(__FILE__, Extensions::callableFile(['PeExtensionsTestProvider', 'make']), 'a [class, method] pair');
T::same(__FILE__, Extensions::callableFile(new PeExtensionsTestProvider()), 'an invokable object');
T::same(null, Extensions::callableFile('strlen'), 'a PHP built-in has no file');
T::same(null, Extensions::callableFile('no_such_function_here'), 'nor does a name that is not defined');
T::same(null, Extensions::callableFile(['PeExtensionsTestProvider', 'missing']), 'nor a missing method');
T::same(null, Extensions::callableFile(42), 'nor a value that is not callable');
T::same(null, Extensions::callableFile((new PeExtensionsTestProvider())->wrap(), 'PeExtensionsTestProvider'), 'a closure made inside the skipped class is ignored');
T::same(__FILE__, Extensions::classFile('PeExtensionsTestProvider'), 'a provider class is traced to its file');
T::same(null, Extensions::classFile('No\\Such\\ClassHere'), 'a class that does not exist is not');

// ACF, free or Pro --------------------------------------------------------------

T::same(['active' => true, 'pro' => false, 'version' => '6.3.0'], Extensions::acf(true, false, false, '6.3.0'), 'free ACF is not ACF Pro');
T::same(true, Extensions::acf(true, true, null, '6.3.0')['pro'], 'the ACF_PRO constant marks Pro');
T::same(true, Extensions::acf(true, false, true, '6.3.0')['pro'], 'so does acf_get_setting("pro")');
T::same(['active' => false, 'pro' => false, 'version' => null], Extensions::acf(false, true, true, '6.3.0'), 'no ACF, no Pro');

// ─── Symlinked roots (WP Engine defines paths through /sites/<install>) ──────

$realBase = sys_get_temp_dir() . '/pe-ext-real-' . getmypid();
$linkBase = sys_get_temp_dir() . '/pe-ext-link-' . getmypid();
@mkdir($realBase . '/themes/pro/cornerstone/includes', 0777, true);
file_put_contents($realBase . '/themes/pro/cornerstone/includes/x.php', '<?php');
@symlink($realBase, $linkBase);

if (is_link($linkBase)) {
    T::same(
        'cornerstone',
        \ProExtended\Site\Extensions::sourceOf($realBase . '/themes/pro/cornerstone/includes/x.php', ['cornerstone' => $linkBase . '/themes/pro/cornerstone/']),
        'a file PHP reports by its real path matches a root defined through a symlink'
    );
}

@unlink($realBase . '/themes/pro/cornerstone/includes/x.php');
@unlink($linkBase);
