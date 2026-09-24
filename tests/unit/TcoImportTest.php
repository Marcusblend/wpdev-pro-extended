<?php

declare(strict_types=1);

use ProExtended\Templates\TcoManifest;
use ProExtended\Templates\TemplateGateway;

T::group('TCO import plan');

// A manifest the way Cornerstone's exporter writes it (version 3) -----------

$manifest = [
    'version' => 3,
    'tasks'   => [
        ['options', [
            'colors' => [['_id' => 'c1', 'title' => 'Brand'], ['_id' => 'c9', 'title' => 'New']],
            'fonts'  => [['_id' => 'f1', 'family' => 'Inter']],
        ]],
        ['images', [
            'h1' => ['id', 'logo.png'],
            'h2' => ['no-import', 'https://example.test/wp-content/uploads/gone.jpg'],
        ]],
        ['doc', ['type' => 'component', 'strategy' => 'original', 'key' => 'k1', 'file' => 'doc-k1.json']],
        ['doc', ['type' => 'document', 'strategy' => 'replace', 'key' => 'k2', 'file' => 'doc-k2.json']],
        ['doc', ['type' => 'template', 'strategy' => 'original', 'key' => 'k3', 'file' => 'doc-k3.json']],
        ['menu', ['name' => 'Main', 'items' => []]],
        ['terms', ['t1' => ['name' => 'News', 'slug' => 'news', 'description' => '', 'taxonomy' => 'category']]],
    ],
];

$entries = [
    'doc-k1.json' => ['title' => 'Card', 'type' => 'document', 'subType' => 'custom:component', 'sig' => 's1', 'meta' => ['elements' => [], 'settings' => []]],
    'doc-k2.json' => ['title' => 'Main header', 'type' => 'document', 'subType' => 'layout:header', 'sig' => 's2', 'meta' => [
        'regions'  => ['top' => []],
        'settings' => ['assignments' => [['group' => true, 'condition' => 'site:entire-site', 'value' => '']]],
    ]],
    'doc-k3.json' => ['title' => 'Hero block', 'type' => 'element', 'subType' => '__multi__', 'meta' => ['elements' => []]],
];

$site = [
    'element_types'  => ['headline', 'section'],
    'document_types' => ['layout:header', 'layout:footer', 'custom:component', 'content:page'],
    'taxonomies'     => ['category', 'post_tag'],
    'can_upload'     => true,
    'svg_allowed'    => false,
    'color_ids'      => ['c1', 'c2'],
    'font_ids'       => ['f7'],
];

$noCode = static fn (array $content): ?string => null;
$svgOk = static fn (string $bytes): array => ['ok' => true, 'errors' => []];
$archive = ['files' => ['manifest.json', 'doc-k1.json', 'doc-k2.json', 'doc-k3.json', 'img-h1-logo.png'], 'manifest' => $manifest, 'entries' => $entries, 'svg' => []];

$plan = TcoManifest::plan($archive, $site, $noCode, $svgOk);

T::same([], $plan['blocking'], 'a clean export has nothing blocking it');
T::same(3, $plan['version'], 'the manifest version is reported');
T::same(1, count($plan['templates']), 'a "template" task goes to the library');
T::same(['title' => 'Hero block', 'identifier' => 'element|__multi__', 'kind' => 'block', 'replaces' => false], $plan['templates'][0], 'described by title, identifier and kind');
T::same(['custom:component', 'layout:header'], array_column($plan['documents'], 'doc_type'), 'every other task is created as a document, in manifest order');
T::same(1, $plan['documents'][1]['assignments'], 'a header\'s assignments are counted');
T::ok($plan['documents'][1]['replaces'], 'and the replace strategy is reported');
T::same('s2', $plan['documents'][1]['signature'], 'with the signature Cornerstone dedupes by');
T::same(['count' => 2, 'replaces' => ['c1']], $plan['colors'], 'colors are counted and the ones replacing a site color named');
T::same(['count' => 1, 'replaces' => []], $plan['fonts'], 'fonts too');
T::same(1, $plan['images']['bundled'], 'a bundled image is counted');
T::same(['https://example.test/wp-content/uploads/gone.jpg'], $plan['images']['missing'], 'an image the exporter could not bundle is listed');
T::same([['name' => 'News', 'taxonomy' => 'category']], $plan['terms'], 'terms are listed');
T::ok(str_contains(implode(' ', $plan['ignored']), 'Menu "Main"'), 'menus are reported as not imported');

$warnings = implode(' ', $plan['warnings']);
T::ok(str_contains($warnings, '":full"'), 'the missing image is a warning, naming what it imports as');
T::ok(str_contains($warnings, 'c1'), 'a replaced color is a warning');
T::ok(str_contains($warnings, 'live'), 'so is creating documents live');

// What blocks an import ------------------------------------------------------

T::same('manifest.json', TcoManifest::plan(['files' => ['doc-x.json'], 'entries' => []], $site, $noCode, $svgOk)['blocking'][0]['what'] ?? null, 'an archive with no manifest is blocked');

$missingDoc = $archive;
unset($missingDoc['entries']['doc-k2.json']);
T::ok(str_contains((string) (TcoManifest::plan($missingDoc, $site, $noCode, $svgOk)['blocking'][0]['reason'] ?? ''), 'does not hold'), 'a document the manifest names but the archive lacks is blocked');

$unknownType = $archive;
$unknownType['entries']['doc-k2.json']['subType'] = 'layout:single-wc';
$blocked = TcoManifest::plan($unknownType, $site, $noCode, $svgOk)['blocking'];
T::ok(str_contains((string) ($blocked[0]['reason'] ?? ''), 'layout:single-wc'), 'a document type this site does not have is blocked');
T::ok(str_contains((string) ($blocked[0]['what'] ?? ''), 'Main header'), 'naming the entry');

$code = TcoManifest::plan($archive, $site, static fn (array $content): ?string => isset($content['regions']) ? 'Raw Content needs unfiltered_html.' : null, $svgOk)['blocking'];
T::same([['what' => '"Main header" (document|layout:header)', 'reason' => 'Raw Content needs unfiltered_html.']], $code, 'code the user may not write blocks the entry that carries it');

$noUpload = TcoManifest::plan($archive, ['can_upload' => false] + $site, $noCode, $svgOk)['blocking'];
T::ok(str_contains((string) ($noUpload[0]['reason'] ?? ''), 'upload_files'), 'bundled images need upload_files');

$badTaxonomy = $archive;
$badTaxonomy['manifest']['tasks'][6][1]['t1']['taxonomy'] = 'product_cat';
T::ok(str_contains((string) (TcoManifest::plan($badTaxonomy, $site, $noCode, $svgOk)['blocking'][0]['reason'] ?? ''), 'product_cat'), 'a term in a taxonomy this site lacks is blocked');

$withSvg = $archive;
$withSvg['manifest']['tasks'][1][1]['h3'] = ['uri', 'icon.svg'];
$withSvg['files'][] = 'img-h3-icon.svg';
$withSvg['svg'] = ['img-h3-icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>'];

T::ok(str_contains((string) (TcoManifest::plan($withSvg, $site, $noCode, $svgOk)['blocking'][0]['reason'] ?? ''), 'pe_allow_svg_uploads'), 'an SVG is blocked while SVG uploads are off');

$seen = null;
$svgBad = static function (string $bytes) use (&$seen): array {
    $seen = $bytes;

    return ['ok' => false, 'errors' => ['<script> is not allowed.']];
};
$svgBlocked = TcoManifest::plan($withSvg, ['svg_allowed' => true] + $site, $noCode, $svgBad)['blocking'];
T::ok(str_contains((string) ($svgBlocked[0]['reason'] ?? ''), '<script>'), 'and when they are on, an SVG that fails validation is blocked');
T::same($withSvg['svg']['img-h3-icon.svg'], $seen, 'the validator sees the SVG\'s own bytes');
T::same([], TcoManifest::plan($withSvg, ['svg_allowed' => true] + $site, $noCode, $svgOk)['blocking'], 'a valid SVG passes');

T::group('TCO archive limits');

$dir = sys_get_temp_dir() . '/pe-tco-test-' . bin2hex(random_bytes(4));
mkdir($dir);

$zipOf = static function (string $name, array $members) use ($dir): string {
    $path = $dir . '/' . $name . '.tco';
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    foreach ($members as $member => $contents) {
        $zip->addFromString($member, $contents);
    }

    $zip->close();

    return $path;
};

$normal = TemplateGateway::readArchive($zipOf('normal', [
    'manifest.json'  => json_encode($manifest),
    'doc-k1.json'    => json_encode($entries['doc-k1.json']),
    'img-h1-logo.png' => "\x89PNG fake",
    'img-h3-icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'notes.json'     => 'not json',
]));

T::same(3, $normal['manifest']['version'] ?? null, 'the manifest is decoded on its own');
T::same(['doc-k1.json'], array_keys($normal['entries']), 'the documents are decoded, and a member that is not JSON is left out');
T::same(['img-h3-icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>'], $normal['svg'], 'SVG members come back raw for validation');
T::same(5, count($normal['files']), 'every member is listed');
T::ok($normal['bytes'] > 0, 'and the expanded size is counted');

T::throws(static fn () => TemplateGateway::readArchive($zipOf('bomb', [
    'manifest.json'   => '{}',
    'img-h1-big.png'  => str_repeat("\0", TemplateGateway::MAX_ENTRY_BYTES + 1),
])), 'an image member over the per-file limit is refused before anything is imported', 'expands to more than');

T::throws(static fn () => TemplateGateway::readArchive($zipOf('total', array_fill_keys(
    array_map(static fn (int $i): string => "img-h{$i}-part.png", range(1, 5)),
    str_repeat("\0", 7 * 1024 * 1024)
))), 'members that add up past the total are refused', 'expands to more than');

T::throws(static fn () => TemplateGateway::readArchive($dir . '/missing.tco'), 'a file that is not an archive is refused', 'could not be opened');

foreach (glob($dir . '/*') ?: [] as $file) {
    unlink($file);
}

rmdir($dir);
