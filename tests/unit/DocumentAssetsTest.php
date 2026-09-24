<?php

declare(strict_types=1);

use ProExtended\Cornerstone\DocumentAssets;
use ProExtended\Mcp\Tools\UpdateDocumentSettings;
use ProExtended\Settings\ThemeOptionsWriter;

T::group('DocumentAssets');

$scripts = DocumentAssets::scripts([['src' => 'https://cdn.example.com/lib/widget.min.js', 'id' => 'widget', 'defer' => true]]);
T::same([
    'src' => 'https://cdn.example.com/lib/widget.min.js', 'id' => 'widget', 'type' => '', 'deps' => '', 'ver' => '',
    'async' => false, 'defer' => true, 'nomodule' => false, 'in_footer' => true,
], $scripts[0], 'a script item is completed with the builder\'s defaults, in its key order');

$styles = DocumentAssets::styles([['src' => 'https://cdn.example.com/lib/widget.css', 'media' => 'screen and (max-width: 600px)']]);
T::same(['src' => 'https://cdn.example.com/lib/widget.css', 'id' => '', 'rel' => 'stylesheet', 'media' => 'screen and (max-width: 600px)'], $styles[0], 'a style item takes rel "stylesheet" by default');

T::same([], DocumentAssets::scripts([]), 'an empty list clears the assets');
T::same([], DocumentAssets::styles(null), 'as does null');
T::same('module', DocumentAssets::scripts([['src' => 'https://x.example/app.mjs', 'type' => 'module']])[0]['type'], 'a module script is allowed');
T::ok(DocumentAssets::scripts([['src' => 'https://cdn.example.com/npm/pkg@1']]) !== [], 'a URL without an extension is allowed (Cornerstone allows it too)');

$refused = [
    [[['src' => 'http://cdn.example.com/a.js']], 'an http URL'],
    [[['src' => '//cdn.example.com/a.js']], 'a protocol-relative URL'],
    [[['src' => '/wp-content/uploads/a.js']], 'a root-relative URL'],
    [[['src' => 'https://user:pass@cdn.example.com/a.js']], 'a URL with credentials'],
    [[['src' => 'javascript:alert(1)']], 'a javascript: URL'],
    [[['src' => 'https://cdn.example.com/a.js" onload="x']], 'a URL with a quote'],
    [[['src' => 'https://cdn.example.com/a.php']], 'a non-script extension'],
    [[['src' => 'https://cdn.example.com/a.js', 'type' => 'text/babel']], 'an unknown type'],
    [[['src' => 'https://cdn.example.com/a.js', 'defer' => 'yes']], 'a string flag'],
    [[['src' => 'https://cdn.example.com/a.js', 'id' => 'my script']], 'a handle with a space'],
    [[['src' => 'https://cdn.example.com/a.js', 'id' => 'x'], ['src' => 'https://cdn.example.com/b.js', 'id' => 'X']], 'a duplicate handle'],
    [[['src' => 'https://cdn.example.com/a.js', 'deps' => 'jquery; alert(1)']], 'deps that are not handles'],
    [[['src' => 'https://cdn.example.com/a.js', 'content' => 'x()']], 'an unknown key'],
    [[['id' => 'x']], 'a missing src'],
    [['src' => 'https://cdn.example.com/a.js'], 'an object instead of a list'],
    ['https://cdn.example.com/a.js', 'a bare string'],
];

foreach ($refused as [$value, $label]) {
    T::throws(static fn () => DocumentAssets::scripts($value), sprintf('refuses %s', $label));
}

T::throws(static fn () => DocumentAssets::styles([['src' => 'https://cdn.example.com/a.js']]), 'a stylesheet must be CSS', 'not a CSS');
T::throws(static fn () => DocumentAssets::styles([['src' => 'https://cdn.example.com/a.css', 'rel' => 'icon']]), 'refuses an unknown rel', 'rel');
T::throws(static fn () => DocumentAssets::styles([['src' => 'https://cdn.example.com/a.css', 'media' => 'screen" onload="x']]), 'refuses a media value that could break the tag', 'media');
T::throws(static fn () => DocumentAssets::scripts(array_fill(0, DocumentAssets::MAX_ITEMS + 1, ['src' => 'https://cdn.example.com/a.js'])), 'refuses more than the item limit', 'at most');

try {
    DocumentAssets::scripts([['src' => 'http://a.example/a.js'], ['src' => 'https://b.example/b.js', 'async' => 1]]);
} catch (InvalidArgumentException $e) {
    T::ok(str_contains($e->getMessage(), 'customScripts[0].src') && str_contains($e->getMessage(), 'customScripts[1].async'), 'every problem is reported, with its path');
}

T::ok(DocumentAssets::isHttpsUrl('https://cdn.example.com/a.js?ver=2#x'), 'query and fragment are fine');
T::ok(! DocumentAssets::isHttpsUrl('https:///a.js'), 'a URL without a host is not');

T::group('update_document_settings Custom Assets');

$settings = ['customScripts' => [['src' => 'https://cdn.example.com/a.js']], 'customCSS' => '.a{}', 'customStyles' => []];
$assets = UpdateDocumentSettings::takeAssets($settings);
T::same(['customCSS' => '.a{}'], $settings, 'the asset keys are taken out of the settings the document validator sees');
T::same(['customScripts', 'customStyles'], array_keys($assets), 'and returned on their own');
T::same(true, $assets['customScripts'][0]['in_footer'], 'completed with defaults');
$none = ['assignments' => []];
T::same([], UpdateDocumentSettings::takeAssets($none), 'settings without assets have none');
$bad = ['customStyles' => [['src' => 'http://a.example/a.css']]];
T::throws(static function () use ($bad): void { UpdateDocumentSettings::takeAssets($bad); }, 'an http stylesheet is refused before anything is read', 'https');

T::group('Custom Assets theme options');

$registered = ['cs_custom_scripts', 'cs_custom_styles'];
$plan = ThemeOptionsWriter::plan(['cs_custom_scripts' => [['src' => 'https://cdn.example.com/a.js', 'id' => 'a']]], $registered, ['cs_custom_scripts' => []]);
T::same([], $plan['errors'], 'a valid site-wide script list is writable');
T::same(DocumentAssets::SCRIPT_DEFAULTS['in_footer'], $plan['writes'][0]['to'][0]['in_footer'], 'and is written completed');
T::ok(ThemeOptionsWriter::touchesDocumentAssets($plan['writes']), 'the write is flagged for the Custom Assets permission');
T::ok(! ThemeOptionsWriter::touchesDocumentAssets(ThemeOptionsWriter::plan(['x' => 1], ['x'], [])['writes']), 'other writes are not');
T::same(1, count(ThemeOptionsWriter::plan(['cs_custom_styles' => [['src' => 'http://a.example/a.css']]], $registered, [])['errors']), 'an http stylesheet is refused site-wide too');
