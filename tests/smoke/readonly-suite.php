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

S::check(count($tools) === 25, 'lists 25 tools', (string) count($tools));
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

    $outline = (array) ($summary['data']['outline'] ?? []);
    $leaf = null;

    foreach (array_reverse($outline) as $node) {
        if (($node['children'] ?? 1) === 0) {
            $leaf = (string) $node['path'];
            break;
        }
    }

    if ($leaf !== null) {
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

    if ($exported !== null) {
        $component = S::call('get_layout', ['post_id' => $componentDocId, 'path' => $exported, 'summary' => true]);
        S::check(! $component['is_error'] && $component['bytes'] < 20000, "an outline of component root {$exported} is under 20 KB", $component['bytes'] . ' bytes');
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

// Nothing changed ------------------------------------------------------------------------

S::section('nothing was written');

foreach ($watched as $option) {
    S::check(S::rawOption($option) === $optionsBefore[$option], "{$option} is unchanged");
}

if ($componentDocId > 0) {
    S::check(S::postField($componentDocId, 'post_content') === $docBefore, "component document #{$componentDocId} is unchanged");
}

S::summary();
