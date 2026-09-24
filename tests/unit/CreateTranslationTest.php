<?php

declare(strict_types=1);

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Mcp\Tools\CreateTranslation;

// WordPress's own definitions: wp_insert_post() unslashes what it is given.
if (! function_exists('wp_slash')) {
    function wp_slash(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_map('wp_slash', $value);
        }

        return is_string($value) ? addslashes($value) : $value;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return stripslashes_deep($value);
    }
}

if (! function_exists('stripslashes_deep')) {
    function stripslashes_deep(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('stripslashes_deep', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

T::group('CreateTranslation');

// The copied JSON survives wp_insert_post ------------------------------------

$header = json_encode([
    'settings' => ['customCSS' => '.x { content: "\\201C"; }'],
    'regions'  => ['top' => [[
        '_type'        => 'headline',
        'text_content' => "Say \"hola\" at the café\nsecond line",
    ]]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

T::ok(str_contains($header, '\\"') && str_contains($header, 'é') && str_contains($header, '\\n'), 'the fixture holds an escaped quote, an é and an escaped newline');

$source = (object) ['post_type' => 'cs_header', 'post_excerpt' => 'A \\ backslash', 'menu_order' => 3];
$postarr = DocumentGateway::rawPostarr($header, CreateTranslation::postFields($source, 'Cabecera "principal"', 'draft', 0));
$stored = wp_unslash($postarr);

T::same($header, $stored['post_content'], 'the content WordPress stores is byte-identical to the source');
T::ok(is_array(json_decode($stored['post_content'], true)), 'and still decodes');
T::same("Say \"hola\" at the café\nsecond line", json_decode($stored['post_content'], true)['regions']['top'][0]['text_content'] ?? null, 'with the quote, the é and the newline intact');
T::same('.x { content: "\\201C"; }', json_decode($stored['post_content'], true)['settings']['customCSS'] ?? null, 'and a backslash inside a value intact');
T::same('Cabecera "principal"', $stored['post_title'], 'the title survives too');
T::same('A \\ backslash', $stored['post_excerpt'], 'and so does a backslash in the excerpt');
T::ok(json_decode(stripslashes($header), true) === null, 'the unslashed write this replaces could not be decoded');

// Status comes from `status`, never from the source ---------------------------

T::same('draft', CreateTranslation::postStatus('page', 'draft'), 'a page defaults to a draft');
T::same('publish', CreateTranslation::postStatus('page', 'publish'), 'and publishes when asked');
T::same('draft', CreateTranslation::postStatus('x-portfolio', 'draft'), 'any other post type is a draft too, whatever the source was');
T::same('draft', CreateTranslation::postStatus('cs_header', 'draft'), 'a Cornerstone document stays a draft');
T::same('tco-data', CreateTranslation::postStatus('cs_header', 'publish'), 'and goes live under tco-data when publishing is asked for');
T::same('tco-data', CreateTranslation::postStatus('cs_global_block', 'publish'), 'components too');
T::ok(CreateTranslation::isDocumentType('cs_layout_single') && ! CreateTranslation::isDocumentType('page'), 'cs_* post types are Cornerstone documents');

// Meta ------------------------------------------------------------------------

$keys = CreateTranslation::copiedMetaKeys([
    '_cornerstone_data', '_cornerstone_settings', '_cornerstone_override', '_cornerstone_version',
    '_cs_template_data', '_cs_template_identifier', '_cs_document_scripts', '_cs_document_styles',
    '_cs_generated_styles', '_cs_generated_tss', '_cs_component_map', '_cs_states_cache',
    '_cs_last_save', '_cs_import', '_cs_attachment_import',
    '_wp_page_template', '_thumbnail_id', '_edit_lock', '_yoast_wpseo_title', 'custom_field', 7,
]);

T::same([
    '_cornerstone_data', '_cornerstone_override', '_cornerstone_settings', '_cornerstone_version',
    '_cs_document_scripts', '_cs_document_styles', '_cs_template_data', '_cs_template_identifier',
    '_wp_page_template',
], $keys, 'every Cornerstone key and the page template are copied, sorted');

foreach (CreateTranslation::NOT_COPIED_META as $cache) {
    T::ok(! in_array($cache, $keys, true), sprintf('%s is not copied', $cache));
}

T::ok(! in_array('_thumbnail_id', $keys, true), 'the featured image is mapped separately, not copied raw');
T::ok(! in_array('_edit_lock', $keys, true) && ! in_array('custom_field', $keys, true), 'other plugins\' meta is left to WPML');
T::same(['_cornerstone_data'], CreateTranslation::copiedMetaKeys(['_cornerstone_data', '_cornerstone_data']), 'a key is copied once');

// Terms and images: the translation where WPML has one --------------------------

$translations = [10 => 110, 11 => null, 12 => 0, 13 => 13];
$mapped = CreateTranslation::translateIds([10, 11, 12, 13, 0, -1], static fn (int $id): mixed => $translations[$id] ?? null);

T::same([110, 11, 12, 13], $mapped['ids'], 'a translated term is swapped, the rest keep the source term');
T::same(1, $mapped['translated'], 'and the swaps are counted');
T::same(['ids' => [5], 'translated' => 0], CreateTranslation::translateIds([5], static fn (int $id): mixed => $id), 'a lookup that returns the same id is not a translation');
T::same([20], CreateTranslation::translateIds([10, 11], static fn (int $id): mixed => 20)['ids'], 'two terms that translate to one are set once');
T::same([], CreateTranslation::translateIds([], static fn (int $id): mixed => 1)['ids'], 'no terms, nothing to set');
