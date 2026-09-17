<?php

declare(strict_types=1);

/**
 * Smoke tests for sites where only read-only calls and dry runs are allowed.
 *
 *     wp eval-file --use-include --user=<admin ID> readonly-suite.php [<component document ID>]
 *
 * Without an ID the largest component document is used. Nothing is written:
 * the palette, fonts, font config and Global CSS options, and the component
 * document, are compared before and after.
 */

require __DIR__ . '/lib.php';

use PeSmoke as S;

echo 'Pro Extended smoke tests (read-only suite), PE ' . PE_VERSION . "\n";

$gateway = pro_extended()->documentGateway();
$watched = ['cornerstone_color_items', 'cornerstone_font_items', 'cornerstone_font_config', $gateway->globalCssKey(), 'pe_settings_backups'];
$optionsBefore = [];

foreach ($watched as $option) {
    $optionsBefore[$option] = S::rawOption($option);
}

$componentDocId = (int) ($args[0] ?? 0);

if ($componentDocId <= 0) {
    global $wpdb;
    $componentDocId = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'cs_global_block' AND post_status = 'tco-data' ORDER BY LENGTH(post_content) DESC, ID ASC LIMIT 1"
    );
}

$docBefore = $componentDocId > 0 ? S::postField($componentDocId, 'post_content') : null;

// 1. Tool list and protocol --------------------------------------------------

S::section('1 tools/list');

$tools = [];

foreach ((array) (S::rpc('tools/list')['result']['tools'] ?? []) as $tool) {
    $tools[$tool['name']] = $tool;
}

S::check(count($tools) === 26, 'lists 26 tools', (string) count($tools));
S::check(isset($tools['get_theme_options']) && ($tools['get_theme_options']['annotations']['readOnlyHint'] ?? null) === true, 'get_theme_options is listed as read-only');
S::check(array_filter($tools, static fn($t) => ! isset($t['annotations']['readOnlyHint'], $t['annotations']['title'], $t['title'])) === [], 'every tool has annotations and a title');

$init = S::rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'pe-smoke', 'version' => '1']]);
S::check(($init['result']['serverInfo']['version'] ?? null) === '1.1.0' && ($init['result']['protocolVersion'] ?? null) === '2025-03-26', 'initialize reports 1.1.0 and protocol 2025-03-26');
S::check(pro_extended()->mcpServer()->getRegistrationErrors() === [], 'no tool failed to register');

// 19. Site info and listings -------------------------------------------------

S::section('19 site info and listings');

$info = S::ok(S::call('get_site_info'), 'get_site_info');
$health = (array) ($info['health'] ?? []);
S::check(($info['pro_extended']['version'] ?? null) === '1.1.0', 'reports 1.1.0');
S::check(isset($health['permalinks'], $health['cornerstone_adapter'], $health['component_registry'], $health['host_cache'], $health['settings']), 'returns the health block', (string) wp_json_encode(array_keys($health)));
S::check(($health['cornerstone_available'] ?? null) === true, 'Cornerstone is available');
S::check(($health['permalinks']['pretty'] ?? null) === true && ($health['application_passwords_in_use'] ?? null) === true, 'pretty permalinks and application passwords are in use');
S::check(($health['current_user']['unfiltered_html'] ?? null) === true, 'the test user has unfiltered_html');
echo '      component registry: ' . wp_json_encode($health['component_registry'] ?? null) . "\n";
echo '      adapter: ' . wp_json_encode($health['cornerstone_adapter'] ?? null) . "\n";

$components = S::ok(S::call('list_components'), 'list_components');
S::check(($components['source'] ?? null) === 'cornerstone' && ($components['count'] ?? -1) === count((array) ($components['components'] ?? [])), 'the builder\'s registry is listed', 'source ' . ($components['source'] ?? 'null') . ', ' . ($components['count'] ?? 'null') . ' components');
S::check(is_array($components['errors'] ?? null), 'registry errors are returned', (string) wp_json_encode($components['errors'] ?? null));

if (($components['errors'] ?? []) !== []) {
    S::attention('The component registry reports errors: ' . implode(' ', (array) $components['errors']));
}

$firstComponent = $components['components'][0] ?? null;

if (is_array($firstComponent)) {
    $keys = ['component_id', 'label', 'element_type', 'document_id', 'document_title', 'library_group', 'document_visibility', 'prefab', 'accepts_children', 'slots', 'parameter_groups'];
    S::check(array_diff($keys, array_keys($firstComponent)) === [], 'each component has the documented fields', implode(', ', array_diff($keys, array_keys($firstComponent))));
    $one = S::ok(S::call('list_components', ['search' => (string) $firstComponent['component_id'], 'include_parameters' => true]), 'list_components search with parameters');
    S::check(($one['components'][0]['component_id'] ?? null) === $firstComponent['component_id'] && array_key_exists('parameters', (array) ($one['components'][0] ?? [])), 'search finds the component and adds its parameters');
}

$layouts = S::ok(S::call('list_layouts', ['type' => 'cs_global_block']), 'list_layouts for component documents');

foreach ((array) ($layouts['layouts'] ?? []) as $row) {
    S::check(
        isset($row['doc_type'], $row['format']) && array_key_exists('library_group', $row) && array_key_exists('document_visibility', $row) && is_int($row['component_count'] ?? null),
        "list_layouts row #{$row['id']} has the component fields",
        (string) wp_json_encode($row)
    );
}

$all = S::ok(S::call('list_layouts'), 'list_layouts');
S::check(array_filter((array) ($all['layouts'] ?? []), static fn($row): bool => ! array_key_exists('doc_type', (array) $row)) === [], 'every row has a doc_type');

$menus = S::ok(S::call('list_menus'), 'list_menus');
S::check(is_array($menus['menus'] ?? null), 'list_menus returns an array', (string) ($menus['count'] ?? ''));

$backups = S::ok(S::call('list_settings_backups'), 'list_settings_backups');
S::check(is_array($backups['backups'] ?? null), 'list_settings_backups returns a list');

$fonts = S::ok(S::call('list_fonts'), 'list_fonts');
$storedConfig = S::jsonOption('cornerstone_font_config');
S::check(
    ! is_array($storedConfig) || $storedConfig === [] || S::canon($fonts['config'] ?? null) === S::canon($storedConfig),
    'list_fonts reports the stored font config (defect 8)',
    'stored keys: ' . implode(', ', array_keys((array) $storedConfig))
);

S::ok(S::call('list_colors'), 'list_colors');
S::ok(S::call('list_elements'), 'list_elements');

// 10. get_layout size ----------------------------------------------------------

S::section('10 get_layout size');

if ($componentDocId <= 0) {
    S::skip('get_layout size', 'this site has no component documents');
} else {
    echo "      component document #{$componentDocId}, " . strlen((string) $docBefore) . " bytes stored\n";

    $full = S::call('get_layout', ['post_id' => $componentDocId]);
    S::check(! $full['is_error'], 'get_layout without options still returns the whole document', $full['bytes'] . ' bytes');

    $summary = S::call('get_layout', ['post_id' => $componentDocId, 'summary' => true]);
    S::check(! $summary['is_error'] && $summary['bytes'] < 20000, 'get_layout with summary is under 20 KB', $summary['bytes'] . ' bytes');
    S::check(! array_key_exists('data', (array) $summary['data']) && is_array($summary['data']['outline'] ?? null), 'the summary has an outline and no data');
    echo "      summary: {$summary['bytes']} bytes, " . count((array) ($summary['data']['outline'] ?? [])) . ' nodes, ' . ($summary['data']['element_count'] ?? '?') . " elements, truncated: " . wp_json_encode($summary['data']['truncated'] ?? null) . "\n";

    $shallow = S::call('get_layout', ['post_id' => $componentDocId, 'summary' => true, 'max_depth' => 2]);
    S::check(! $shallow['is_error'] && $shallow['bytes'] <= $summary['bytes'], 'a smaller max_depth returns a smaller outline', $shallow['bytes'] . ' bytes');

    // Find a leaf element and a component root anywhere in the document (a
    // full-depth outline can be large; it is only used to pick paths).
    $deep = S::call('get_layout', ['post_id' => $componentDocId, 'summary' => true, 'max_depth' => 50]);
    $outline = (array) ($deep['data']['outline'] ?? []);
    S::check(! $deep['is_error'] && ($deep['data']['truncated'] ?? null) === false, 'a full-depth outline covers the whole document', $deep['bytes'] . ' bytes');
    $leaf = null;

    foreach (array_reverse($outline) as $node) {
        if (($node['children'] ?? 1) === 0) {
            $leaf = (string) $node['path'];
            break;
        }
    }

    if ($leaf === null) {
        S::check(false, 'the outline has a leaf element to fetch by path');
    } else {
        $subtree = S::call('get_layout', ['post_id' => $componentDocId, 'path' => $leaf]);
        S::check(! $subtree['is_error'] && $subtree['bytes'] < 20000, "get_layout with path {$leaf} is under 20 KB", $subtree['bytes'] . ' bytes');
        S::check(($subtree['data']['data']['root'] ?? null) === $leaf, 'the path returns that element');
    }

    $exported = null;

    foreach ($outline as $node) {
        if (isset($node['_c_id'])) {
            $exported = (string) $node['path'];
            break;
        }
    }

    if ($exported === null) {
        S::skip('component root outline', 'the document exports no components');
    } else {
        $component = S::call('get_layout', ['post_id' => $componentDocId, 'path' => $exported, 'summary' => true]);
        S::check(! $component['is_error'] && $component['bytes'] < 20000, "an outline of component root {$exported} is under 20 KB", $component['bytes'] . ' bytes');
        S::check(($component['data']['outline'][0]['path'] ?? null) === $exported, 'the outline starts at that component root');
        echo "      component root {$exported}: outline {$component['bytes']} bytes, " . count((array) ($component['data']['outline'] ?? [])) . " nodes\n";
    }

    S::isError(S::call('get_layout', ['post_id' => $componentDocId, 'path' => 'no.such.path']), 'an unknown path is an error', 'not found');
}

// 5, 6. Palette and font dry runs --------------------------------------------------

S::section('5 set_colors dry runs');

$colors = S::jsonOption('cornerstone_color_items') ?? [];
$lockedColor = null;

foreach ($colors as $item) {
    if (is_array($item) && ! empty($item['locked']) && ! array_key_exists('children', $item) && isset($item['_id'], $item['value'])) {
        $lockedColor = $item;
        break;
    }
}

if ($lockedColor === null) {
    S::skip('locked palette entries', 'this site has no locked colors');
} else {
    $change = [['_id' => (string) $lockedColor['_id'], 'value' => $lockedColor['value'] === '#000000' ? '#000001' : '#000000']];
    S::isError(S::call('set_colors', ['colors' => $change, 'dry_run' => true]), "changing locked color {$lockedColor['_id']} without allow_locked is an error", 'locked');
    $dry = S::ok(S::call('set_colors', ['colors' => $change, 'allow_locked' => true, 'dry_run' => true]), 'the same change with allow_locked');
    S::check(
        ($dry['dry_run'] ?? null) === true && ($dry['updated'][0]['_id'] ?? null) === $lockedColor['_id']
            && ($dry['updated'][0]['before']['value'] ?? null) === $lockedColor['value']
            && ($dry['updated'][0]['after']['locked'] ?? null) === true
            && S::isNull($dry, 'backup_id'),
        'it returns a diff that keeps the lock, and writes nothing',
        (string) wp_json_encode($dry)
    );
}

$dryNew = S::ok(S::call('set_colors', ['colors' => [['_id' => 'peTestA', 'title' => 'PE Test A', 'value' => '#123456']], 'group' => ['_id' => 'peTestGroup', 'title' => 'PE Test Group'], 'dry_run' => true]), 'dry run of a new color and group');
S::check(($dryNew['added'] ?? null) === ['peTestA'] || ($dryNew['unchanged'] ?? 0) === 1, 'the dry run reports the addition', (string) wp_json_encode($dryNew));

$firstColor = null;

foreach ($colors as $item) {
    if (is_array($item) && isset($item['_id']) && ! array_key_exists('children', $item)) {
        $firstColor = (string) $item['_id'];
        break;
    }
}

if ($firstColor === null) {
    S::skip('palette removal dry run', 'this site has no palette colors');
} else {
    $dryRemove = S::ok(S::call('set_colors', ['remove' => [$firstColor], 'allow_locked' => true, 'dry_run' => true]), "dry run of removing palette color {$firstColor}");
    $use = (array) ($dryRemove['uses'][$firstColor] ?? []);
    S::check(($dryRemove['dry_run'] ?? null) === true && ($dryRemove['removed'] ?? null) === [$firstColor] && is_int($use['count'] ?? null) && S::isNull($dryRemove, 'backup_id'), 'the dry run lists the uses and writes nothing', (string) wp_json_encode(array_diff_key($dryRemove, ['uses' => 1])));
    echo "      {$firstColor} is used " . ($use['count'] ?? '?') . ' times in ' . count((array) ($use['locations'] ?? [])) . ' listed places' . "\n";
}

S::section('6 set_fonts dry runs');

$fontItems = S::jsonOption('cornerstone_font_items') ?? [];
$lockedFont = null;

foreach ($fontItems as $item) {
    if (is_array($item) && ! empty($item['locked']) && ! array_key_exists('children', $item) && isset($item['_id'], $item['title'])) {
        $lockedFont = $item;
        break;
    }
}

if ($lockedFont === null) {
    S::skip('locked font entries', 'this site has no locked fonts');
} else {
    $change = [['_id' => (string) $lockedFont['_id'], 'title' => $lockedFont['title'] . ' (PE TEST)']];
    S::isError(S::call('set_fonts', ['fonts' => $change, 'dry_run' => true]), "changing locked font {$lockedFont['_id']} without allow_locked is an error", 'locked');
    $dry = S::ok(S::call('set_fonts', ['fonts' => $change, 'allow_locked' => true, 'dry_run' => true]), 'the same change with allow_locked');
    S::check(
        ($dry['dry_run'] ?? null) === true && ($dry['updated'][0]['_id'] ?? null) === $lockedFont['_id']
            && ($dry['updated'][0]['after']['title'] ?? null) === $lockedFont['title'] . ' (PE TEST)'
            && ($dry['updated'][0]['after']['locked'] ?? null) === true
            && ($dry['backup_ids'] ?? null) === [],
        'it returns a diff that keeps the lock, and writes nothing',
        (string) wp_json_encode($dry)
    );
}

$fontIds = array_values(array_map('strval', array_column(array_filter($fontItems, static fn($i): bool => is_array($i) && isset($i['_id']) && ! array_key_exists('children', $i)), '_id')));

if (count($fontIds) < 2) {
    S::skip('font removal dry run', 'this site has fewer than two fonts');
} else {
    $dryFontRemove = S::ok(S::call('set_fonts', ['remove' => [$fontIds[0]], 'allow_locked' => true, 'dry_run' => true]), "dry run of removing font {$fontIds[0]}");
    $fontUse = (array) ($dryFontRemove['uses'][$fontIds[0]] ?? []);
    S::check(($dryFontRemove['dry_run'] ?? null) === true && ($dryFontRemove['removed'] ?? null) === [$fontIds[0]] && is_int($fontUse['count'] ?? null) && ($dryFontRemove['backup_ids'] ?? null) === [], 'the dry run lists the uses and writes nothing', (string) wp_json_encode(array_diff_key($dryFontRemove, ['uses' => 1])));
    echo "      {$fontIds[0]} is used " . ($fontUse['count'] ?? '?') . ' times' . "\n";
    S::isError(S::call('set_fonts', ['remove' => $fontIds, 'allow_locked' => true, 'force' => true, 'dry_run' => true]), 'removing every font is refused', 'At least one font');
}

$dryConfig = S::ok(S::call('set_fonts', ['config' => ['fontDisplay' => 'swap'], 'dry_run' => true]), 'dry run of a config change');
S::check(($dryConfig['dry_run'] ?? null) === true && is_array($dryConfig['config']['changed'] ?? null), 'the dry run reports the config change', (string) wp_json_encode($dryConfig['config'] ?? null));

// 7. Global CSS ----------------------------------------------------------------------

S::section('7 Global CSS');

$css = S::ok(S::call('get_global_css'), 'get_global_css');
S::check(($css['option_key'] ?? null) === $gateway->globalCssKey() && is_int($css['bytes'] ?? null), 'it reports the option and size', (string) wp_json_encode($css));
echo '      Global CSS: ' . ($css['bytes'] ?? '?') . ' bytes, blocks: ' . wp_json_encode($css['blocks'] ?? null) . "\n";

$dryCss = S::ok(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => ".pe-test-block {\n  color: #111111;\n  background-color: #ffffff;\n}", 'dry_run' => true]), 'set_global_css dry run');
S::check(($dryCss['dry_run'] ?? null) === true && S::isNull($dryCss, 'backup_id') && str_contains((string) ($dryCss['diff'] ?? ''), 'pe:begin pe-test'), 'the dry run returns a diff and writes nothing', (string) wp_json_encode(array_diff_key($dryCss, ['diff' => 1])));

// 2. Document dry runs -------------------------------------------------------------

S::section('2 document dry runs');

$dryDoc = S::ok(S::call('create_document', ['type' => 'header', 'title' => 'PE TEST Dry run header', 'dry_run' => true, 'settings' => ['assignments' => [['group' => true, 'condition' => 'site:entire-site', 'value' => '']]]]), 'create_document dry run');
S::check(($dryDoc['dry_run'] ?? null) === true && S::isNull($dryDoc, 'document_id'), 'the dry run creates nothing', (string) wp_json_encode($dryDoc));
echo '      warnings: ' . wp_json_encode($dryDoc['warnings'] ?? null) . "\n";
S::check(get_posts(['post_type' => 'cs_header', 'post_status' => 'tco-data', 'title' => 'PE TEST Dry run header', 'fields' => 'ids', 'suppress_filters' => true]) === [], 'no header was created');

if ($componentDocId > 0) {
    $drySettings = S::ok(S::call('update_document_settings', ['document_id' => $componentDocId, 'settings' => ['library_group' => 'PE TEST dry run'], 'dry_run' => true]), 'update_document_settings dry run');
    S::check(($drySettings['updated'] ?? null) === false && isset($drySettings['changes']['library_group']) && S::isNull($drySettings, 'backup_id'), 'the dry run reports the change and writes nothing', (string) wp_json_encode($drySettings));
}

$validation = S::ok(S::call('validate_layout', ['layout_data' => '[{"_type": "section", "_modules": []}]']), 'validate_layout with a JSON string');
S::check(($validation['valid'] ?? null) === true, 'the JSON string is decoded and validated');

// 21. Site feature report -------------------------------------------------------------

S::section('21 site feature report');

$features = (array) ($info['features'] ?? []);

foreach (['content_storage', 'twig', 'external_api', 'csv', 'wpml', 'woocommerce', 'acf', 'max', 'permissions'] as $key) {
    S::check(array_key_exists($key, $features), "features has {$key}");
}

S::check(! array_key_exists('features', $health), 'features are not repeated in the health block');
S::check(($features['content_storage']['mode'] ?? null) === (get_option('cs_document_build_as_html', true) ? 'html' : 'shortcodes'), 'content_storage matches the site option', (string) wp_json_encode($features['content_storage'] ?? null));
S::check(is_bool($features['twig']['enabled'] ?? null) && is_bool($features['external_api']['enabled'] ?? null) && is_bool($features['csv']['enabled'] ?? null), 'the switches are booleans');
S::check(($features['woocommerce']['active'] ?? null) === class_exists('WooCommerce') && ($features['wpml']['active'] ?? null) === class_exists('SitePress') && ($features['acf']['active'] ?? null) === class_exists('ACF'), 'the integrations match the loaded plugins');
S::check(($features['permissions']['available'] ?? null) === true && in_array('content.page', (array) ($features['permissions']['allowed'] ?? []), true), 'the caller\'s Cornerstone permissions are reported', (string) wp_json_encode($features['permissions'] ?? null));
S::check(preg_match('/^\d+_\d+$/', (string) ($info['breakpoints']['tag'] ?? '')) === 1, 'breakpoints report their tag', (string) ($info['breakpoints']['tag'] ?? ''));

$maxJson = (string) wp_json_encode($features['max'] ?? null);
S::check(is_int($features['max']['count'] ?? null) && ! str_contains($maxJson, '://') && ! str_contains($maxJson, '"package"') && ! str_contains($maxJson, '"edge"'), 'the Max summary has no package URLs', 'count ' . ($features['max']['count'] ?? 'null'));
echo '      features: ' . wp_json_encode(['content_storage' => $features['content_storage'] ?? null, 'twig' => $features['twig']['enabled'] ?? null, 'external_api' => array_intersect_key((array) ($features['external_api'] ?? []), array_flip(['enabled', 'allowlist_empty', 'allowlist_entries', 'global_endpoints'])), 'max_active' => array_column(array_filter((array) ($features['max']['packages'] ?? []), static fn($p): bool => ! empty($p['active'])), 'slug')]) . "\n";

// Secrets never leave the site: filters stand in for the stored options, so
// nothing is written.
$fakeSecret = 'PE-TEST-SECRET-' . wp_generate_password(12, false);
$fakeFilters = [
    'pre_option_x_max_plugins'               => static fn(): array => [[
        'slug'        => 'pe-test-max',
        'plugin'      => 'pe-test-max/pe-test-max.php',
        'title'       => 'PE TEST Max',
        'new_version' => '9.9.9',
        'package'     => 'https://packages.example.invalid/pe-test.zip?key=' . $fakeSecret,
        'edge'        => ['package' => 'https://packages.example.invalid/edge.zip?key=' . $fakeSecret, 'new_version' => '10.0'],
        'purchased'   => true,
        'x-extension' => ['logo_url' => 'https://cdn.example.invalid/' . $fakeSecret . '.png'],
    ]],
    'pre_option_cs_api_extension_allowlist'  => static fn(): string => "https://api.example.invalid/{$fakeSecret}/\nhttps://other.example.invalid \r",
    'pre_option_cs_api_endpoints'            => static fn(): array => [['id' => 'pe-test', 'name' => 'PE TEST', 'endpoint' => 'https://api.example.invalid/', 'headers' => 'Authorization: Bearer ' . $fakeSecret]],
];

foreach ($fakeFilters as $hook => $callback) {
    add_filter($hook, $callback);
}

$fakeInfo = S::call('get_site_info');

foreach ($fakeFilters as $hook => $callback) {
    remove_filter($hook, $callback);
}

$fakeData = is_array($fakeInfo['data']) ? $fakeInfo['data'] : [];
S::check(! $fakeInfo['is_error'] && ! str_contains($fakeInfo['text'], $fakeSecret) && ! str_contains($fakeInfo['text'], 'example.invalid'), 'no package URL, allowlist entry or endpoint header appears in get_site_info');
$fakePackage = $fakeData['features']['max']['packages'][0] ?? [];
S::check(($fakePackage['slug'] ?? null) === 'pe-test-max' && ($fakePackage['version'] ?? null) === '9.9.9' && ($fakePackage['installed'] ?? null) === false, 'a Max package is summarized', (string) wp_json_encode($fakePackage));
S::check(($fakeData['features']['external_api']['allowlist_entries'] ?? null) === 2 && ($fakeData['features']['external_api']['entries_with_extra_spaces'] ?? null) === 1 && ($fakeData['features']['external_api']['entries_without_final_slash'] ?? null) === 1 && ($fakeData['features']['external_api']['global_endpoints'] ?? null) === 1, 'the allowlist is described, not listed', (string) wp_json_encode($fakeData['features']['external_api'] ?? null));

// 22. Theme options ------------------------------------------------------------------

S::section('22 get_theme_options');

$themeOptionsBefore = [];

foreach (['cs_option_data', 'x_stack', 'x_breakpoint_base', 'x_breakpoint_ranges'] as $option) {
    $themeOptionsBefore[$option] = S::rawOption($option);
}

$overview = S::ok(S::call('get_theme_options'), 'get_theme_options overview');
$sectionTags = array_column((array) ($overview['sections'] ?? []), 'tag');
S::check(($overview['total_keys'] ?? 0) > 50 && isset($overview['sections'], $overview['breakpoints']) && ! isset($overview['options']), 'the overview lists sections without values', 'keys ' . ($overview['total_keys'] ?? 'null'));
S::check(in_array('typography', $sectionTags, true) && in_array('layout-and-design', $sectionTags, true), 'the panel sections are found', implode(', ', $sectionTags));
S::check(($overview['breakpoints']['tag'] ?? null) === pro_extended()->elementContext()->breakpointTag() && in_array('x_layout_site_width', (array) ($overview['breakpoints']['responsive_keys'] ?? []), true), 'the breakpoint tag and responsive keys are reported', (string) wp_json_encode($overview['breakpoints'] ?? null));
echo '      overview: ' . wp_json_encode(array_intersect_key($overview, array_flip(['stack', 'total_keys', 'changed']))) . ' sections ' . wp_json_encode(array_map(static fn($s) => $s['tag'] . ':' . $s['keys'] . '/' . $s['changed'], (array) ($overview['sections'] ?? []))) . "\n";

$typography = S::ok(S::call('get_theme_options', ['section' => 'Typography']), 'read the typography section by label');
$typoRows = [];

foreach ((array) ($typography['options'] ?? []) as $row) {
    $typoRows[$row['key']] = $row;
}

S::check(($typography['section'] ?? null) === 'typography' && isset($typoRows['x_body_font_family_selection']), 'the section lists its keys', (string) count($typoRows));
$bodyFont = $typoRows['x_body_font_family_selection'] ?? [];
S::check(array_key_exists('value', $bodyFont) && array_key_exists('default', $bodyFont) && is_bool($bodyFont['changed'] ?? null) && is_string($bodyFont['label'] ?? null), 'each option has a label, value, default and changed flag', (string) wp_json_encode($bodyFont));

$named = S::ok(S::call('get_theme_options', ['keys' => ['x_layout_site_width', 'x_custom_styles', 'pe_test_no_such_key']]), 'read named keys');
$namedRows = array_column((array) ($named['options'] ?? []), null, 'key');
S::check(($namedRows['x_layout_site_width']['responsive'] ?? null) === true && array_key_exists('breakpoint_values', $namedRows['x_layout_site_width'] ?? []), 'a responsive option has breakpoint values', (string) wp_json_encode($namedRows['x_layout_site_width'] ?? null));
S::check(S::isNull((array) ($namedRows['x_custom_styles'] ?? []), 'value') && is_int($namedRows['x_custom_styles']['bytes'] ?? null), 'Global CSS is reported by size only', (string) wp_json_encode($namedRows['x_custom_styles'] ?? null));
S::check(($named['unknown_keys'] ?? null) === ['pe_test_no_such_key'], 'unknown keys are listed');

$fakeKey = 'PE-TEST-THEME-SECRET-' . wp_generate_password(10, false);
$fakeEndpoints = static fn(): array => [['name' => 'PE TEST', 'headers' => 'Authorization: ' . $fakeKey]];
add_filter('pre_option_cs_api_endpoints', $fakeEndpoints);
$searched = S::call('get_theme_options', ['search' => 'api']);
remove_filter('pre_option_cs_api_endpoints', $fakeEndpoints);
S::check(! $searched['is_error'] && ! str_contains($searched['text'], $fakeKey), 'endpoint headers are never returned');

$changed = S::ok(S::call('get_theme_options', ['changed_only' => true]), 'read changed options');
S::check(($changed['count'] ?? -1) === ($overview['changed'] ?? -2), 'changed_only returns every changed option', ($changed['count'] ?? 'null') . ' vs ' . ($overview['changed'] ?? 'null'));
S::isError(S::call('get_theme_options', ['section' => 'pe-test-no-such-section']), 'an unknown section is rejected', 'Unknown section');
S::isError(S::call('get_theme_options', ['keys' => 'x_stack']), 'keys must be a list', 'must be an object or array');

foreach ($themeOptionsBefore as $option => $before) {
    S::check(S::rawOption($option) === $before, "{$option} is unchanged by the reads");
}

// 20. Validator warning codes --------------------------------------------------------

S::section('20 validator warning codes');

$lintFixture = [[
    '_type'          => 'section',
    'show_condition' => [['condition' => 'global:pe-test-no-such-rule', 'value' => '']],
    '_modules'       => [[
        '_type'                        => 'layout-grid',
        'layout_grid_template_columns' => '1fr 1fr',
        '_modules'                     => [[
            '_type'                => 'layout-cell',
            'looper_provider'      => true,
            'looper_provider_type' => 'pe-test-no-such-provider',
            'custom_atts'          => '{not json',
            '_modules'             => [['_type' => 'text', 'text_content' => '{{dc:post:title']],
        ]],
    ]],
]];
$lintResult = S::ok(S::call('validate_layout', ['layout_data' => $lintFixture]), 'validate a layout with known problems');
$codes = array_keys((array) ($lintResult['codes'] ?? []));
S::check(($lintResult['valid'] ?? null) === true, 'warnings do not make the layout invalid');

foreach (['missing-migration-marker', 'missing-breakpoint-base', 'condition-unknown', 'looper-shape', 'custom-atts-type', 'token-syntax'] as $code) {
    S::check(in_array($code, $codes, true), "reports {$code}", implode(', ', $codes));
}

$issuePaths = [];

foreach ((array) ($lintResult['issues'] ?? []) as $issue) {
    $issuePaths[$issue['code']][] = $issue['path'];
}

S::check(in_array('0._modules.0._modules.0._modules.0', $issuePaths['token-syntax'] ?? [], true), 'issues carry update_layout paths', (string) wp_json_encode($issuePaths));
S::check(($lintResult['issue_count'] ?? 0) >= count((array) ($lintResult['issues'] ?? [])) && ($lintResult['issue_count'] ?? 0) > 0, 'issue_count is reported');
S::check((bool) array_filter((array) ($lintResult['warnings'] ?? []), static fn($w): bool => str_starts_with((string) $w, '[missing-breakpoint-base]')), 'warnings are summarized with their codes', (string) wp_json_encode($lintResult['warnings'] ?? null));

$known = S::ok(S::call('validate_layout', ['layout_data' => [[
    '_type'          => 'text',
    '_m'             => ['e' => 1],
    '_bp_base'       => pro_extended()->elementContext()->breakpointTag(),
    'show_condition' => [['group' => true, 'condition' => 'global:user-loggedin', 'value' => '', 'toggle' => true]],
    'text_content'   => "{{dc:post:title fallback='PE TEST'}}",
]]]), 'validate a well-formed element');
S::same([], (array) ($known['codes'] ?? ['missing' => 1]), 'a well-formed element has no warning codes');

if ($componentDocId > 0) {
    $stored = S::layout($componentDocId);
    $storedResult = S::ok(S::call('validate_layout', ['layout_data' => $stored, 'context' => 'flat']), "validate component document #{$componentDocId}");
    S::check(($storedResult['valid'] ?? null) === true && is_array($storedResult['issues'] ?? null), 'the stored document validates and lists its issues');
    echo '      codes: ' . wp_json_encode($storedResult['codes'] ?? null) . "\n";
}

// Nothing changed ------------------------------------------------------------------------

S::section('nothing was written');

foreach ($watched as $option) {
    S::check(S::rawOption($option) === $optionsBefore[$option], "{$option} is unchanged");
}

if ($componentDocId > 0) {
    S::check(S::postField($componentDocId, 'post_content') === $docBefore, "component document #{$componentDocId} is unchanged");
}

S::summary();
