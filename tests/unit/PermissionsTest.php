<?php

declare(strict_types=1);

use ProExtended\Cornerstone\Permissions;

T::group('Permissions');

// Every gated tool names a key Cornerstone actually has --------------------

// The keys Cornerstone 7.9.4 defines (Services/Permissions.php defaultPolicy()
// and the Global Variables tab's own "global.variables").
$known = [
    'content.page', 'layout', 'component', 'template', 'template.manage_library',
    'element-library', 'global', 'global.colors', 'global.fonts', 'global.theme_options', 'global.variables',
    'global.edit_custom_css', 'global.edit_custom_js',
];

foreach (Permissions::TOOL_KEYS as $tool => $key) {
    T::ok(in_array($key, $known, true), sprintf('"%s" is gated on a real Cornerstone permission (%s)', $tool, $key));
}

T::same('layout', Permissions::forTool('update_layout'), 'a writing tool is gated on layout');
T::same('global.variables', Permissions::forTool('set_variables'), 'variables have their own permission');
T::same('template.manage_library', Permissions::forTool('import_tco'), 'writing to the library needs the library permission');
T::same('template', Permissions::forTool('list_templates'), 'reading it only needs template');
T::same('global.edit_custom_css', Permissions::forTool('set_global_css'), 'Global CSS needs the custom CSS permission, not just Theme Options');
T::same('element-library', Permissions::forTool('render_preview'), 'rendering elements needs the element library');

// Tools WordPress alone governs --------------------------------------------

foreach (['list_menus', 'create_menu', 'update_menu', 'upload_media', 'get_site_info', 'clear_cache', 'get_platform_baseline'] as $ungated) {
    T::same(null, Permissions::forTool($ungated), sprintf('"%s" is not gated by Cornerstone', $ungated));
}

T::same(null, Permissions::forTool('not_a_tool'), 'an unknown tool is not gated');

// Without Cornerstone, nothing is refused -----------------------------------

$permissions = new Permissions();
T::same(null, $permissions->userCan(''), 'an empty key asks nothing');
T::same(null, $permissions->denialFor('list_menus'), 'an ungated tool is never denied');

// Every registered tool is placed on purpose ----------------------------------

$server = new ProExtended\Mcp\Server(new ProExtended\Elements\SchemaExtractor(), new ProExtended\Layouts\LayoutService());
$registered = array_keys($server->getTools());

foreach (array_keys($server->getRegistrationErrors()) as $name) {
    if (! str_contains($name, '://')) {
        $registered[] = $name;
    }
}

sort($registered);

foreach ($registered as $tool) {
    T::ok(
        isset(Permissions::TOOL_KEYS[$tool]) || isset(Permissions::EXEMPT[$tool]),
        sprintf('"%s" is either gated by a Cornerstone permission or exempt on purpose', $tool)
    );
}

T::same([], array_values(array_intersect(array_keys(Permissions::TOOL_KEYS), array_keys(Permissions::EXEMPT))), 'no tool is both gated and exempt');
T::same([], array_values(array_diff(array_keys(Permissions::TOOL_KEYS), $registered)), 'every gated tool is registered');
T::same([], array_values(array_diff(array_keys(Permissions::EXEMPT), $registered)), 'every exempt tool is registered');

foreach (Permissions::EXEMPT as $tool => $reason) {
    T::ok(trim($reason) !== '', sprintf('"%s" says why it is exempt', $tool));
    T::same(null, Permissions::forTool($tool), sprintf('an exempt tool ("%s") is never gated', $tool));
}
