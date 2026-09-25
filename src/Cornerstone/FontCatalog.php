<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

use ProExtended\Settings\FontItems;
use ProExtended\Support\Json;

/**
 * The site's global fonts as Cornerstone resolves them, and the exact forms
 * that reference them.
 *
 * Each Font Manager item is resolved through Cornerstone's GlobalFonts
 * service (getDataForFontItem(), the code that renders it), so the stack and
 * the weights fw-normal and fw-bold turn into are the ones the front end
 * prints. That is a read: it never enters builder context. Custom font
 * items are reported with the family their @font-face rule declares and the
 * stack elements get, which is where a comma stack shows up as broken.
 *
 * shape() is pure, so it is unit-tested without a site.
 */
final class FontCatalog
{
    private const ITEMS_OPTION = 'cornerstone_font_items';
    private const CONFIG_OPTION = 'cornerstone_font_config';

    /** Cornerstone's system fallback (GlobalFonts::get_system_fallback()). */
    public const SYSTEM_FALLBACK = 'sans-serif';

    /**
     * How to reference a global font. Settings\FontReferences has the rules
     * and tests/fixtures/cornerstone-7.9.4/font-values.txt what each form renders.
     */
    public const REFERENCE = [
        'element_family'      => '<_id>',
        'element_weight'      => ['fw-normal', 'fw-bold'],
        'theme_option_family' => '<_id>',
        'theme_option_weight' => ['fw-normal', 'fw-bold'],
        'notes'               => [
            'An element\'s *_font_family key (and its _alt and _bp_data values) holds the font\'s bare _id: "text_font_family": "body".',
            'An element\'s *_font_weight key holds "fw-normal" or "fw-bold" on its own: Cornerstone joins the element\'s family to it. "inherit", a number such as "700" (snapped to the closest weight the font has) and var() also work.',
            'Theme Options: *_font_family_selection holds the _id ("x_body_font_family_selection": "body") and *_font_weight_selection holds "fw-normal" or "fw-bold".',
            '"global-ff:<_id>" is not a family reference: Cornerstone reads "global-ff" as a font source and renders its fallback font (Helvetica, Arial, sans-serif). "<_id>|fw-normal" and "global-fw:<_id>|fw-normal" render font-weight: inherit. validate_layout reports them as font-ref-prefix and font-weight-shape.',
            'A component parameter or Global Parameter of type font-family holds the _id as well.',
        ],
    ];

    /**
     * The fonts section, read from the site.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $rawItems = get_option(self::ITEMS_OPTION, false);
        $rawConfig = get_option(self::CONFIG_OPTION, false);
        $items = Json::decodeStored($rawItems) ?? [];
        $config = Json::decodeStored($rawConfig) ?? [];
        $service = self::service();
        $notes = [];

        // GlobalFonts' first read writes to the database when an option is
        // missing or a custom item has no _id (getStoredFontItems(),
        // preload_config()). This section is read-only, so it resolves
        // nothing in those cases.
        if ($service !== null && ($rawItems === false || $rawConfig === false || self::customItemWithoutId($config))) {
            $service = null;
            $notes[] = 'The fonts or font config have never been saved, or a custom font item has no _id, and Cornerstone writes them the first time it reads them; stack and weights are as stored until the Font Manager is saved once.';
        }

        $resolve = null;
        $fallback = self::SYSTEM_FALLBACK;
        $googleEnabled = null;

        if ($service !== null) {
            $normalized = [];

            try {
                foreach ((array) $service->get_font_items() as $item) {
                    if (is_array($item) && isset($item['_id']) && is_scalar($item['_id'])) {
                        $normalized[(string) $item['_id']] = $item;
                    }
                }

                $fallback = (string) $service->get_system_fallback();
                $googleEnabled = function_exists('cs_google_fonts_enabled') ? (bool) cs_google_fonts_enabled() : null;
            } catch (\Throwable) {
                $normalized = [];
            }

            $resolve = static function (array $item) use ($service, $normalized): ?array {
                // The stored item is current; the service's copy fills in the
                // name and weights Cornerstone derives for older items.
                $input = $item + ($normalized[(string) ($item['_id'] ?? '')] ?? []);

                try {
                    $data = $service->getDataForFontItem($input);
                } catch (\Throwable) {
                    return null;
                }

                return is_array($data) ? $data : null;
            };
        }

        $shaped = self::shape($items, $config, $resolve, $fallback);

        if ($googleEnabled !== null) {
            $shaped['config']['google_fonts_enabled'] = $googleEnabled;
        }

        if ($service === null && $notes === []) {
            $notes[] = 'Cornerstone\'s GlobalFonts service is not available, so stack and weights are as stored rather than resolved.';
        }

        if ($notes !== []) {
            $shaped['notes'] = array_merge($notes, $shaped['notes']);
        }

        return $shaped;
    }

    /**
     * The section from the stored lists, with each font item resolved by
     * $resolve (getDataForFontItem()) when given.
     *
     * @param  array<int, mixed>                          $items   cornerstone_font_items, decoded.
     * @param  array<string, mixed>                       $config  cornerstone_font_config, decoded.
     * @param  (\Closure(array<string, mixed>): ?array<string, mixed>)|null $resolve
     * @return array<string, mixed>
     */
    public static function shape(array $items, array $config, ?\Closure $resolve, string $systemFallback = self::SYSTEM_FALLBACK): array
    {
        $groups = [];
        $groupOf = [];

        foreach ($items as $entry) {
            if (is_array($entry) && array_key_exists('children', $entry) && isset($entry['_id'])) {
                $children = array_values(array_map('strval', array_filter((array) $entry['children'], 'is_scalar')));
                $groups[] = ['_id' => (string) $entry['_id'], 'title' => (string) ($entry['title'] ?? ''), 'children' => $children];

                foreach ($children as $child) {
                    $groupOf[$child] ??= (string) $entry['_id'];
                }
            }
        }

        $customItems = array_values(array_filter((array) ($config['customFontItems'] ?? []), 'is_array'));
        $fonts = [];
        $usedBy = [];

        foreach ($items as $entry) {
            if (! is_array($entry) || array_key_exists('children', $entry) || ! isset($entry['_id']) || ! is_scalar($entry['_id'])) {
                continue;
            }

            $id = (string) $entry['_id'];
            $row = [
                '_id'    => $id,
                'title'  => (string) ($entry['title'] ?? ''),
                'source' => (string) ($entry['source'] ?? ''),
                'family' => (string) ($entry['family'] ?? ''),
            ];

            foreach (['name', 'fallback'] as $key) {
                if (isset($entry[$key]) && is_scalar($entry[$key]) && (string) $entry[$key] !== '') {
                    $row[$key] = (string) $entry[$key];
                }
            }

            $resolved = $resolve !== null ? $resolve($entry) : null;

            if (is_array($resolved)) {
                $row['stack'] = (string) ($resolved['stack'] ?? '');
                $row['weightNormal'] = (string) ($resolved['weightNormal'] ?? '');
                $row['weightBold'] = (string) ($resolved['weightBold'] ?? '');
                $row['weights'] = array_values(array_map('strval', (array) ($resolved['weights'] ?? [])));
                $row['resolved_by'] = 'cornerstone';

                $family = (string) ($resolved['family'] ?? '');

                if ($family !== '' && $row['family'] !== '' && $family !== $row['family']) {
                    $row['problem'] = sprintf('Cornerstone resolves this font to %s, not %s: its %s definition was not found, so elements get the fallback font.', $family, $row['family'], $row['source'] ?: 'source');
                }
            } else {
                $row['stack'] = (string) ($entry['stack'] ?? '');
                $row['weightNormal'] = (string) ($entry['weightNormal'] ?? '');
                $row['weightBold'] = (string) ($entry['weightBold'] ?? '');
                $row['resolved_by'] = 'stored';
            }

            if (isset($groupOf[$id])) {
                $row['group'] = $groupOf[$id];
            }

            if (! empty($entry['locked'])) {
                $row['locked'] = true;
            }

            $fonts[] = $row;

            if (($entry['source'] ?? null) === 'custom') {
                $usedBy[(string) ($entry['family'] ?? '')][] = $id;
            }
        }

        $custom = [];

        foreach ($customItems as $item) {
            $custom[] = self::customRow($item, $usedBy, $systemFallback);
        }

        $typekit = [];

        foreach ((array) ($config['typekitItems'] ?? []) as $kit) {
            if (is_array($kit) && isset($kit['family']) && is_string($kit['family'])) {
                $typekit[] = $kit['family'];
            }
        }

        $first = $fonts[0]['_id'] ?? 'body';

        return [
            'count'             => count($fonts),
            'fonts'             => $fonts,
            'groups'            => $groups,
            'custom_font_items' => $custom,
            'config'            => [
                'google_fonts_enabled'       => empty($config['googleDisabled']),
                'google_subsets'             => array_values(array_filter((array) ($config['googleSubsets'] ?? []), 'is_string')),
                'google_fonts_url_set'       => is_string($config['googleFontsURL'] ?? null) && $config['googleFontsURL'] !== '',
                'typekit_kit_set'            => is_string($config['typekitKitID'] ?? null) && $config['typekitKitID'] !== '',
                'typekit_load_as_css'        => ! empty($config['typekitKitLoadAsCSS']),
                'typekit_families'           => $typekit,
                'font_display'               => is_string($config['fontDisplay'] ?? null) ? $config['fontDisplay'] : 'auto',
                'custom_font_face_css_bytes' => is_string($config['customFontFaceCSS'] ?? null) ? strlen($config['customFontFaceCSS']) : 0,
            ],
            'reference'         => self::REFERENCE + ['example' => [
                'text_font_family'             => $first,
                'text_font_weight'             => 'fw-bold',
                'x_body_font_family_selection' => $first,
                'x_body_font_weight_selection' => 'fw-normal',
            ]],
            'notes'             => [
                'A custom font item\'s stack (or, without one, its family) is printed as the @font-face font-family, so it must be one quoted family; the other families go in its fallback, which Cornerstone appends for elements. set_fonts splits a comma stack that way.',
                'config.customFontFaceCSS is stored but Cornerstone 7.9.4 does not print it on the front end; an @font-face of your own (a variable font\'s font-stretch range, say) goes in Global CSS.',
            ],
        ];
    }

    /**
     * One custom font item: what its @font-face declares and what elements get.
     *
     * Mirrors make_custom_font_css() (isset stack, else family) and
     * resolveFontDefinition() (non-empty stack, else family, then the
     * fallback or the system fallback).
     *
     * @param  array<string, mixed>     $item
     * @param  array<string, string[]>  $usedBy Family => global font ids that use it.
     * @return array<string, mixed>
     */
    private static function customRow(array $item, array $usedBy, string $systemFallback): array
    {
        $family = (string) ($item['family'] ?? '');
        $stack = isset($item['stack']) && is_scalar($item['stack']) ? (string) $item['stack'] : null;
        $fallback = isset($item['fallback']) && is_scalar($item['fallback']) && (string) $item['fallback'] !== '' ? (string) $item['fallback'] : null;
        $fontFace = $stack ?? $family;
        $weights = [];

        foreach ((array) ($item['files'] ?? []) as $file) {
            if (is_array($file) && isset($file['weight']) && is_scalar($file['weight'])) {
                $weights[] = (string) $file['weight'] . (($file['style'] ?? 'normal') === 'italic' ? ' italic' : '');
            }
        }

        $row = [
            '_id'              => (string) ($item['_id'] ?? ''),
            'family'           => $family,
            'font_face_family' => $fontFace,
            'fallback'         => $fallback,
            'element_stack'    => ($stack !== null && $stack !== '' ? $stack : $family) . ', ' . ($fallback ?? $systemFallback),
            'weights'          => array_values(array_unique($weights)),
            'files'            => count((array) ($item['files'] ?? [])),
            'used_by'          => $usedBy[$family] ?? [],
        ];

        $parts = FontItems::splitFontList($fontFace);

        if (count($parts) > 1) {
            $row['problem'] = sprintf('The @font-face rule declares font-family: %s, which is a list, so it is invalid and the font never loads. set_fonts with config.customFontItems [{"_id": "%s"}] splits it into stack %s and fallback.', $fontFace, (string) ($item['_id'] ?? ''), $parts[0]);
        } elseif ($fontFace === '') {
            $row['problem'] = 'The @font-face rule declares an empty font-family, so the font never loads. Set its stack to the quoted family with set_fonts.';
        }

        return $row;
    }

    private static function customItemWithoutId(array $config): bool
    {
        foreach ((array) ($config['customFontItems'] ?? []) as $item) {
            if (is_array($item) && ! isset($item['_id'])) {
                return true;
            }
        }

        return false;
    }

    private static function service(): ?object
    {
        if (! function_exists('cornerstone')) {
            return null;
        }

        try {
            $service = cornerstone('GlobalFonts');
        } catch (\Throwable) {
            return null;
        }

        return is_object($service) && method_exists($service, 'getDataForFontItem') && method_exists($service, 'get_font_items') ? $service : null;
    }
}
