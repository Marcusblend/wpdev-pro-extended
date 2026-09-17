<?php

declare(strict_types=1);

/**
 * Smoke tests for the site that allows writes.
 *
 *     wp eval-file --use-include --user=<admin ID> write-suite.php
 *
 * Everything this creates is titled "PE TEST ..." and left in place (listed at
 * the end). Palette, font and Global CSS test writes use peTest* IDs and a
 * pe-test block, and each is restored from its pre-test backup and checked
 * byte for byte.
 */

require __DIR__ . '/lib.php';

use PeSmoke as S;

$run = substr(md5(uniqid('', true)), 0, 6);
$server = pro_extended()->mcpServer();
$layouts = pro_extended()->layoutService();

echo "Pro Extended smoke tests (write suite), run {$run}, PE " . PE_VERSION . "\n";

$forceFallback = static fn(): bool => true;

// Options: image_url=<https URL> for upload_media; local=1 on a disposable
// local site only (enables checks that change how Cornerstone stores pages
// for the rest of the request).
$suiteOptions = [];

foreach ((array) ($args ?? []) as $arg) {
    if (is_string($arg) && str_contains($arg, '=')) {
        [$optionKey, $optionValue] = explode('=', $arg, 2);
        $suiteOptions[$optionKey] = $optionValue;
    }
}

$localSite = ($suiteOptions['local'] ?? '') === '1' && wp_get_environment_type() === 'local';

// Fixtures -------------------------------------------------------------------

$text = static fn(string $region, string $content): array => ['_type' => 'text', '_region' => $region, 'text_content' => $content];

$layoutFixture = static function (string $type, string $marker, array $settings = []) use ($text): array {
    $fixture = match ($type) {
        'header' => [
            'settings' => ['customCSS' => '', 'customJS' => '', 'assignments' => [], 'assignment_priority' => 0, 'multi_region' => false],
            'regions'  => [
                'top'    => [['_type' => 'bar', '_region' => 'top', '_modules' => [['_type' => 'container', '_region' => 'top', '_modules' => [$text('top', $marker)]]]]],
                'right'  => [],
                'bottom' => [],
                'left'   => [],
            ],
        ],
        'footer' => [
            'settings' => ['customCSS' => '', 'customJS' => '', 'assignments' => [], 'assignment_priority' => 0],
            'regions'  => [
                'footer' => [['_type' => 'bar', '_region' => 'footer', '_modules' => [['_type' => 'container', '_region' => 'footer', '_modules' => [$text('footer', $marker)]]]]],
            ],
        ],
        default => [
            'settings' => ['customCSS' => '', 'customJS' => '', 'assignments' => [], 'assignment_priority' => 0, 'header_enabled' => true, 'footer_enabled' => true],
            'regions'  => [
                'layout' => [['_type' => 'section', '_region' => 'layout', '_label' => 'PE Test Section', '_modules' => [$text('layout', $marker)]]],
            ],
        ],
    };

    $fixture['settings'] = array_merge($fixture['settings'], $settings);

    return $fixture;
};

$componentFixture = static function (string $componentId, string $label, array $extraElements = []): array {
    $elements = [
        'e0' => ['_id' => 'e0', '_type' => 'root', '_modules' => ['e1']],
        'e1' => ['_id' => 'e1', '_type' => 'region', '_region' => 'content', '_modules' => ['e2'], '_parent' => 'e0'],
        'e2' => [
            '_id'       => 'e2',
            '_type'     => 'layout-div',
            '_region'   => 'content',
            '_label'    => $label,
            '_c_export' => true,
            '_c_id'     => $componentId,
            '_p_json'   => '{"peTestTitle":"text|Hello"}',
            '_modules'  => ['e3'],
            '_parent'   => 'e1',
        ],
        'e3' => ['_id' => 'e3', '_type' => 'text', '_region' => 'content', 'text_content' => '{{dc:p:peTestTitle}}', '_modules' => [], '_parent' => 'e2'],
    ];

    foreach ($extraElements as $id => $element) {
        $elements[$id] = $element;
        $elements['e1']['_modules'][] = $id;
    }

    return [
        'elements' => $elements,
        'settings' => ['customCSS' => '', 'customJS' => '', 'library_group' => 'PE Test', 'document_visibility' => ''],
    ];
};

// A second exported component: one element, no parameters.
$noteComponent = static fn(string $componentId): array => [
    '_id'          => 'e4',
    '_type'        => 'text',
    '_region'      => 'content',
    '_label'       => 'PE Test Note',
    '_c_export'    => true,
    '_c_id'        => $componentId,
    'text_content' => 'PE TEST note',
    '_modules'     => [],
    '_parent'      => 'e1',
];

$pageLayout = static fn(array $children): array => [[
    '_type'    => 'section',
    '_label'   => 'PE Test Section',
    '_modules' => [[
        '_type'    => 'layout-row',
        '_modules' => [[
            '_type'    => 'layout-column',
            '_modules' => $children,
        ]],
    ]],
]];

$simplePage = $pageLayout([['_type' => 'text', 'text_content' => 'PE TEST page']]);

$pageWithComponent = static fn(string $componentId, array $pData = ['peTestTitle' => 'Hi']): array => $pageLayout([
    ['_type' => 'component', 'component_id' => $componentId, '_p_data' => $pData, '_modules' => []],
]);

// Sparse _bp_data: always a validation error.
$invalidPage = [['_type' => 'section', '_bp_data' => ['section_padding' => ['1' => '10px']], '_modules' => []]];

$rawHtml = '<iframe src="https://example.com/pe-test-embed" title="PE TEST embed" width="300" height="200"></iframe><script>window.peSmokeTest = 1;</script>';

$layoutExtra = [
    'header' => ['customCSS', 'customJS', 'assignments', 'layout_type', 'assignment_priority', 'general_featured_image', 'multi_region'],
    'footer' => ['customCSS', 'customJS', 'assignments', 'layout_type', 'assignment_priority', 'general_featured_image'],
    'theme'  => ['customCSS', 'customJS', 'assignments', 'layout_type', 'assignment_priority', 'header_enabled', 'footer_enabled'],
];
$componentExtra = ['customCSS', 'customJS', 'library_group', 'document_visibility', 'general_post_title', 'general_post_name', 'customScripts', 'customStyles'];

// 1. Tool list -----------------------------------------------------------------

S::section('1 tools/list');

$baseline = [
    'list_elements'      => [['group' => 'string'], []],
    'get_element_schema' => [['element_type' => 'string'], ['element_type']],
    'list_layouts'       => [['type' => 'string'], []],
    'get_layout'         => [['post_id' => 'integer'], ['post_id']],
    'validate_layout'    => [['layout_data' => ['array', 'object'], 'context' => 'string'], ['layout_data']],
    'list_colors'        => [[], []],
    'list_fonts'         => [[], []],
    'get_site_info'      => [[], []],
    'create_page'        => [['title' => 'string', 'slug' => 'string', 'status' => 'string', 'layout_data' => ['array', 'null']], ['title']],
    'deploy_layout'      => [['post_id' => 'integer', 'layout_data' => ['array', 'object'], 'skip_validation' => 'boolean', 'skip_backup' => 'boolean'], ['post_id', 'layout_data']],
    'backup_layout'      => [['post_id' => 'integer'], ['post_id']],
    'restore_layout'     => [['post_id' => 'integer', 'backup_id' => 'string'], ['post_id']],
    'clear_cache'        => [['post_id' => 'integer'], []],
    'update_layout'      => [['post_id' => 'integer', 'operations' => 'array', 'skip_validation' => 'boolean'], ['post_id', 'operations']],
];
$newTools = ['create_document', 'update_document_settings', 'list_components', 'get_global_css', 'set_global_css', 'set_colors', 'set_fonts', 'upload_media', 'list_menus', 'list_settings_backups', 'restore_settings', 'get_theme_options'];

$list = S::rpc('tools/list');
$tools = [];

foreach ((array) ($list['result']['tools'] ?? []) as $tool) {
    $tools[$tool['name']] = $tool;
}

S::check(count($tools) === 26, 'lists 26 tools', (string) count($tools));
S::check(array_diff(array_merge(array_keys($baseline), $newTools), array_keys($tools)) === [], 'lists the 14 original and 12 newer tools', implode(', ', array_diff(array_merge(array_keys($baseline), $newTools), array_keys($tools))));
S::check(array_filter($tools, static fn($t) => ! isset($t['annotations']['readOnlyHint'], $t['annotations']['title'], $t['title'])) === [], 'every tool has annotations and a title');
S::check(($tools['upload_media']['annotations']['openWorldHint'] ?? null) === true, 'upload_media is open-world');
S::check(count(array_filter($tools, static fn($t) => ($t['annotations']['openWorldHint'] ?? null) === true)) === 1, 'no other tool is open-world');

foreach ($baseline as $name => [$properties, $required]) {
    $schema = $tools[$name]['inputSchema'] ?? [];
    $problems = [];

    foreach ($properties as $property => $type) {
        if (! isset($schema['properties'][$property])) {
            $problems[] = "missing {$property}";
        } elseif (($schema['properties'][$property]['type'] ?? null) !== $type) {
            $problems[] = "type of {$property} changed";
        }
    }

    if (($schema['required'] ?? []) !== $required) {
        $problems[] = 'required changed';
    }

    S::check($problems === [], "{$name} keeps its 1.0.4 inputs", implode('; ', $problems));
}

$init = S::rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'pe-smoke', 'version' => '1']]);
S::check(($init['result']['protocolVersion'] ?? null) === '2025-03-26', 'protocol version unchanged');
S::check(str_contains((string) ($init['result']['instructions'] ?? ''), 'global-color:'), 'initialize returns instructions');
S::check(($init['result']['serverInfo']['version'] ?? null) === '1.1.0', 'server reports 1.1.0');
S::check($server->getRegistrationErrors() === [], 'no tool failed to register', wp_json_encode($server->getRegistrationErrors()));

// 18. Storage detection --------------------------------------------------------

S::section('18 storage detection');

foreach (['cs_layout', 'cs_layout_single_wc', 'cs_layout_archive_wc', 'cs_header', 'cs_global_block'] as $postType) {
    $post = new WP_Post((object) ['ID' => 0, 'post_type' => $postType]);
    S::check($layouts->detectSource($post) === 'post_content', "{$postType} is stored in post_content");
}

S::check($layouts->detectSource(new WP_Post((object) ['ID' => 0, 'post_type' => 'page'])) === 'post_meta', 'page is stored in meta');

// 13. Error reporting ----------------------------------------------------------

S::section('13 error reporting');

S::isError(S::call('get_layout', ['post_id' => 987654321]), 'an exception inside a tool is an isError result', 'does not exist');
$unknown = S::call('no_such_tool');
S::check($unknown['rpc_error'] !== null && ($unknown['rpc_error']['code'] ?? 0) === -32601, 'an unknown tool is a JSON-RPC error');
$missing = S::rpc('tools/call', ['arguments' => []]);
S::check(isset($missing['error']), 'a missing tool name is a JSON-RPC error');

$noManage = static function (array $caps): array {
    $caps['manage_options'] = false;
    return $caps;
};
add_filter('user_has_cap', $noManage, 999);
S::isError(S::call('list_components', ['refresh' => true]), 'refresh without manage_options is an isError result', 'manage_options');
$denied = S::call('deploy_layout', ['post_id' => 987654321, 'layout_data' => []]);
S::check($denied['rpc_error'] !== null, 'a tool the user may not run is still a JSON-RPC error');
remove_filter('user_has_cap', $noManage, 999);
S::isError(S::call('create_document', ['type' => 'header', 'title' => 'PE TEST x', 'dry_run' => true, 'bogus' => 1]), 'unknown arguments are rejected', 'bogus');
S::isError(S::call('create_document', ['type' => 'sidebar', 'title' => 'PE TEST x', 'dry_run' => true]), 'unknown enum values are rejected', 'sidebar');

// 12. skip_validation lock -----------------------------------------------------

S::section('12 skip_validation lock');

$lockPage = S::ok(S::call('create_page', ['title' => "PE TEST Lock page {$run}", 'layout_data' => $simplePage]), 'create a PE TEST page');
$lockPageId = (int) ($lockPage['post_id'] ?? 0);

if ($lockPageId > 0) {
    S::track('page', $lockPageId, "PE TEST Lock page {$run}");
    $checksum = S::call('get_layout', ['post_id' => $lockPageId])['data']['checksum'] ?? null;

    // The only call in these suites that passes skip_validation, and only
    // while the lock filter is on (brief §3 rule 4).
    $locked = static fn(): string => '0';
    add_filter('pe_allow_skip_validation', $locked);

    try {
        S::isError(
            S::call('deploy_layout', ['post_id' => $lockPageId, 'layout_data' => $simplePage, 'skip_validation' => true]),
            'deploy_layout with skip_validation is refused while the lock is on',
            'skip_validation is disabled'
        );
    } finally {
        remove_filter('pe_allow_skip_validation', $locked);
    }

    S::check((S::call('get_layout', ['post_id' => $lockPageId])['data']['checksum'] ?? null) === $checksum, 'the refused deploy wrote nothing');
}

// 11. JSON-string input ------------------------------------------------------

S::section('11 JSON-string input');

$pageJson = (string) wp_json_encode($simplePage);
$validation = S::ok(S::call('validate_layout', ['layout_data' => $pageJson]), 'validate_layout accepts a JSON string');
S::check(($validation['valid'] ?? null) === true, 'the decoded layout is valid', wp_json_encode($validation));

if ($lockPageId > 0) {
    $deployed = S::ok(S::call('deploy_layout', ['post_id' => $lockPageId, 'layout_data' => $pageJson]), 'deploy_layout accepts a JSON string');
    S::check(($deployed['deployed'] ?? null) === true, 'the JSON-string layout was deployed', wp_json_encode($deployed));
    S::same($simplePage, S::layout($lockPageId), 'the page holds the decoded layout');
}

$jsonPage = S::ok(S::call('create_page', ['title' => "PE TEST JSON page {$run}", 'layout_data' => $pageJson]), 'create_page accepts a JSON string');

if (isset($jsonPage['post_id'])) {
    S::track('page', (int) $jsonPage['post_id'], "PE TEST JSON page {$run}");
    S::same(S::stamped($simplePage), S::layout((int) $jsonPage['post_id']), 'create_page stored the decoded layout, not an empty page');
}

$jsonFooterData = $layoutFixture('footer', "PE-TEST-JSON-{$run}");
$jsonFooter = S::ok(S::call('create_document', [
    'type'        => 'footer',
    'title'       => "PE TEST JSON footer {$run}",
    'settings'    => '{"assignment_priority": 0}',
    'layout_data' => (string) wp_json_encode($jsonFooterData),
]), 'create_document accepts JSON strings');

if (isset($jsonFooter['document_id'])) {
    S::track('document', (int) $jsonFooter['document_id'], "PE TEST JSON footer {$run}");
    S::same(S::stamped($jsonFooterData['regions'], 'regions'), S::layout((int) $jsonFooter['document_id'])['regions'] ?? null, 'create_document stored the decoded regions');
}

S::isError(S::call('validate_layout', ['layout_data' => '[{"_type": "section",']), 'validate_layout: an undecodable string is an error', 'could not be decoded');
S::isError(S::call('deploy_layout', ['post_id' => ($lockPageId ?: 987654321), 'layout_data' => '{nope']), 'deploy_layout: an undecodable string is an error', 'could not be decoded');
S::isError(S::call('create_page', ['title' => "PE TEST Bad JSON {$run}", 'layout_data' => 'not json']), 'create_page: a non-JSON string is an error', 'object or array');
S::isError(S::call('create_document', ['type' => 'footer', 'title' => "PE TEST Bad JSON {$run}", 'layout_data' => '"text"', 'dry_run' => true]), 'create_document: a string that is not an object is an error', 'object or array');
S::check(get_posts(['post_type' => 'page', 'title' => "PE TEST Bad JSON {$run}", 'post_status' => 'any', 'fields' => 'ids']) === [], 'no page was created for the bad JSON string');
S::isError(S::call('update_layout', ['post_id' => ($lockPageId ?: 987654321), 'operations' => '[{"op": "update"']), 'update_layout: undecodable operations are an error', 'could not be decoded');

// 2, 3, 4, 16. Documents, components, registry refresh, full replace ----------
// Each runs twice: through Cornerstone's API, then with pe_force_fallback on.

$gateway = pro_extended()->documentGateway();
$types = array_values((array) ($tools['create_document']['inputSchema']['properties']['type']['enum'] ?? []));
$typeLabels = [
    'header'            => 'Header',
    'footer'            => 'Footer',
    'component'         => 'Component',
    'layout_single'     => 'Single Layout',
    'layout_archive'    => 'Archive Layout',
    'layout_single_wc'  => 'Shop Single Layout',
    'layout_archive_wc' => 'Shop Archive Layout',
];
$entireSite = [['group' => true, 'condition' => 'site:entire-site', 'value' => '']];
$warningsOf = static fn(array $result): string => implode(' ', array_map('strval', (array) ($result['warnings'] ?? [])));

foreach (['api', 'fallback'] as $mode) {
    $expectedPath = $mode === 'api' ? 'cornerstone-api' : 'fallback';

    if ($mode === 'fallback') {
        add_filter('pe_force_fallback', $forceFallback);
    }

    try {
        // 2. create_document -----------------------------------------------

        S::section("2 create_document ({$mode})");

        S::check($types !== [] && array_diff(['header', 'footer', 'component', 'layout_single', 'layout_archive'], $types) === [], 'create_document offers the five core types', implode(', ', $types));

        if ($mode === 'api') {
            S::check($gateway->apiAvailable(), 'Cornerstone\'s document API is available');
        } else {
            S::check(! $gateway->apiAvailable() && $gateway->fallbackForced(), 'pe_force_fallback turns the API path off');
        }

        $docs = [];

        foreach ($types as $type) {
            $label = $typeLabels[$type] ?? $type;
            $title = "PE TEST {$label} ({$mode}) {$run}";
            $marker = 'PE-TEST-' . strtoupper(str_replace('_', '-', $type)) . "-{$mode}-{$run}";
            $componentId = null;

            if ($type === 'component') {
                $componentId = 'peTest' . ucfirst($mode) . $run . 'A';
                $fixture = $componentFixture($componentId, "PE Test Card {$mode}");
            } else {
                $shape = in_array($type, ['header', 'footer'], true) ? $type : 'theme';
                $fixture = $layoutFixture($shape, $marker, $type === 'layout_archive' ? ['header_enabled' => false] : []);
            }

            $created = S::ok(S::call('create_document', ['type' => $type, 'title' => $title, 'layout_data' => $fixture]), "{$type}: create");
            $id = (int) ($created['document_id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            S::track('document', $id, $title);
            S::check(($created['created'] ?? null) === true, "{$type}: created is true");
            S::check(($created['write_path'] ?? null) === $expectedPath, "{$type}: written through {$expectedPath}", ($created['write_path'] ?? 'null') . ' ' . $warningsOf($created));
            S::check(
                S::postField($id, 'post_status') === 'tco-data' && S::postField($id, 'post_type') === $gateway->postTypeForDocType($gateway->docTypeForType($type)),
                "{$type}: stored as a " . $gateway->postTypeForDocType($gateway->docTypeForType($type)) . ' post',
                (string) S::postField($id, 'post_type')
            );
            S::check(S::postField($id, 'post_title') === $title, "{$type}: title stored");

            $stored = S::layout($id);
            $storedSettings = is_array($stored['settings'] ?? null) ? $stored['settings'] : [];

            if ($type === 'component') {
                S::same(S::stamped($fixture['elements'], 'flat'), $stored['elements'] ?? null, "{$type}: get_layout returns the elements that were sent, stamped");
                S::settingsRoundTrip($fixture['settings'], $storedSettings, $componentExtra, "{$type}: settings round-trip");
                S::check(($storedSettings['general_post_title'] ?? null) === $title, "{$type}: general_post_title matches the title");
            } else {
                $extraKey = in_array($type, ['header', 'footer'], true) ? $type : 'theme';
                $expectedLayoutType = in_array($type, ['header', 'footer'], true) ? 'any' : $gateway->layoutTypeFor($gateway->docTypeForType($type));
                S::same(S::stamped($fixture['regions'], 'regions'), $stored['regions'] ?? null, "{$type}: get_layout returns the regions that were sent, stamped");
                S::settingsRoundTrip($fixture['settings'], $storedSettings, $layoutExtra[$extraKey], "{$type}: settings round-trip");
                S::check(($storedSettings['layout_type'] ?? null) === $expectedLayoutType, "{$type}: layout_type is \"{$expectedLayoutType}\"", (string) wp_json_encode($storedSettings['layout_type'] ?? null));
                S::check(($storedSettings['assignments'] ?? null) === [], "{$type}: not assigned");
            }

            $again = S::ok(S::call('create_document', ['type' => $type, 'title' => $title, 'layout_data' => $fixture]), "{$type}: create again");
            S::check(($again['created'] ?? null) === false && (int) ($again['document_id'] ?? 0) === $id, "{$type}: if_not_exists returns the same document", (string) wp_json_encode($again));

            $docs[$type] = ['id' => $id, 'title' => $title, 'marker' => $marker, 'fixture' => $fixture, 'component_id' => $componentId];
        }

        $dryTitle = "PE TEST Dry run ({$mode}) {$run}";
        $dry = S::ok(S::call('create_document', ['type' => 'footer', 'title' => $dryTitle, 'dry_run' => true, 'settings' => ['assignment_priority' => 3]]), 'dry run');
        S::check(
            ($dry['dry_run'] ?? null) === true && array_key_exists('document_id', $dry) && $dry['document_id'] === null
                && ($dry['would_create']['settings']['assignment_priority'] ?? null) === 3
                && ($dry['would_create']['settings']['layout_type'] ?? null) === 'any'
                && ($dry['write_path'] ?? null) === $expectedPath,
            'a dry run reports what it would create',
            (string) wp_json_encode($dry)
        );
        S::check(get_posts(['post_type' => 'cs_footer', 'post_status' => 'tco-data', 'title' => $dryTitle, 'fields' => 'ids', 'suppress_filters' => true]) === [], 'a dry run creates nothing');

        $badTitle = "PE TEST Rejected ({$mode}) {$run}";
        S::isError(S::call('create_document', ['type' => 'header', 'title' => $badTitle, 'settings' => ['assignments' => [['group' => 'yes', 'condition' => 'site:entire-site', 'value' => '']]]]), 'malformed assignments are rejected', 'group must be a boolean');
        S::isError(S::call('create_document', ['type' => 'header', 'title' => $badTitle, 'settings' => ['assignments' => [['condition' => 'site:entire-site']]]]), 'assignments need exactly group, condition and value', 'exactly the keys');
        S::isError(S::call('create_document', ['type' => 'footer', 'title' => $badTitle, 'settings' => ['multi_region' => true]]), 'a setting of another document type is rejected', 'Unknown setting "multi_region"');
        S::isError(S::call('create_document', ['type' => 'header', 'title' => $badTitle, 'slug' => 'nope']), 'slug is for components only', 'component');
        S::isError(S::call('create_document', ['type' => 'footer', 'title' => $badTitle, 'layout_data' => ['settings' => [], 'regions' => ['footer' => $invalidPage]]]), 'an invalid layout is rejected', 'failed validation');
        S::isError(S::call('create_document', ['type' => 'component', 'title' => $badTitle, 'layout_data' => ['elements' => ['e1' => ['_type' => 'text']]]]), 'component data without a root is rejected', 'root');
        S::check(get_posts(['post_type' => ['cs_header', 'cs_footer', 'cs_global_block'], 'post_status' => 'tco-data', 'title' => $badTitle, 'fields' => 'ids', 'suppress_filters' => true]) === [], 'rejected calls create nothing');

        // Assign the header to the entire site, check the homepage, unassign.

        if (isset($docs['header'])) {
            $header = $docs['header'];
            $home = S::fetch('/');
            S::check($home['code'] === 200 && ! str_contains($home['body'], $header['marker']), 'the homepage loads without the unassigned test header', "HTTP {$home['code']}");

            // Cornerstone renders the entire-site header that sorts first (by
            // priority, then title). Another one that sorts first would hide
            // the test header, so the homepage checks are skipped then.
            $winners = array_filter(
                $gateway->documentsClaiming('layout:header', 'site:entire-site', 0, $header['id']),
                static fn(array $doc): bool => $doc['title'] <= $header['title']
            );
            $checkHomepage = $winners === [];

            if (! $checkHomepage) {
                S::skip('homepage header checks', 'another entire-site header sorts before the test header: ' . wp_json_encode(array_values($winners)));
            }

            $assigned = false;

            try {
                $assign = S::ok(S::call('update_document_settings', ['document_id' => $header['id'], 'settings' => ['assignments' => $entireSite]]), 'assign the test header to the entire site');
                $assigned = ($assign['updated'] ?? null) === true;
                S::check(($assign['write_path'] ?? null) === $expectedPath, "the assignment was written through {$expectedPath}", ($assign['write_path'] ?? 'null') . ' ' . $warningsOf($assign));
                S::check(($assign['changes']['assignments']['before'] ?? null) === [] && ($assign['changes']['assignments']['after'] ?? null) === $entireSite, 'the change is reported with before and after', (string) wp_json_encode($assign['changes'] ?? null));
                S::check(is_string($assign['backup_id'] ?? null), 'the settings change was backed up');

                if ($checkHomepage) {
                    $home = S::fetch('/');
                    S::check($home['code'] === 200 && str_contains($home['body'], $header['marker']), 'the homepage shows the assigned test header', "HTTP {$home['code']}");
                }

                $claim = S::ok(S::call('create_document', ['type' => 'header', 'title' => "PE TEST Claim ({$mode}) {$run}", 'dry_run' => true, 'settings' => ['assignments' => $entireSite]]), 'dry run of a second entire-site header');
                S::check(str_contains($warningsOf($claim), '#' . $header['id']), 'it warns that the test header already claims the entire site', $warningsOf($claim));
            } finally {
                if ($assigned) {
                    $unassign = S::call('update_document_settings', ['document_id' => $header['id'], 'settings' => ['assignments' => []]]);
                    S::check(! $unassign['is_error'] && ($unassign['data']['updated'] ?? null) === true, 'unassign the test header', substr($unassign['text'], 0, 300));

                    if ((S::layout($header['id'])['settings']['assignments'] ?? null) !== []) {
                        S::attention("PE TEST header #{$header['id']} may still be assigned to the entire site. Unassign it in Cornerstone.");
                    }
                }
            }

            if ($assigned && $checkHomepage) {
                $home = S::fetch('/');
                S::check($home['code'] === 200 && ! str_contains($home['body'], $header['marker']), 'the homepage no longer shows the test header', "HTTP {$home['code']}");
            }

            S::same(S::stamped($header['fixture']['regions'], 'regions'), S::layout($header['id'])['regions'] ?? null, 'settings updates left the header elements untouched');
            S::check((S::layout($header['id'])['settings']['layout_type'] ?? null) === 'any', 'the header kept layout_type "any"');
        }

        // 3. Components ----------------------------------------------------

        S::section("3 components ({$mode})");

        if (isset($docs['component'])) {
            $component = $docs['component'];
            $cid = (string) $component['component_id'];
            $row = S::component($cid);

            S::check($row !== null, 'list_components shows the new component without refresh');
            S::check(($row['document_id'] ?? null) === $component['id'] && ($row['label'] ?? null) === "PE Test Card {$mode}", 'it reports the source document and label', (string) wp_json_encode($row));
            S::check(($row['element_type'] ?? null) === 'layout-div' && ($row['library_group'] ?? null) === 'PE Test' && ($row['prefab'] ?? null) === false, 'it reports the element type, library group and prefab flag');
            S::check(($row['parameter_groups'] ?? null) === ['peTestTitle'], 'it lists the declared parameter', (string) wp_json_encode($row['parameter_groups'] ?? null));

            $byDoc = S::ok(S::call('list_components', ['document_id' => $component['id'], 'include_parameters' => true]), 'list_components for the document, with parameters');
            S::check(($byDoc['count'] ?? null) === 1 && isset($byDoc['components'][0]['parameters']), 'include_parameters adds the parameter tree', (string) wp_json_encode($byDoc));

            $instance = $pageWithComponent($cid);
            $check = S::ok(S::call('validate_layout', ['layout_data' => $instance]), 'validate a page with an instance');
            S::check(($check['valid'] ?? null) === true && ! str_contains(implode(' ', (array) ($check['warnings'] ?? [])), $cid), 'the instance validates without component warnings', (string) wp_json_encode($check['warnings'] ?? null));

            S::forgetCornerstoneComponents();
            $pageTitle = "PE TEST Component page ({$mode}) {$run}";
            $page = S::ok(S::call('create_page', ['title' => $pageTitle, 'layout_data' => $instance]), 'create a PE TEST page with the instance');

            if (isset($page['post_id'])) {
                S::track('page', (int) $page['post_id'], $pageTitle);
                S::check(! str_contains($warningsOf($page), $cid), 'the page was created without component warnings', $warningsOf($page));
                S::same(S::stamped($instance), S::layout((int) $page['post_id']), 'the page holds the instance');
            }

            $missingId = 'peTestMissing' . $run;
            $check = S::ok(S::call('validate_layout', ['layout_data' => $pageWithComponent($missingId)]), 'validate an instance of a made-up component');
            S::check(($check['valid'] ?? null) === true && str_contains(implode(' ', (array) ($check['warnings'] ?? [])), $missingId), 'a made-up component_id is a warning, not an error', (string) wp_json_encode($check));

            $check = S::ok(S::call('validate_layout', ['layout_data' => $pageWithComponent($cid, ['peTestTitle' => 'Hi', 'peTestNope' => 'x'])]), 'validate an instance with an undeclared parameter');
            S::check(str_contains(implode(' ', (array) ($check['warnings'] ?? [])), 'peTestNope'), 'an undeclared parameter is a warning', (string) wp_json_encode($check['warnings'] ?? null));
        } else {
            S::check(false, 'a component document was created for these checks');
        }

        // 4. Registry refresh after deploy, update and restore -------------

        S::section("4 registry refresh ({$mode})");

        if (isset($docs['component'])) {
            $component = $docs['component'];
            $cid = (string) $component['component_id'];
            $cidB = substr($cid, 0, -1) . 'B';

            $data = $componentFixture($cid, "PE Test Card {$mode}", ['e4' => $noteComponent($cidB)]);
            $data['settings'] = ['customCSS' => '', 'customJS' => '', 'document_visibility' => ''];

            $deploy = S::ok(S::call('deploy_layout', ['post_id' => $component['id'], 'layout_data' => $data]), 'deploy_layout on the component document');
            S::check(($deploy['deployed'] ?? null) === true && ($deploy['write_path'] ?? null) === $expectedPath, "deployed through {$expectedPath}", (string) wp_json_encode($deploy));
            S::check(S::component($cidB) !== null, 'deploy_layout refreshed the registry: the added component is listed');

            $stored = S::layout($component['id']);
            S::same($data['elements'], $stored['elements'] ?? null, 'the deploy replaced the elements');
            S::check(($stored['settings']['library_group'] ?? null) === '', '16: library_group, left out of the deploy, is back at its default', (string) wp_json_encode($stored['settings'] ?? null));
            S::check(S::postField($component['id'], 'post_title') === $component['title'] && ($stored['settings']['general_post_title'] ?? null) === $component['title'], 'the deploy kept the title');

            $update = S::ok(S::call('update_layout', [
                'post_id'    => $component['id'],
                'operations' => [['op' => 'update', 'path' => 'elements.e2', 'value' => ['_label' => "PE Test Card {$mode} v2"]]],
            ]), 'update_layout on the component document');
            S::check(($update['updated'] ?? null) === true && ($update['write_path'] ?? null) === $expectedPath, "updated through {$expectedPath}", (string) wp_json_encode($update));
            S::check((S::component($cid)['label'] ?? null) === "PE Test Card {$mode} v2", 'update_layout refreshed the registry: the new label is listed');

            $restore = S::ok(S::call('restore_layout', ['post_id' => $component['id'], 'backup_id' => (string) ($update['backup_id'] ?? '')]), 'restore_layout on the component document');
            S::check(($restore['restored'] ?? null) === true && ($restore['write_path'] ?? null) === $expectedPath, "restored through {$expectedPath}", (string) wp_json_encode($restore));
            S::check((S::component($cid)['label'] ?? null) === "PE Test Card {$mode}", 'restore_layout refreshed the registry: the old label is back');

            $summary = S::ok(S::call('get_layout', ['post_id' => $component['id'], 'summary' => true]), 'get_layout with summary');
            S::check(! array_key_exists('data', $summary) && ($summary['element_count'] ?? null) === 3 && is_array($summary['outline'] ?? null) && ($summary['bytes'] ?? 0) > 0, 'summary returns an outline, the element count and the size', (string) wp_json_encode(array_diff_key($summary, ['outline' => 1])));
            $subtree = S::ok(S::call('get_layout', ['post_id' => $component['id'], 'path' => 'e2']), 'get_layout with path');
            S::check(($subtree['data']['root'] ?? null) === 'e2' && array_keys((array) ($subtree['data']['elements'] ?? [])) === ['e2', 'e3'], 'path returns the element and its descendants');
        }

        // 16. deploy_layout is a full replace ------------------------------

        S::section("16 full replace ({$mode})");

        if (isset($docs['layout_archive'])) {
            $archive = $docs['layout_archive'];
            S::check((S::layout($archive['id'])['settings']['header_enabled'] ?? null) === false, 'the archive layout starts with header_enabled off');

            $data = $layoutFixture('theme', $archive['marker'] . '-V2');
            unset($data['settings']['header_enabled']);

            $deploy = S::ok(S::call('deploy_layout', ['post_id' => $archive['id'], 'layout_data' => $data]), 'deploy the archive layout without header_enabled');
            S::check(($deploy['deployed'] ?? null) === true && ($deploy['write_path'] ?? null) === $expectedPath, "deployed through {$expectedPath}", (string) wp_json_encode($deploy));

            $after = S::layout($archive['id']);
            S::check(($after['settings']['header_enabled'] ?? null) === true, 'header_enabled is back at its default', (string) wp_json_encode($after['settings'] ?? null));
            S::check(($after['settings']['layout_type'] ?? null) === 'archive' && S::postField($archive['id'], 'post_type') === 'cs_layout_archive', 'the archive layout is still an archive');
            S::same($data['regions'], $after['regions'] ?? null, 'the regions were replaced whole');
            S::check(S::postField($archive['id'], 'post_title') === $archive['title'], 'the deploy kept the title');

            $data['settings']['layout_type'] = 'single';
            $deploy = S::ok(S::call('deploy_layout', ['post_id' => $archive['id'], 'layout_data' => $data]), 'deploy the archive layout with layout_type "single"');
            S::check(str_contains($warningsOf($deploy), 'Ignored layout_type'), 'the other layout_type is ignored with a warning', $warningsOf($deploy));
            S::check((S::layout($archive['id'])['settings']['layout_type'] ?? null) === 'archive' && S::postField($archive['id'], 'post_type') === 'cs_layout_archive', 'the archive layout is still an archive');
        } else {
            S::check(false, 'an archive layout was created for these checks');
        }

        if (isset($docs['header'])) {
            $deploy = S::ok(S::call('deploy_layout', ['post_id' => $docs['header']['id'], 'layout_data' => ['regions' => $docs['header']['fixture']['regions']]]), 'deploy a header without a settings object');
            S::check(str_contains($warningsOf($deploy), 'kept its current settings'), 'the header keeps its settings, with a warning', $warningsOf($deploy));
            S::check((S::layout($docs['header']['id'])['settings']['layout_type'] ?? null) === 'any', 'the header keeps layout_type "any"');
        }
    } finally {
        remove_filter('pe_force_fallback', $forceFallback);
    }
}

// 9. Raw Content round trip ----------------------------------------------------

S::section('9 Raw Content round trip');

$rawFooter = $layoutFixture('footer', "PE-TEST-RAW-{$run}");
$rawFooter['regions']['footer'][0]['_modules'][0]['_modules'][] = ['_type' => 'raw-content', '_region' => 'footer', 'raw_content' => $rawHtml];
$rawFooterTitle = "PE TEST Raw Content footer {$run}";
$rawDoc = S::ok(S::call('create_document', ['type' => 'footer', 'title' => $rawFooterTitle, 'layout_data' => $rawFooter]), 'create a footer with an iframe and a script in Raw Content');
$rawDocId = (int) ($rawDoc['document_id'] ?? 0);

if ($rawDocId > 0) {
    S::track('document', $rawDocId, $rawFooterTitle);
    S::same(S::stamped($rawFooter['regions'], 'regions'), S::layout($rawDocId)['regions'] ?? null, 'the document returns the Raw Content unchanged');

    $rawFooter['regions']['footer'][0]['_modules'][0]['_modules'][1]['raw_content'] = $rawHtml . '<!-- v2 -->';
    $deploy = S::ok(S::call('deploy_layout', ['post_id' => $rawDocId, 'layout_data' => $rawFooter]), 'deploy the footer with changed Raw Content');
    S::check(($deploy['deployed'] ?? null) === true, 'deployed');
    S::same($rawFooter['regions'], S::layout($rawDocId)['regions'] ?? null, 'the deployed Raw Content is unchanged');
}

$rawPage = $pageLayout([['_type' => 'raw-content', 'raw_content' => $rawHtml]]);
$rawPageTitle = "PE TEST Raw Content page {$run}";
$rawPageResult = S::ok(S::call('create_page', ['title' => $rawPageTitle, 'layout_data' => $rawPage]), 'create a page with an iframe and a script in Raw Content');

if (isset($rawPageResult['post_id'])) {
    $rawPageId = (int) $rawPageResult['post_id'];
    S::track('page', $rawPageId, $rawPageTitle);
    S::same(S::stamped($rawPage), S::layout($rawPageId), 'the page returns the Raw Content unchanged');
    $rendered = (string) S::postField($rawPageId, 'post_content');
    S::check(str_contains($rendered, '<iframe') && str_contains($rendered, 'window.peSmokeTest'), 'the rendered page keeps the iframe and the script');
}

// 14. update_document_settings -------------------------------------------------

S::section('14 update_document_settings');

$renameTitle = "PE TEST Rename {$run}";
$renameSlug = "pe-test-rename-{$run}";
$rename = S::ok(S::call('create_document', [
    'type'        => 'component',
    'title'       => $renameTitle,
    'slug'        => $renameSlug,
    'layout_data' => $componentFixture('peTestRename' . $run, 'PE Test Rename'),
]), 'create a component document to rename');
$renameId = (int) ($rename['document_id'] ?? 0);

if ($renameId > 0) {
    S::track('document', $renameId, $renameTitle);
    S::check(($rename['slug'] ?? null) === $renameSlug && S::postField($renameId, 'post_name') === $renameSlug, 'the component got the requested slug', (string) wp_json_encode($rename));

    $newTitle = "PE TEST Renamed {$run}";
    $newSlug = "pe-test-renamed-{$run}";

    $dry = S::ok(S::call('update_document_settings', ['document_id' => $renameId, 'title' => $newTitle, 'dry_run' => true]), 'dry run a rename');
    S::check(($dry['updated'] ?? null) === false && array_key_exists('backup_id', $dry) && $dry['backup_id'] === null && isset($dry['changes']['title']), 'the dry run reports the change and makes no backup', (string) wp_json_encode($dry));
    S::check(S::postField($renameId, 'post_title') === $renameTitle, 'the dry run changed nothing');

    $first = S::ok(S::call('update_document_settings', [
        'document_id' => $renameId,
        'title'       => $newTitle,
        'slug'        => $newSlug,
        'settings'    => ['library_group' => 'PE Test Renamed'],
    ]), 'rename the document and change a setting');
    S::check(
        ($first['changes']['title']['before'] ?? null) === $renameTitle
            && ($first['changes']['slug']['before'] ?? null) === $renameSlug
            && ($first['changes']['slug']['after'] ?? null) === $newSlug
            && ($first['changes']['library_group']['after'] ?? null) === 'PE Test Renamed',
        'before and after are reported for each change',
        (string) wp_json_encode($first['changes'] ?? null)
    );
    S::check(($first['write_path'] ?? null) === 'cornerstone-api' && is_string($first['backup_id'] ?? null), 'written through cornerstone-api, with a backup', (string) wp_json_encode($first));
    clean_post_cache($renameId);
    S::check(S::postField($renameId, 'post_title') === $newTitle && S::postField($renameId, 'post_name') === $newSlug, 'the title and slug changed');
    $storedRename = S::layout($renameId);
    S::check(($storedRename['settings']['general_post_name'] ?? null) === $newSlug && ($storedRename['settings']['library_group'] ?? null) === 'PE Test Renamed', 'the stored settings follow');
    S::same(S::stamped($componentFixture('peTestRename' . $run, 'PE Test Rename')['elements'], 'flat'), $storedRename['elements'] ?? null, 'the elements were not touched');

    $deploy = S::ok(S::call('deploy_layout', ['post_id' => $renameId, 'layout_data' => $storedRename]), 'deploy the document unchanged (its backup has no title fields)');
    $second = S::ok(S::call('update_document_settings', ['document_id' => $renameId, 'title' => "PE TEST Renamed again {$run}"]), 'rename it again');
    S::check(($second['updated'] ?? null) === true, 'renamed again');

    S::ok(S::call('restore_layout', ['post_id' => $renameId, 'backup_id' => (string) ($deploy['backup_id'] ?? '')]), 'restore the deploy backup');
    clean_post_cache($renameId);
    S::check(S::postField($renameId, 'post_title') === "PE TEST Renamed again {$run}", 'restoring an older, unrelated backup keeps the later title');

    S::ok(S::call('restore_layout', ['post_id' => $renameId, 'backup_id' => (string) ($first['backup_id'] ?? '')]), 'restore the backup from the first rename');
    clean_post_cache($renameId);
    S::check(S::postField($renameId, 'post_title') === $renameTitle && S::postField($renameId, 'post_name') === $renameSlug, 'restore_layout put the title and slug back');
    S::check((S::layout($renameId)['settings']['library_group'] ?? null) === 'PE Test', 'restore_layout put the setting back');

    S::isError(S::call('update_document_settings', ['document_id' => $renameId, 'settings' => ['bogus_setting' => 1]]), 'an unknown settings key is rejected', 'Unknown setting "bogus_setting"');
    S::isError(S::call('update_document_settings', ['document_id' => $renameId, 'settings' => ['assignments' => []]]), 'a setting of another document type is rejected', 'Unknown setting "assignments"');
    S::isError(S::call('update_document_settings', ['document_id' => $renameId, 'settings' => ['document_visibility' => 'public']]), 'an invalid value is rejected', 'document_visibility');
    S::isError(S::call('update_document_settings', ['document_id' => $renameId]), 'a call with nothing to change is rejected', 'Nothing to change');
    S::isError(S::call('update_document_settings', ['document_id' => $renameId, 'title' => 'PE TEST x', 'bogus' => 1]), 'unknown arguments are rejected', 'bogus');
    S::check(S::postField($renameId, 'post_title') === $renameTitle, 'rejected calls changed nothing');
}

if ($lockPageId > 0) {
    S::isError(S::call('update_document_settings', ['document_id' => $lockPageId, 'settings' => ['assignment_priority' => 1]]), 'a page is not a document', 'not a Cornerstone');
}

if (isset($jsonFooter['document_id'])) {
    S::isError(S::call('update_document_settings', ['document_id' => (int) $jsonFooter['document_id'], 'slug' => 'nope']), 'slug is for components only', 'component');
}

// 15. create_page --------------------------------------------------------------

S::section('15 create_page');

$parentTitle = "PE TEST Parent {$run}";
$parentPage = S::ok(S::call('create_page', ['title' => $parentTitle, 'slug' => "pe-test-parent-{$run}", 'status' => 'private']), 'create a parent page');
$parentId = (int) ($parentPage['post_id'] ?? 0);

if ($parentId > 0) {
    S::track('page', $parentId, $parentTitle);
    S::check(($parentPage['created'] ?? null) === true && ($parentPage['parent'] ?? null) === 0, 'created at the top level');

    $childArgs = [
        'title'      => "PE TEST Child {$run}",
        'slug'       => "pe-test-child-{$run}",
        'status'     => 'private',
        'parent_id'  => $parentId,
        'menu_order' => 3,
        'excerpt'    => 'PE TEST excerpt',
        'template'   => 'default',
    ];
    $child = S::ok(S::call('create_page', $childArgs), 'create a child page');
    $childId = (int) ($child['post_id'] ?? 0);

    if ($childId > 0) {
        S::track('page', $childId, $childArgs['title']);
        S::check(($child['parent'] ?? null) === $parentId && (int) S::postField($childId, 'post_parent') === $parentId, 'parent_id is set');
        clean_post_cache($childId);
        S::check((int) get_post_field('menu_order', $childId) === 3 && get_post_field('post_excerpt', $childId) === 'PE TEST excerpt', 'menu_order and excerpt are set');
        S::check(($child['template'] ?? null) === 'default', 'the template is "default"', (string) ($child['template'] ?? ''));

        $again = S::ok(S::call('create_page', $childArgs + ['if_not_exists' => true]), 'create the child again with if_not_exists');
        S::check(($again['created'] ?? null) === false && ($again['post_id'] ?? null) === $childId, 'if_not_exists returns the existing page', (string) wp_json_encode($again));

        $dupe = S::ok(S::call('create_page', $childArgs), 'create the child again without if_not_exists');
        $dupeId = (int) ($dupe['post_id'] ?? 0);

        if ($dupeId > 0) {
            S::track('page', $dupeId, $childArgs['title']);
            S::check($dupeId !== $childId && ($dupe['slug'] ?? '') === "pe-test-child-{$run}-2", 'a duplicate slug gets the usual -2 suffix', (string) ($dupe['slug'] ?? ''));
        }
    }

    $templates = array_keys(wp_get_theme()->get_page_templates(null, 'page'));

    if (in_array('template-blank-4.php', $templates, true)) {
        $templated = S::ok(S::call('create_page', ['title' => "PE TEST Template {$run}", 'template' => 'template-blank-4.php', 'parent_id' => $parentId, 'status' => 'private']), 'create a page with a template');

        if (isset($templated['post_id'])) {
            S::track('page', (int) $templated['post_id'], "PE TEST Template {$run}");
            S::check(($templated['template'] ?? null) === 'template-blank-4.php', 'the template is set');
        }
    } else {
        S::skip('create a page with a template', 'template-blank-4.php is not offered by this theme');
    }

    S::isError(S::call('create_page', ['title' => "PE TEST Bad template {$run}", 'template' => 'no-such-template.php']), 'an unknown template is rejected', 'Unknown page template');

    if ($rawDocId > 0) {
        S::isError(S::call('create_page', ['title' => "PE TEST Bad parent {$run}", 'parent_id' => $rawDocId]), 'a parent that is not a page is rejected', 'not an existing page');
    }
}

$draftTitle = "PE TEST Draft {$run}";
$draft = S::ok(S::call('create_page', ['title' => $draftTitle]), 'create a draft page without a slug');
$draftId = (int) ($draft['post_id'] ?? 0);

if ($draftId > 0) {
    S::track('page', $draftId, $draftTitle);
    $draftAgain = S::ok(S::call('create_page', ['title' => $draftTitle, 'if_not_exists' => true]), 'create the draft again with if_not_exists');
    S::check(($draftAgain['created'] ?? null) === false && ($draftAgain['post_id'] ?? null) === $draftId, 'if_not_exists finds a draft without a slug by its title', (string) wp_json_encode($draftAgain));
}

$invalidTitle = "PE TEST Invalid layout {$run}";
S::isError(S::call('create_page', ['title' => $invalidTitle, 'layout_data' => $invalidPage]), 'an invalid layout is refused', 'failed validation');

$leftBehind = get_posts([
    'post_type'        => 'page',
    'post_status'      => ['publish', 'draft', 'private', 'pending', 'future', 'trash'],
    'title'            => $invalidTitle,
    'fields'           => 'ids',
    'suppress_filters' => true,
]);
$rejectedTitles = ["PE TEST Bad template {$run}", "PE TEST Bad parent {$run}", "PE TEST Bad JSON {$run}"];

foreach ($rejectedTitles as $rejected) {
    $leftBehind = array_merge($leftBehind, get_posts([
        'post_type'        => 'page',
        'post_status'      => ['publish', 'draft', 'private', 'pending', 'future', 'trash'],
        'title'            => $rejected,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ]));
}

S::check($leftBehind === [], 'no page was left behind by a refused call', implode(', ', $leftBehind));

// 5. set_colors ------------------------------------------------------------------

S::section('5 set_colors');

$colorsOption = 'cornerstone_color_items';
$isEmptyList = static fn(array $state): bool => ! $state['exists'] || in_array(trim(wp_unslash((string) $state['raw'])), ['', '[]', '{}'], true);

S::isError(S::call('set_colors', ['colors' => [['_id' => 'x', 'title' => 'X', 'value' => '#000']], 'dry_run' => true]), 'a short _id is rejected', '_id');
S::isError(S::call('set_colors', ['colors' => [['_id' => 'peTestBad', 'title' => 'Bad', 'value' => 'red; background: blue']], 'dry_run' => true]), 'a value with ";" is rejected', 'value');
S::isError(S::call('set_colors', ['colors' => [['_id' => 'peTestBad', 'title' => 'Bad', 'value' => '#000', 'locked' => false]], 'dry_run' => true]), 'unknown color keys are rejected', 'locked');
S::isError(S::call('set_colors', ['colors' => [['_id' => 'peTestBad', 'title' => 'Bad']], 'dry_run' => true]), 'a new color needs a value', 'needs both title and value');
S::isError(S::call('set_colors', ['colors' => [['_id' => 'peTestBad', 'title' => 'Bad', 'value' => '#000'], ['_id' => 'peTestBad', 'title' => 'Bad', 'value' => '#111']], 'dry_run' => true]), 'a color listed twice is rejected', 'more than once');
S::isError(S::call('set_colors', ['colors' => []]), 'an empty list is rejected', 'colors');

$colorsBefore = S::rawOption($colorsOption);

if (! $isEmptyList($colorsBefore)) {
    S::skip('palette writes', 'the palette is not empty, so no test colors were written');
    S::attention('The palette option is not empty on this site; test 5 wrote nothing. Ask before running palette writes here.');
} else {
    $testColors = [
        ['_id' => 'peTestA', 'title' => 'PE Test A', 'value' => '#123456'],
        ['_id' => 'peTestB', 'title' => 'PE Test B', 'value' => 'rgba(0, 0, 0, 0.5)'],
    ];
    $testGroup = ['_id' => 'peTestGroup', 'title' => 'PE Test Group'];

    $dry = S::ok(S::call('set_colors', ['colors' => $testColors, 'group' => $testGroup, 'dry_run' => true]), 'dry run');
    S::check(($dry['changed'] ?? null) === true && ($dry['added'] ?? null) === ['peTestA', 'peTestB'] && S::isNull($dry, 'backup_id'), 'the dry run reports the additions', (string) wp_json_encode($dry));
    S::check(S::rawOption($colorsOption) === $colorsBefore, 'the dry run wrote nothing');

    $firstBackup = null;

    try {
        $write = S::ok(S::call('set_colors', ['colors' => $testColors, 'group' => $testGroup]), 'write peTestA and peTestB in peTestGroup');
        $firstBackup = is_string($write['backup_id'] ?? null) ? $write['backup_id'] : null;
        S::check($firstBackup !== null && ($write['group']['action'] ?? null) === 'created' && is_string($write['write_path'] ?? null), 'the write reports a backup, the new group and the write path', (string) wp_json_encode($write));

        $items = S::jsonOption($colorsOption) ?? [];
        S::check(($items[0]['_id'] ?? null) === 'peTestA', 'peTestA is at index 0', (string) wp_json_encode($items));
        S::check(count($items) === 3 && ($items[2]['children'] ?? null) === ['peTestA', 'peTestB'], 'the new group holds both colors', (string) wp_json_encode($items));

        $listed = S::ok(S::call('list_colors'), 'list_colors');
        S::check(in_array('peTestA', array_column((array) ($listed['colors'] ?? []), '_id'), true), 'list_colors shows peTestA');

        $update = S::ok(S::call('set_colors', ['colors' => [['_id' => 'peTestA', 'value' => '#654321']]]), 'update peTestA');
        S::check(($update['added'] ?? null) === [] && ($update['updated'][0]['_id'] ?? null) === 'peTestA' && ($update['updated'][0]['before']['value'] ?? null) === '#123456', 'the update is reported with before and after', (string) wp_json_encode($update));

        $items = S::jsonOption($colorsOption) ?? [];
        $matches = array_values(array_filter($items, static fn($item): bool => is_array($item) && ($item['_id'] ?? null) === 'peTestA'));
        S::check(count($matches) === 1 && ($matches[0]['value'] ?? null) === '#654321' && ($matches[0]['title'] ?? null) === 'PE Test A', 'peTestA was updated in place, not duplicated', (string) wp_json_encode($items));
        S::check(($items[0]['_id'] ?? null) === 'peTestA' && count($items) === 3, 'the order is unchanged');

        $same = S::ok(S::call('set_colors', ['colors' => [['_id' => 'peTestA', 'value' => '#654321']]]), 'repeat the update');
        S::check(($same['changed'] ?? null) === false && ($same['unchanged'] ?? null) === 1 && S::isNull($same, 'backup_id'), 'an unchanged value writes nothing');

        S::isError(S::call('set_colors', ['colors' => [['_id' => 'peTestGroup', 'title' => 'Renamed']], 'dry_run' => true]), 'a group cannot be edited as a color', 'group');

        $backups = S::ok(S::call('list_settings_backups', ['key' => 'colors']), 'list_settings_backups');
        S::check(in_array($firstBackup, array_column((array) ($backups['backups'] ?? []), 'backup_id'), true), 'the first backup is listed', (string) wp_json_encode($backups));
    } finally {
        if ($firstBackup !== null) {
            $dryRestore = S::call('restore_settings', ['key' => 'colors', 'backup_id' => $firstBackup, 'dry_run' => true]);
            S::check(! $dryRestore['is_error'] && ($dryRestore['data']['restored'] ?? null) === false && ($dryRestore['data']['changed'] ?? null) === true, 'a restore dry run reports the change', substr($dryRestore['text'], 0, 300));

            $restore = S::call('restore_settings', ['key' => 'colors', 'backup_id' => $firstBackup]);
            S::check(! $restore['is_error'] && ($restore['data']['restored'] ?? null) === true && is_string($restore['data']['backup_id'] ?? null), 'restore_settings put the pre-test palette back', substr($restore['text'], 0, 300));
            S::check(($restore['data']['action'] ?? null) === ($colorsBefore['exists'] ? 'write' : 'delete'), 'the restore ' . ($colorsBefore['exists'] ? 'rewrote' : 'deleted') . ' the option');
        }

        $colorsAfter = S::rawOption($colorsOption);
        S::check($colorsAfter === $colorsBefore, $colorsBefore['exists'] ? 'the palette option is byte-identical to before' : 'the palette option is absent again', (string) wp_json_encode($colorsAfter));

        if ($colorsAfter !== $colorsBefore) {
            S::attention('The palette option differs from its state before test 5. Check restore_settings for key "colors".');
        }
    }
}

// 6. set_fonts -------------------------------------------------------------------

S::section('6 set_fonts');

$fontsOption = 'cornerstone_font_items';
$configOption = 'cornerstone_font_config';

S::isError(S::call('set_fonts', ['fonts' => [['_id' => 'peTestBad', 'title' => 'Bad', 'family' => 'Not A Real Font', 'source' => 'google']], 'dry_run' => true]), 'an unknown Google family is rejected', 'not in Cornerstone');
S::isError(S::call('set_fonts', ['fonts' => [['_id' => 'peTestBad', 'title' => 'Bad', 'family' => 'Arial', 'source' => 'adobe']], 'dry_run' => true]), 'an unknown source is rejected', 'source');
S::isError(S::call('set_fonts', ['fonts' => [['_id' => 'peTestBad', 'title' => 'Bad', 'family' => 'Arial', 'source' => 'system', 'weightSelection' => ['450']]], 'dry_run' => true]), 'an invalid weight is rejected', 'weight');
S::isError(S::call('set_fonts', ['fonts' => [['_id' => 'peTestBad', 'title' => 'Bad', 'family' => 'Arial', 'source' => 'system', 'name' => 'helvetica']], 'dry_run' => true]), 'a name that does not match the family is rejected', 'name must be "arial"');
S::isError(S::call('set_fonts', ['config' => ['googleFontsURL' => 'http://fonts.example.com/css'], 'dry_run' => true]), 'an http googleFontsURL is rejected', 'https');
S::isError(S::call('set_fonts', ['config' => ['fontDisplay' => 'sometimes'], 'dry_run' => true]), 'an unknown fontDisplay is rejected', 'fontDisplay');
S::isError(S::call('set_fonts', ['config' => ['customFontFaceCSS' => '</style><script>alert(1)</script>'], 'dry_run' => true]), 'customFontFaceCSS with markup is rejected', 'customFontFaceCSS');
S::isError(S::call('set_fonts', ['config' => ['bogusKey' => 1], 'dry_run' => true]), 'an unknown config key is rejected', 'bogusKey');
S::isError(S::call('set_fonts', ['dry_run' => true]), 'a call with nothing to change is rejected', 'Pass "fonts", "config", or both');

$fontsBefore = S::rawOption($fontsOption);
$configBefore = S::rawOption($configOption);

if (! $isEmptyList($fontsBefore) || ! $isEmptyList($configBefore)) {
    S::skip('font writes', 'the font options are not empty, so no test fonts were written');
    S::attention('The font options are not empty on this site; test 6 wrote nothing. Ask before running font writes here.');
} else {
    $testFonts = [
        ['_id' => 'peTestSans', 'title' => 'PE Test Sans', 'family' => 'Arial', 'source' => 'system'],
        ['_id' => 'peTestSerif', 'title' => 'PE Test Serif', 'family' => 'Georgia', 'source' => 'system', 'weightSelection' => ['400', '400italic', '700']],
    ];
    $fontArgs = ['fonts' => $testFonts, 'group' => ['_id' => 'peTestFontGroup', 'title' => 'PE Test Fonts'], 'config' => ['fontDisplay' => 'swap']];

    $dry = S::ok(S::call('set_fonts', $fontArgs + ['dry_run' => true]), 'dry run');
    S::check(($dry['changed'] ?? null) === true && ($dry['config']['changed'] ?? null) === ['fontDisplay'] && ($dry['backup_ids'] ?? null) === [], 'the dry run reports the fonts and the config change', (string) wp_json_encode($dry));
    S::check(S::rawOption($fontsOption) === $fontsBefore && S::rawOption($configOption) === $configBefore, 'the dry run wrote nothing');

    $fontBackups = [];

    try {
        $write = S::ok(S::call('set_fonts', $fontArgs), 'write two system fonts and fontDisplay');
        $fontBackups = is_array($write['backup_ids'] ?? null) ? $write['backup_ids'] : [];
        S::check(array_keys($fontBackups) === ['fonts', 'font_config'] && ! array_key_exists('backup_id', $write), 'backup_ids has one backup per option', (string) wp_json_encode($write));

        $items = S::jsonOption($fontsOption) ?? [];
        $sans = $items[0] ?? [];
        S::check(($sans['_id'] ?? null) === 'peTestSans' && ($sans['name'] ?? null) === 'arial' && str_starts_with((string) ($sans['stack'] ?? ''), 'Arial'), 'the name and stack are derived from Cornerstone\'s font list', (string) wp_json_encode($sans));
        S::check(($sans['weightNormal'] ?? null) === '400' && ($sans['weightBold'] ?? null) === '700' && ($sans['weightSelection'] ?? null) === ['400', '700'], 'the weights default to normal and bold', (string) wp_json_encode($sans));
        S::check(($items[1]['weightSelection'] ?? null) === ['400', '400italic', '700'], 'a given weight selection is kept');
        S::check(($items[2]['children'] ?? null) === ['peTestSans', 'peTestSerif'], 'the new group holds both fonts');

        $listed = S::ok(S::call('list_fonts'), 'list_fonts');
        S::check(($listed['config']['fontDisplay'] ?? null) === 'swap', 'list_fonts reports the stored config (defect 8)', (string) wp_json_encode($listed['config'] ?? null));
        S::check(in_array('peTestSerif', array_column((array) ($listed['fonts'] ?? []), '_id'), true), 'list_fonts shows the new fonts');

        $update = S::ok(S::call('set_fonts', ['fonts' => [['_id' => 'peTestSans', 'title' => 'PE Test Sans 2']]]), 'update peTestSans');
        S::check(isset($update['backup_id']) && array_keys((array) ($update['backup_ids'] ?? [])) === ['fonts'], 'a fonts-only write has one backup', (string) wp_json_encode($update));
        $items = S::jsonOption($fontsOption) ?? [];
        $matches = array_values(array_filter($items, static fn($item): bool => is_array($item) && ($item['_id'] ?? null) === 'peTestSans'));
        S::check(count($matches) === 1 && ($matches[0]['title'] ?? null) === 'PE Test Sans 2' && ($items[0]['_id'] ?? null) === 'peTestSans', 'peTestSans was updated in place, not duplicated', (string) wp_json_encode($items));
        S::check(($matches[0]['name'] ?? null) === 'arial' && ($matches[0]['family'] ?? null) === 'Arial', 'keys not given were kept');
    } finally {
        foreach (['fonts' => $fontsOption, 'font_config' => $configOption] as $key => $option) {
            if (! isset($fontBackups[$key])) {
                continue;
            }

            $restore = S::call('restore_settings', ['key' => $key, 'backup_id' => (string) $fontBackups[$key]]);
            S::check(! $restore['is_error'] && ($restore['data']['restored'] ?? null) === true, "restore_settings put the pre-test {$key} back", substr($restore['text'], 0, 300));
        }

        foreach ([[$fontsOption, $fontsBefore], [$configOption, $configBefore]] as [$option, $before]) {
            $after = S::rawOption($option);
            S::check($after === $before, $before['exists'] ? "{$option} is byte-identical to before" : "{$option} is absent again", (string) wp_json_encode($after));

            if ($after !== $before) {
                S::attention("{$option} differs from its state before test 6. Check restore_settings.");
            }
        }
    }
}

// 7. set_global_css ----------------------------------------------------------------

S::section('7 set_global_css');

$cssKey = $gateway->globalCssKey();
$cssBefore = S::rawOption($cssKey);
$cssState = S::ok(S::call('get_global_css', ['full' => true]), 'get_global_css');
S::check(($cssState['option_key'] ?? null) === $cssKey, 'it reports the Global CSS option', (string) ($cssState['option_key'] ?? ''));

$outside = static fn(): string => \ProExtended\Css\CssBlocks::outside((string) (S::call('get_global_css', ['full' => true])['data']['css'] ?? ''));
$blockNames = static fn(): array => array_column((array) (S::call('get_global_css')['data']['blocks'] ?? []), 'name');
$outsideBefore = $outside();

if (in_array('pe-test', $blockNames(), true)) {
    S::attention('Global CSS already has a pe-test block before test 7; the test will replace it and then restore the original.');
}

S::isError(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => '.pe-test { color: red; } </style><script>alert(1)</script>', 'dry_run' => true]), 'CSS containing </style> is rejected', '</style');
S::isError(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => '.pe-test { color: red;', 'dry_run' => true]), 'unbalanced braces are rejected', 'unclosed "{"');
S::isError(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => '/* open comment .pe-test { color: red; }', 'dry_run' => true]), 'an unclosed comment is rejected', 'comment');
S::isError(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => '/* pe:begin other */ .x { color: red; }', 'dry_run' => true]), 'CSS containing a pe:begin marker is rejected', 'pe:begin');
S::isError(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'PE Test', 'css' => '.x { color: red; }', 'dry_run' => true]), 'an invalid block name is rejected', 'name');
S::isError(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => '/* ' . str_repeat('a', 262200) . ' */', 'dry_run' => true]), 'CSS over 256 KB is rejected', '256 KB');
S::isError(S::call('set_global_css', ['operation' => 'remove_block', 'name' => 'pe-test-missing-' . $run, 'dry_run' => true]), 'removing a block that does not exist is an error', 'no block named');
S::isError(S::call('set_global_css', ['operation' => 'replace_all', 'css' => '.x { color: red; }', 'dry_run' => true]), 'replace_all needs confirm_replace_all', 'confirm_replace_all');
S::isError(S::call('set_global_css', ['operation' => 'rewrite', 'dry_run' => true]), 'an unknown operation is rejected', 'operation');

$replaceDry = S::ok(S::call('set_global_css', ['operation' => 'replace_all', 'css' => ".pe-test-replace { color: #111; }\n", 'confirm_replace_all' => true, 'dry_run' => true]), 'replace_all as a dry run');
S::check(($replaceDry['dry_run'] ?? null) === true && S::isNull($replaceDry, 'backup_id') && S::rawOption($cssKey) === $cssBefore, 'the replace_all dry run wrote nothing');

$testCss = ".pe-test-block {\n  color: #111111;\n  background-color: #ffffff;\n}";
$dry = S::ok(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => $testCss, 'dry_run' => true]), 'upsert as a dry run');
S::check(($dry['changed'] ?? null) === true && str_contains((string) ($dry['diff'] ?? ''), 'pe:begin pe-test') && S::rawOption($cssKey) === $cssBefore, 'the dry run returns a diff and writes nothing', (string) wp_json_encode($dry));

$cssBackup = null;

try {
    $create = S::ok(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => $testCss]), 'create the pe-test block');
    $cssBackup = is_string($create['backup_id'] ?? null) ? $create['backup_id'] : null;
    S::check($cssBackup !== null && ($create['changed'] ?? null) === true && is_string($create['write_path'] ?? null), 'the write reports a backup and the write path', (string) wp_json_encode($create));
    S::check(in_array('pe-test', $blockNames(), true), 'the pe-test block is listed');
    S::check($outside() === $outsideBefore, 'CSS outside the managed blocks is unchanged after the create');
    S::check((S::call('get_global_css', ['name' => 'pe-test'])['data']['block']['css'] ?? null) === $testCss, 'get_global_css returns the block content');

    $update = S::ok(S::call('set_global_css', ['operation' => 'upsert_block', 'name' => 'pe-test', 'css' => ".pe-test-block {\n  background: #fafafa;\n}"]), 'update the block with a background and no color');
    S::check(str_contains(implode(' ', (array) ($update['warnings'] ?? [])), 'background without a text color'), 'the background-without-color warning fires', (string) wp_json_encode($update['warnings'] ?? null));
    S::check(($update['changed'] ?? null) === true && ($update['bytes_after'] ?? 0) < ($update['bytes_before'] ?? 0), 'the update reports the sizes');
    S::check($outside() === $outsideBefore, 'CSS outside the managed blocks is unchanged after the update');

    $remove = S::ok(S::call('set_global_css', ['operation' => 'remove_block', 'name' => 'pe-test']), 'remove the block');
    S::check(($remove['changed'] ?? null) === true && ! in_array('pe-test', $blockNames(), true), 'the pe-test block is gone');
    S::check($outside() === $outsideBefore, 'CSS outside the managed blocks is unchanged after the removal');
} finally {
    if ($cssBackup !== null) {
        $restore = S::call('restore_settings', ['key' => 'global_css', 'backup_id' => $cssBackup]);
        S::check(! $restore['is_error'] && ($restore['data']['restored'] ?? null) === true, 'restore_settings put the pre-test Global CSS back', substr($restore['text'], 0, 300));
    }

    $cssAfter = S::rawOption($cssKey);
    S::check($cssAfter === $cssBefore, $cssBefore['exists'] ? 'Global CSS is byte-identical to before' : 'the Global CSS option is absent again', (string) wp_json_encode($cssAfter));

    if ($cssAfter !== $cssBefore) {
        S::attention("The Global CSS option {$cssKey} differs from its state before test 7. Check restore_settings for key \"global_css\".");
    }
}

// 8. upload_media ----------------------------------------------------------------

S::section('8 upload_media');

$imageUrl = (string) ($suiteOptions['image_url'] ?? 'https://s.w.org/style/images/about/WordPress-logotype-wmark.png');
$trackMedia = static function (array $result, string $title): void {
    foreach ((array) ($result['items'] ?? []) as $item) {
        if (($item['ok'] ?? false) === true && is_int($item['attachment_id'] ?? null)) {
            S::track('media', $item['attachment_id'], $title);
        }
    }
};
$itemErrors = static fn(array $result): array => array_map(static fn($item): string => (string) ($item['error'] ?? ''), (array) ($result['items'] ?? []));

// An HTTPS image, then the same URL again.
$imageTitle = "PE TEST image {$run}";
$first = S::ok(S::call('upload_media', ['items' => [['source_url' => $imageUrl, 'title' => $imageTitle, 'alt' => 'PE TEST image']]]), 'upload an image from an https URL');
$trackMedia($first, $imageTitle);
$image = $first['items'][0] ?? [];
$imageId = is_int($image['attachment_id'] ?? null) ? $image['attachment_id'] : 0;
S::check(($image['ok'] ?? null) === true && $imageId > 0 && ($image['cs_ref'] ?? null) === $imageId . ':full' && is_string($image['url'] ?? null), 'it returns the attachment, URL and cs_ref', (string) wp_json_encode($first));

if ($imageId > 0) {
    if (($image['deduped'] ?? null) === true) {
        S::skip('image subsizes', 'this image was uploaded by an earlier run');
    } else {
        S::check(($image['mime'] ?? null) === 'image/png' && is_int($image['width'] ?? null), 'it reports the type and size');
        S::check(is_array($image['sizes'] ?? null) && $image['sizes'] !== [], 'WordPress generated image subsizes for the sideloaded file', (string) wp_json_encode($first['warnings'] ?? null));
        S::check(get_post_meta($imageId, '_pe_source_url', true) === $imageUrl && get_post_meta($imageId, '_wp_attachment_image_alt', true) === 'PE TEST image', 'the source URL and alt text are stored');
        S::check(strlen((string) get_post_meta($imageId, '_pe_source_sha1', true)) === 40, 'the file hash is stored');
    }

    $second = S::ok(S::call('upload_media', ['items' => [['source_url' => $imageUrl, 'title' => $imageTitle, 'alt' => 'PE TEST image']]]), 'upload the same URL again');
    S::check(($second['items'][0]['deduped'] ?? null) === true && ($second['items'][0]['attachment_id'] ?? null) === $imageId, 'the second upload is deduped to the same attachment', (string) wp_json_encode($second));
}

// A base64 PNG (random color, so each run uploads a new file).
$png = null;

if (function_exists('imagecreatetruecolor')) {
    $canvas = imagecreatetruecolor(64, 48);

    if ($canvas !== false) {
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagesetpixel($canvas, random_int(0, 63), random_int(0, 47), (int) imagecolorallocate($canvas, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
    }
}

$png = $png ?: (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$base64Title = "PE TEST base64 image {$run}";
$base64 = S::ok(S::call('upload_media', ['items' => [[
    'data_base64' => 'data:image/png;base64,' . base64_encode($png),
    'filename'    => "pe-test-{$run}.png",
    'title'       => $base64Title,
    'alt'         => 'PE TEST base64 image',
    'caption'     => 'PE TEST caption',
]]]), 'upload a base64 PNG');
$trackMedia($base64, $base64Title);
S::check(($base64['items'][0]['ok'] ?? null) === true && ($base64['items'][0]['mime'] ?? null) === 'image/png', 'the base64 PNG was added', (string) wp_json_encode($base64));

if (is_int($base64['items'][0]['attachment_id'] ?? null)) {
    S::check(get_post_field('post_excerpt', $base64['items'][0]['attachment_id']) === 'PE TEST caption', 'the caption is stored');
}

// Over the size cap (lowered for this call only).
$smallCap = static fn(): int => 1024;
add_filter('pe_upload_media_max_bytes', $smallCap);

try {
    $big = S::ok(S::call('upload_media', ['items' => [['source_url' => $imageUrl, 'alt' => 'PE TEST image']]]), 'upload a file over the size cap');
} finally {
    remove_filter('pe_upload_media_max_bytes', $smallCap);
}

S::check(($big['items'][0]['ok'] ?? null) === false && str_contains((string) ($big['items'][0]['error'] ?? ''), 'larger than') && ($big['error_count'] ?? null) === 1, 'a file over the cap is refused', (string) wp_json_encode($big));

$tooLarge = str_repeat('A', (int) (ceil(524288 / 3) * 4) + 4);
$refused = S::ok(S::call('upload_media', ['items' => [
    ['data_base64' => base64_encode('<?php echo 1;'), 'filename' => 'pe-test.php'],
    ['data_base64' => base64_encode('PE TEST plain text'), 'filename' => 'pe-test.txt'],
    ['data_base64' => base64_encode('PE TEST not an image ' . $run), 'filename' => "pe-test-fake-{$run}.png", 'alt' => 'PE TEST fake'],
    ['source_url' => 'http://example.com/pe-test.png', 'alt' => 'PE TEST'],
    ['source_url' => $imageUrl, 'data_base64' => base64_encode($png), 'filename' => 'pe-test-both.png', 'alt' => 'PE TEST'],
    ['data_base64' => base64_encode($png), 'filename' => 'pe-test-no-alt.png'],
    ['data_base64' => base64_encode($png), 'filename' => 'pe-test-extra.png', 'alt' => 'PE TEST', 'bogus' => 1],
    ['data_base64' => $tooLarge, 'filename' => 'pe-test-large.png', 'alt' => 'PE TEST'],
    ['data_base64' => '%%% not base64 %%%', 'filename' => 'pe-test-garbage.png', 'alt' => 'PE TEST'],
]]), 'upload files that are not allowed');
$errors = $itemErrors($refused);
$expected = ['not allowed', 'not allowed', 'do not match', 'https', 'exactly one', 'alt text', 'bogus', 'larger than 512 KB', 'not valid base64'];

foreach ($expected as $i => $needle) {
    S::check(($refused['items'][$i]['ok'] ?? null) === false && str_contains($errors[$i] ?? '', $needle), "refused item {$i}: {$needle}", $errors[$i] ?? 'missing');
}

S::check(($refused['ok_count'] ?? null) === 0 && ($refused['error_count'] ?? null) === count($expected), 'nothing from the refused batch was added');
S::isError(S::call('upload_media', ['items' => array_fill(0, 11, ['source_url' => $imageUrl, 'alt' => 'PE TEST'])]), 'more than 10 items is an error', 'at most 10');

// SVG: refused while the opt-in is off, accepted while it is on (filters only;
// the site option is not touched).
$benignSvg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="PE TEST">'
    . "<title>PE TEST {$run}</title>"
    . '<defs><linearGradient id="peg"><stop offset="0" stop-color="#123456"/><stop offset="1" stop-color="#654321"/></linearGradient>'
    . '<symbol id="pes" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="url(#peg)"/></symbol></defs>'
    . '<use href="#pes" width="24" height="24"/></svg>';
$svgItem = static fn(string $svg, string $name): array => ['data_base64' => base64_encode($svg), 'filename' => $name, 'alt' => 'PE TEST svg', 'title' => "PE TEST svg {$run}"];

$svgOff = static fn(): bool => false;
add_filter('pe_allow_svg_uploads', $svgOff, 999);

try {
    $off = S::ok(S::call('upload_media', ['items' => [$svgItem($benignSvg, "pe-test-{$run}.svg")]]), 'upload an SVG while SVG uploads are off');
} finally {
    remove_filter('pe_allow_svg_uploads', $svgOff, 999);
}

S::check(($off['items'][0]['ok'] ?? null) === false && str_contains((string) ($off['items'][0]['error'] ?? ''), 'turned off'), 'the SVG is refused while the opt-in is off', (string) wp_json_encode($off));

$ns = 'xmlns="http://www.w3.org/2000/svg"';
$xl = 'xmlns:xlink="http://www.w3.org/1999/xlink"';
$maliciousSvg = [
    'script'          => "<svg {$ns}><script>alert(1)</script></svg>",
    'onload'          => "<svg {$ns} onload=\"alert(1)\"><rect width=\"1\" height=\"1\"/></svg>",
    'style element'   => "<svg {$ns}><style>rect { fill: red; }</style><rect width=\"1\" height=\"1\"/></svg>",
    'style attribute' => "<svg {$ns}><rect width=\"1\" height=\"1\" style=\"fill: red\"/></svg>",
    'a'               => "<svg {$ns} {$xl}><a xlink:href=\"https://example.com/\"><rect width=\"1\" height=\"1\"/></a></svg>",
    'animate'         => "<svg {$ns}><rect width=\"1\" height=\"1\"><animate attributeName=\"x\" to=\"10\" dur=\"1s\"/></rect></svg>",
    'set'             => "<svg {$ns}><rect width=\"1\" height=\"1\"><set attributeName=\"x\" to=\"10\"/></rect></svg>",
    'foreignObject'   => "<svg {$ns}><foreignObject width=\"10\" height=\"10\"><div xmlns=\"http://www.w3.org/1999/xhtml\">x</div></foreignObject></svg>",
    'external use'    => "<svg {$ns} {$xl}><use xlink:href=\"https://example.com/sprite.svg#icon\"/></svg>",
    'doctype/entity'  => "<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n<svg {$ns}><title>&xxe;</title></svg>",
    'xml:base'        => "<svg {$ns} xml:base=\"https://example.com/\"><rect width=\"1\" height=\"1\"/></svg>",
    'javascript href' => "<svg {$ns} {$xl}><use xlink:href=\"javascript:alert(1)\"/></svg>",
    'data href'       => "<svg {$ns}><use href=\"data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=\"/></svg>",
    'xinclude'        => "<svg {$ns} xmlns:xi=\"http://www.w3.org/2001/XInclude\"><xi:include href=\"file:///etc/passwd\" parse=\"text\"/></svg>",
    'php'             => "<svg {$ns}><?php echo 1; ?><rect width=\"1\" height=\"1\"/></svg>",
    'image'           => "<svg {$ns}><image href=\"https://example.com/x.png\" width=\"1\" height=\"1\"/></svg>",
];

$svgOn = static fn(): bool => true;
add_filter('pe_allow_svg_uploads', $svgOn, 999);

try {
    $svgTitle = "PE TEST svg {$run}";
    $on = S::ok(S::call('upload_media', ['items' => [$svgItem($benignSvg, "pe-test-{$run}.svg")]]), 'upload a benign SVG while SVG uploads are on');
    $trackMedia($on, $svgTitle);
    S::check(($on['items'][0]['ok'] ?? null) === true && ($on['items'][0]['mime'] ?? null) === 'image/svg+xml', 'the benign SVG is accepted', (string) wp_json_encode($on));

    if (is_int($on['items'][0]['attachment_id'] ?? null)) {
        $storedSvg = (string) file_get_contents((string) get_attached_file($on['items'][0]['attachment_id']));
        S::check(str_contains($storedSvg, "PE TEST {$run}") && str_contains($storedSvg, '<use href="#pes"'), 'the stored SVG is the validated document');
    }

    foreach (array_chunk($maliciousSvg, 10, true) as $chunk) {
        $items = [];
        $n = 0;

        foreach ($chunk as $label => $svg) {
            $items[] = $svgItem($svg, 'pe-test-bad-' . ($n++) . "-{$run}.svg");
        }

        $result = S::ok(S::call('upload_media', ['items' => $items]), 'upload ' . count($items) . ' malicious SVGs while SVG uploads are on');
        $trackMedia($result, 'PE TEST unexpected SVG ' . $run);
        $n = 0;

        foreach (array_keys($chunk) as $label) {
            $item = $result['items'][$n++] ?? [];
            S::check(($item['ok'] ?? null) === false && str_starts_with((string) ($item['error'] ?? ''), 'SVG refused'), "SVG with {$label} is refused", (string) ($item['error'] ?? 'missing'));
        }
    }
} finally {
    remove_filter('pe_allow_svg_uploads', $svgOn, 999);
}

S::check(has_filter('pe_allow_svg_uploads', $svgOn) === false && has_filter('pe_allow_svg_uploads', $svgOff) === false, 'the test\'s SVG filters are removed again');

// 17. wp pe layout import --------------------------------------------------------

S::section('17 wp pe layout import');

$userId = get_current_user_id();
$importFiles = [];

if ($lockPageId <= 0) {
    S::skip('layout import checks', 'the PE TEST page from test 12 is missing');
} elseif (! function_exists('proc_open')) {
    S::skip('layout import checks', 'proc_open is not available');
} else {
    $writeImport = static function (string $contents) use (&$importFiles): string {
        $path = wp_tempnam('pe-smoke-import.json');
        file_put_contents($path, $contents);
        $importFiles[] = $path;

        return $path;
    };

    $before = S::call('get_layout', ['post_id' => $lockPageId])['data']['checksum'] ?? null;
    $valid = $writeImport((string) wp_json_encode(['post_id' => $lockPageId, 'data' => $simplePage]));
    $invalidLayout = $writeImport((string) wp_json_encode(['post_id' => $lockPageId, 'data' => $invalidPage]));
    $notJson = $writeImport('{"post_id": ' . $lockPageId . ', "data": [');

    $noUser = S::cli('pe layout import ' . escapeshellarg($valid));
    S::check($noUser['code'] !== 0 && str_contains($noUser['output'], '--user'), 'the import refuses to run without --user', trim($noUser['output']));

    $invalid = S::cli('pe layout import ' . escapeshellarg($invalidLayout) . ' --user=' . $userId);
    S::check($invalid['code'] !== 0 && str_contains($invalid['output'], 'failed validation'), 'the import rejects a layout that fails validation', trim($invalid['output']));

    $broken = S::cli('pe layout import ' . escapeshellarg($notJson) . ' --user=' . $userId);
    S::check($broken['code'] !== 0 && str_contains($broken['output'], 'Invalid JSON'), 'the import rejects a file that is not JSON', trim($broken['output']));

    wp_cache_delete($lockPageId, 'post_meta');
    S::check((S::call('get_layout', ['post_id' => $lockPageId])['data']['checksum'] ?? null) === $before, 'the refused imports wrote nothing');

    $mcpNoUser = S::cli('pe mcp call deploy_layout --args=' . escapeshellarg((string) wp_json_encode(['post_id' => $lockPageId, 'layout_data' => $simplePage])));
    S::check($mcpNoUser['code'] !== 0 && str_contains($mcpNoUser['output'], '--user'), 'wp pe mcp call refuses a write tool without --user', trim($mcpNoUser['output']));

    $mcpError = S::cli('pe mcp call get_layout --args=' . escapeshellarg('{"post_id": 987654321}') . ' --user=' . $userId);
    S::check($mcpError['code'] !== 0 && str_contains($mcpError['output'], 'does not exist'), 'wp pe mcp call prints an isError result and exits non-zero', trim($mcpError['output']));

    $doctor = S::cli('pe doctor --format=json --user=' . $userId);
    $doctorJson = json_decode(trim($doctor['stdout']), true);
    S::check(is_array($doctorJson['checks'] ?? null) && $doctorJson['checks'] !== [] && ($doctorJson['report']['cornerstone_available'] ?? null) === true, 'wp pe doctor --format=json prints only the JSON report', trim(substr($doctor['output'], 0, 300)));
    S::check($doctor['code'] === 0, 'wp pe doctor reports no failures on this site', (string) wp_json_encode(array_values(array_filter((array) ($doctorJson['checks'] ?? []), static fn($c): bool => ($c['status'] ?? '') === 'fail'))));

    $doctorNoUser = S::cli('pe doctor');
    S::check($doctorNoUser['code'] !== 0 && str_contains($doctorNoUser['output'], '--user'), 'wp pe doctor needs --user', trim($doctorNoUser['output']));
}

foreach ($importFiles as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}

// 19. Remaining tool changes -----------------------------------------------------

S::section('19 remaining tool changes');

$includes = ['tss', 'generated_styles', 'components', 'assignments', 'host'];
$cleared = S::ok(S::call('clear_cache', ['include' => $includes]), 'clear_cache with every include');
$reported = array_merge((array) ($cleared['ran'] ?? []), (array) ($cleared['unavailable'] ?? []));

foreach ($includes as $include) {
    S::check((bool) array_filter($reported, static fn($line): bool => str_starts_with((string) $line, $include)), "clear_cache reports {$include}", (string) wp_json_encode($reported));
}

S::check(($cleared['scope'] ?? null) === 'global' && array_key_exists('entries_cleared', $cleared) && ($cleared['cleared'] ?? null) === true, 'clear_cache keeps cleared, scope and entries_cleared');

$defaultClear = S::ok(S::call('clear_cache'), 'clear_cache with no arguments');
S::check(count((array) ($defaultClear['ran'] ?? [])) === 2 && str_starts_with((string) ($defaultClear['ran'][1] ?? ''), 'generated_styles'), 'without post_id it clears tss and generated styles', (string) wp_json_encode($defaultClear));

if ($lockPageId > 0) {
    $postClear = S::ok(S::call('clear_cache', ['post_id' => $lockPageId]), 'clear_cache for one post');
    S::check(($postClear['scope'] ?? null) === 'post' && ($postClear['ran'] ?? null) === ['tss: post ' . $lockPageId], 'with post_id it clears only that post\'s tss', (string) wp_json_encode($postClear));
}

S::isError(S::call('clear_cache', ['include' => ['everything']]), 'an unknown include is rejected', 'everything');

$menus = S::ok(S::call('list_menus', ['include_items' => true]), 'list_menus');
S::check(is_array($menus['menus'] ?? null) && ($menus['count'] ?? -1) === count($menus['menus']), 'list_menus returns an array', (string) wp_json_encode($menus));

foreach ((array) ($menus['menus'] ?? []) as $menu) {
    S::check(($menu['cs_ref'] ?? null) === 'menu:' . ($menu['id'] ?? '') && is_array($menu['items'] ?? null), "menu {$menu['id']} has a cs_ref and its items");
}

$info = S::ok(S::call('get_site_info'), 'get_site_info');
$health = (array) ($info['health'] ?? []);
S::check(($info['pro_extended']['version'] ?? null) === '1.1.0', 'get_site_info reports 1.1.0');

foreach (['cornerstone_available', 'permalinks', 'application_passwords_in_use', 'blog_public', 'breakpoint_ranges_saved', 'current_user', 'global_css_key', 'cornerstone_adapter', 'component_registry', 'host_cache', 'settings', 'environment_type'] as $key) {
    S::check(array_key_exists($key, $health), "the health block has {$key}");
}

S::check(($health['current_user']['unfiltered_html'] ?? null) === true, 'the test user has unfiltered_html');
S::check(($health['settings']['pe_allow_svg_uploads'] ?? null) === (\ProExtended\Media\MediaImporter::svgAllowed() ? '1' : '0'), 'the health block reports the effective SVG setting', (string) wp_json_encode($health['settings'] ?? null));

$listed = S::ok(S::call('list_layouts', ['type' => 'cs_global_block']), 'list_layouts for component documents');
$rows = [];

foreach ((array) ($listed['layouts'] ?? []) as $row) {
    $rows[(int) ($row['id'] ?? 0)] = $row;
}

if (isset($renameId) && $renameId > 0) {
    $row = $rows[$renameId] ?? [];
    S::check(($row['doc_type'] ?? null) === 'custom:component' && ($row['format'] ?? null) === 'component' && ($row['component_count'] ?? null) === 1 && ($row['library_group'] ?? null) === 'PE Test' && ($row['document_visibility'] ?? null) === '', 'list_layouts reports the component fields', (string) wp_json_encode($row));
}

$all = S::ok(S::call('list_layouts'), 'list_layouts');
S::check(array_filter((array) ($all['layouts'] ?? []), static fn($row): bool => ! array_key_exists('doc_type', (array) $row)) === [], 'every row has a doc_type');

$backups = S::ok(S::call('list_settings_backups'), 'list_settings_backups');
S::check(is_array($backups['backups'] ?? null), 'list_settings_backups returns a list');

// 20. Page saves through Cornerstone ------------------------------------------------

S::section('20 page saves through Cornerstone');

$hookCounts = [];
$countHooks = ['cs_save_document', 'cornerstone_before_save_content', 'cornerstone_after_save_content'];

foreach ($countHooks as $hook) {
    add_action($hook, static function () use (&$hookCounts, $hook): void {
        $hookCounts[$hook] = ($hookCounts[$hook] ?? 0) + 1;
    });
}

$savePageTitle = "PE TEST Page save \\ {$run}";
$saveLayout = $pageLayout([['_type' => 'text', 'text_content' => "PE-TEST-SAVE-A-{$run}"]]);
$savePage = S::ok(S::call('create_page', ['title' => $savePageTitle, 'layout_data' => $saveLayout]), 'create a page with layout data');
$savePageId = (int) ($savePage['post_id'] ?? 0);

if ($savePageId > 0) {
    S::track('page', $savePageId, $savePageTitle);
    S::check(($savePage['write_path'] ?? null) === 'cornerstone-api', 'create_page wrote through cornerstone-api', (string) wp_json_encode($savePage));
    clean_post_cache($savePageId);
    S::check(S::postField($savePageId, 'post_title') === $savePageTitle, 'a backslash in the title survives the save', (string) S::postField($savePageId, 'post_title'));

    update_post_meta($savePageId, '_cornerstone_override', '1');
    delete_post_meta($savePageId, '_cs_last_save');
    $hookCounts = [];
    $saveLayout[0]['_modules'][0]['_modules'][0]['_modules'][0]['text_content'] = "PE-TEST-SAVE-B-{$run}";
    $saved = S::ok(S::call('deploy_layout', ['post_id' => $savePageId, 'layout_data' => $saveLayout]), 'deploy to the page');
    wp_cache_delete($savePageId, 'post_meta');
    clean_post_cache($savePageId);

    S::check(($saved['write_path'] ?? null) === 'cornerstone-api', 'deploy_layout wrote through cornerstone-api', (string) wp_json_encode($saved));
    S::check(get_post_meta($savePageId, '_cornerstone_override', true) === '', 'the _cornerstone_override flag is removed');
    $fired = [];

    foreach ($countHooks as $hook) {
        $fired[$hook] = $hookCounts[$hook] ?? 0;
    }

    S::same(array_fill_keys($countHooks, 1), $fired, 'the save hooks fire once each');
    S::check(get_post_meta($savePageId, '_cs_last_save', true) !== '', '_cs_last_save is set');
    S::check(get_post_meta($savePageId, '_wp_page_template', true) === 'template-blank-4.php', 'the page keeps the blank template');
    S::same($saveLayout, S::layout($savePageId), 'the page returns the deployed layout');

    $content = (string) S::postField($savePageId, 'post_content');
    $htmlMode = (bool) get_option('cs_document_build_as_html', true);

    if ($htmlMode) {
        S::check(str_starts_with($content, '<!-- cs-content -->') && str_contains($content, "PE-TEST-SAVE-B-{$run}") && ! str_contains($content, "PE-TEST-SAVE-A-{$run}"), 'post_content holds the new rendered HTML (HTML storage)', substr($content, 0, 120));
    } else {
        S::check(str_starts_with($content, "[cs_content _p='{$savePageId}']") && str_contains($content, "PE-TEST-SAVE-B-{$run}"), 'post_content holds the new shortcodes (shortcode storage)', substr($content, 0, 120));
    }

    $settings = json_decode((string) get_post_meta($savePageId, '_cornerstone_settings', true), true);
    S::check(is_array($settings) && ($settings['layoutSingle'] ?? null) === 'default' && array_key_exists('responsive_text', $settings), 'the page has Cornerstone settings', (string) wp_json_encode($settings));

    $patched = S::ok(S::call('update_layout', [
        'post_id'    => $savePageId,
        'operations' => [['op' => 'update', 'path' => '0._modules.0._modules.0._modules.0', 'value' => ['text_content' => "PE-TEST-SAVE-C-{$run}"]]],
    ]), 'update_layout on the page');
    S::check(($patched['write_path'] ?? null) === 'cornerstone-api' && str_contains((string) S::postField($savePageId, 'post_content'), "PE-TEST-SAVE-C-{$run}"), 'update_layout wrote through cornerstone-api and rebuilt post_content', (string) wp_json_encode($patched));

    $restored = S::ok(S::call('restore_layout', ['post_id' => $savePageId, 'backup_id' => (string) ($saved['backup_id'] ?? '')]), 'restore the page from its first backup');
    clean_post_cache($savePageId);
    $content = (string) S::postField($savePageId, 'post_content');
    S::check(($restored['write_path'] ?? null) === 'cornerstone-api' && str_contains($content, "PE-TEST-SAVE-A-{$run}") && ! str_contains($content, "PE-TEST-SAVE-C-{$run}"), 'restore_layout rebuilt post_content from the restored data', (string) wp_json_encode($restored));

    add_filter('pe_force_fallback', $forceFallback);
    update_post_meta($savePageId, '_cornerstone_override', '1');
    $saveLayout[0]['_modules'][0]['_modules'][0]['_modules'][0]['text_content'] = "PE-TEST-SAVE-D-{$run}";
    $fallback = S::ok(S::call('deploy_layout', ['post_id' => $savePageId, 'layout_data' => $saveLayout]), 'deploy to the page with pe_force_fallback on');
    remove_filter('pe_force_fallback', $forceFallback);
    wp_cache_delete($savePageId, 'post_meta');
    clean_post_cache($savePageId);
    S::check(($fallback['write_path'] ?? null) === 'fallback', 'the fallback path was used', (string) wp_json_encode($fallback));
    S::check(get_post_meta($savePageId, '_cornerstone_override', true) === '' && str_contains((string) S::postField($savePageId, 'post_content'), "PE-TEST-SAVE-D-{$run}"), 'the fallback also clears the override and renders the page');
    S::same($saveLayout, S::layout($savePageId), 'the fallback stores the layout');

    if ($localSite) {
        // Shortcode storage, for this request only (nothing is stored).
        $shortcodes = static fn(): string => '0';
        add_filter('pre_option_cs_document_build_as_html', $shortcodes);
        $saveLayout[0]['_modules'][0]['_modules'][0]['_modules'][0]['text_content'] = "PE-TEST-SAVE-E-{$run}";
        $short = S::ok(S::call('deploy_layout', ['post_id' => $savePageId, 'layout_data' => $saveLayout]), 'deploy to the page with shortcode storage (local site)');
        remove_filter('pre_option_cs_document_build_as_html', $shortcodes);
        clean_post_cache($savePageId);
        $content = (string) S::postField($savePageId, 'post_content');
        S::check(($short['write_path'] ?? null) === 'cornerstone-api' && str_starts_with($content, "[cs_content _p='{$savePageId}']") && str_ends_with($content, '[/cs_content]') && ! str_contains($content, '<!-- cs-content -->'), 'post_content holds [cs_content] shortcodes', substr($content, 0, 120));
        S::check(str_contains($content, "PE-TEST-SAVE-E-{$run}"), 'the shortcodes carry the new content');

        S::ok(S::call('deploy_layout', ['post_id' => $savePageId, 'layout_data' => $saveLayout]), 'deploy again with HTML storage');
        clean_post_cache($savePageId);
        S::check(str_starts_with((string) S::postField($savePageId, 'post_content'), '<!-- cs-content -->'), 'post_content is HTML again');
    } else {
        S::skip('shortcode storage', 'runs only with local=1 on a local site');
    }

    $post = get_post($savePageId);
    S::check($post instanceof WP_Post && $post->post_status === 'draft' && $post->post_title === $savePageTitle, 'the page stays a draft with its title');
}

$legacyTitle = "PE TEST Page tabs {$run}";
$tabsLayout = $pageLayout([['_type' => 'tabs', '_modules' => [['_type' => 'tab', 'tab_label_content' => 'PE TEST tab', 'tab_content' => 'PE TEST tab content']]]]);
$tabsPage = S::ok(S::call('create_page', ['title' => $legacyTitle, 'layout_data' => $tabsLayout]), 'create a page with tabs');

if (isset($tabsPage['post_id'])) {
    S::track('page', (int) $tabsPage['post_id'], $legacyTitle);
    $storedTabs = S::layout((int) $tabsPage['post_id']);
    $storedTab = $storedTabs[0]['_modules'][0]['_modules'][0]['_modules'][0]['_modules'][0] ?? [];
    S::check(($storedTab['tab_label_content'] ?? null) === 'PE TEST tab', 'tabs keep their content through the save', (string) wp_json_encode($storedTab));
}

// 21. Migration and breakpoint stamps --------------------------------------------

S::section('21 migration and breakpoint stamps');

$elementContext = new \ProExtended\Cornerstone\ElementContext(pro_extended()->schemaExtractor());
$siteTag = $elementContext->breakpointTag();
S::check((bool) preg_match('/^\d+_\d+$/', $siteTag), 'the site has a breakpoint tag', $siteTag);
S::check(($elementContext->migrationVersions()['bar'] ?? 0) >= 1 && ($elementContext->migrationVersions()['section'] ?? 0) >= 2, 'migration versions come from the registry', (string) wp_json_encode($elementContext->migrationVersions()));

// The elements Cornerstone works with after loading a document (its migrations run on load).
$migrated = static function (int $id): array {
    clean_post_cache($id);
    wp_cache_delete($id, 'post_meta');
    $class = '\\Themeco\\Cornerstone\\Documents\\Document';
    $doc = $class::locate($id);

    return is_object($doc) ? (array) ($doc->data()['elements'] ?? []) : [];
};
$barDefault = (string) (cs_get_element('bar')->get_aggregated_values()['bar_height'][0] ?? '');
$effectiveBarHeight = static fn(array $bar): string => (string) ($bar['bar_height'] ?? $barDefault);

$navHeader = [
    'settings' => ['assignments' => [], 'assignment_priority' => 0],
    'regions'  => [
        'top'    => [[
            '_type'    => 'bar',
            '_region'  => 'top',
            '_modules' => [[
                '_type'    => 'container',
                '_region'  => 'top',
                '_modules' => [
                    ['_type' => 'text', '_region' => 'top', 'text_content' => "PE-TEST-STAMP-{$run}"],
                    ['_type' => 'nav-collapsed', '_region' => 'top'],
                ],
            ]],
        ]],
        'right'  => [],
        'bottom' => [],
        'left'   => [],
    ],
];
$stampTitle = "PE TEST Stamped header {$run}";
$stampDoc = S::ok(S::call('create_document', ['type' => 'header', 'title' => $stampTitle, 'layout_data' => $navHeader]), 'create a header with a bar and a collapsed nav');
$stampDocId = (int) ($stampDoc['document_id'] ?? 0);

if ($stampDocId > 0) {
    S::track('document', $stampDocId, $stampTitle);
    S::check(($stampDoc['stamped']['_m'] ?? 0) === 4 && ($stampDoc['stamped']['_bp_base'] ?? 0) === 4, 'create_document reports the stamps', (string) wp_json_encode($stampDoc['stamped'] ?? null));

    $stored = S::layout($stampDocId)['regions']['top'][0] ?? [];
    S::check(($stored['_m'] ?? null) === ['e' => 1] && ($stored['_bp_base'] ?? null) === $siteTag, 'the stored bar has _m and the site tag', (string) wp_json_encode(array_intersect_key($stored, array_flip(['_m', '_bp_base']))));

    $top = $migrated($stampDocId)['top'][0] ?? [];
    $nav = $top['_modules'][0]['_modules'][1] ?? [];
    S::check($effectiveBarHeight($top) === '100px' && $barDefault === '100px', 'a stamped bar keeps its 100px height after Cornerstone loads it', $effectiveBarHeight($top));
    S::check(empty($nav['legacy_region_detect']), 'a stamped nav in a header bar stays inline', (string) wp_json_encode($nav['legacy_region_detect'] ?? null));

    $legacyDeploy = S::ok(S::call('deploy_layout', ['post_id' => $stampDocId, 'layout_data' => $navHeader]), 'deploy the unmarked header without stamp_new');
    S::check((bool) array_filter((array) ($legacyDeploy['warnings'] ?? []), static fn($w): bool => str_contains((string) $w, 'stamp_new')), 'deploy_layout warns about the missing markers', (string) wp_json_encode($legacyDeploy['warnings'] ?? null));
    S::check(S::isNull($legacyDeploy, 'stamped'), 'nothing was stamped', (string) wp_json_encode($legacyDeploy));
    $top = $migrated($stampDocId)['top'][0] ?? [];
    $nav = $top['_modules'][0]['_modules'][1] ?? [];
    S::check($effectiveBarHeight($top) === '6em' && ! empty($nav['legacy_region_detect']), 'unmarked, the bar gets the legacy 6em height and the nav turns into a toggle (why stamps matter)', $effectiveBarHeight($top));

    $stampDeploy = S::ok(S::call('deploy_layout', ['post_id' => $stampDocId, 'layout_data' => $navHeader, 'stamp_new' => true]), 'deploy it again with stamp_new: true');
    S::check(($stampDeploy['stamped']['_m'] ?? 0) === 4, 'deploy_layout reports the stamps', (string) wp_json_encode($stampDeploy['stamped'] ?? null));
    S::check(! array_filter((array) ($stampDeploy['warnings'] ?? []), static fn($w): bool => str_contains((string) $w, 'stamp_new')), 'no marker warning with stamp_new');
    S::check($effectiveBarHeight($migrated($stampDocId)['top'][0] ?? []) === '100px', 'the bar is 100px again');
}

$grid = static fn(array $extra = []): array => [array_merge([
    '_type'    => 'section',
    '_modules' => [array_merge([
        '_type'                        => 'layout-grid',
        'layout_grid_template_columns' => '1fr 1fr 1fr',
        '_modules'                     => [
            ['_type' => 'layout-cell', '_modules' => [['_type' => 'text', 'text_content' => "PE-TEST-GRID-{$run}"]]],
        ],
    ], $extra)],
])];

$gridTitle = "PE TEST Stamped grid {$run}";
$gridPage = S::ok(S::call('create_page', ['title' => $gridTitle, 'layout_data' => $grid()]), 'create a page with a three-column grid');
$gridPageId = (int) ($gridPage['post_id'] ?? 0);

if ($gridPageId > 0) {
    S::track('page', $gridPageId, $gridTitle);
    S::same(['elements' => 4, '_m' => 4, '_bp_base' => 4], $gridPage['stamped'] ?? null, 'create_page reports the stamps');
    $loadedGrid = $migrated($gridPageId)[0]['_modules'][0] ?? [];
    S::check(($loadedGrid['layout_grid_template_columns'] ?? null) === '1fr 1fr 1fr' && ! isset($loadedGrid['_bp_data' . $siteTag]), 'a stamped grid keeps its columns after Cornerstone loads it', (string) wp_json_encode($loadedGrid));

    $added = S::ok(S::call('update_layout', [
        'post_id'    => $gridPageId,
        'operations' => [
            ['op' => 'add', 'path' => '0._modules.0._modules.1', 'value' => ['_type' => 'layout-cell', '_modules' => [['_type' => 'text', 'text_content' => 'PE TEST added']]]],
            ['op' => 'update', 'path' => '0', 'value' => ['_label' => 'PE Test Grid Section']],
        ],
    ]), 'add a cell with update_layout');
    $storedGrid = S::layout($gridPageId)[0]['_modules'][0] ?? [];
    $cell = $storedGrid['_modules'][1] ?? [];
    S::check(($cell['_m'] ?? null) === ['e' => 1] && ($cell['_bp_base'] ?? null) === $siteTag && ($cell['_modules'][0]['_m'] ?? null) === ['e' => 1], 'the added cell and its text are stamped', (string) wp_json_encode($cell));
    S::same(['elements' => 2, '_m' => 2, '_bp_base' => 2], $added['stamped'] ?? null, 'update_layout reports the stamps of the added elements only');
}

$plainTitle = "PE TEST Unstamped grid {$run}";
$plainPage = S::ok(S::call('create_page', ['title' => $plainTitle, 'layout_data' => $grid(), 'stamp_new' => false]), 'create a page with stamp_new: false');

if (isset($plainPage['post_id'])) {
    $plainId = (int) $plainPage['post_id'];
    S::track('page', $plainId, $plainTitle);
    S::same($grid(), S::layout($plainId), 'stamp_new: false stores the layout as sent');
    $loadedGrid = $migrated($plainId)[0]['_modules'][0] ?? [];
    S::check(($loadedGrid['layout_grid_template_columns'] ?? null) !== '1fr 1fr 1fr', 'unmarked, Cornerstone overwrites the grid columns (why stamps matter)', (string) ($loadedGrid['layout_grid_template_columns'] ?? 'null'));
}

$keptPage = S::ok(S::call('create_page', ['title' => "PE TEST Kept markers {$run}", 'layout_data' => $grid(['_m' => ['e' => 1], '_bp_base' => '3_4'])]), 'create a page whose grid already has markers');

if (isset($keptPage['post_id'])) {
    S::track('page', (int) $keptPage['post_id'], "PE TEST Kept markers {$run}");
    $storedGrid = S::layout((int) $keptPage['post_id'])[0]['_modules'][0] ?? [];
    S::check(($storedGrid['_bp_base'] ?? null) === '3_4' && ($storedGrid['_m'] ?? null) === ['e' => 1], 'existing markers are kept', (string) wp_json_encode($storedGrid));
}

// Done -----------------------------------------------------------------------------

S::summary();
