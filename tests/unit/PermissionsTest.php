<?php

declare(strict_types=1);

use ProExtended\Cornerstone\Permissions;

T::group('Permissions');

// Every gated tool names a key Cornerstone actually has --------------------

$known = [
    'content.page', 'layout', 'component', 'template', 'template.manage_library',
    'element-library', 'global.colors', 'global.fonts', 'global.theme_options', 'global.variables',
];

foreach (Permissions::TOOL_KEYS as $tool => $key) {
    T::ok(in_array($key, $known, true), sprintf('"%s" is gated on a real Cornerstone permission (%s)', $tool, $key));
}

T::same('layout', Permissions::forTool('update_layout'), 'a writing tool is gated on layout');
T::same('global.variables', Permissions::forTool('set_variables'), 'variables have their own permission');
T::same('template.manage_library', Permissions::forTool('import_tco'), 'writing to the library needs the library permission');
T::same('template', Permissions::forTool('list_templates'), 'reading it only needs template');

// Tools WordPress alone governs --------------------------------------------

foreach (['list_menus', 'create_menu', 'update_menu', 'upload_media', 'get_site_info', 'clear_cache', 'get_platform_baseline'] as $ungated) {
    T::same(null, Permissions::forTool($ungated), sprintf('"%s" is not gated by Cornerstone', $ungated));
}

T::same(null, Permissions::forTool('not_a_tool'), 'an unknown tool is not gated');

// Without Cornerstone, nothing is refused -----------------------------------

$permissions = new Permissions();
T::same(null, $permissions->userCan(''), 'an empty key asks nothing');
T::same(null, $permissions->denialFor('list_menus'), 'an ungated tool is never denied');
