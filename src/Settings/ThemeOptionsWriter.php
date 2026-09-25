<?php

declare(strict_types=1);

namespace ProExtended\Settings;

use ProExtended\Cornerstone\DocumentAssets;

/**
 * Decides what a theme option write may change, before anything is written.
 *
 * Theme Options is a wide surface: stacks, layout, header and footer behaviour,
 * WooCommerce, and a handful of keys that other tools own or that would take
 * the site apart. Only keys Cornerstone actually registers can be written, and
 * a few registered ones are refused outright because there is a better way to
 * change them or because changing them rewrites stored content.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ThemeOptionsWriter
{
    /**
     * Keys this tool refuses, and why.
     *
     * Global CSS belongs to set_global_css and Global JS to set_global_js,
     * which back them up and check them. The palette and font lists belong to set_colors and
     * set_fonts, which keep their references intact. The breakpoint keys and
     * the stack decide how every piece of stored element data is read, so
     * changing them here would silently reinterpret the whole site. Variables
     * and global parameters belong to set_variables and set_global_parameters,
     * which validate each name and keep their own backups.
     */
    public const REFUSED = [
        'x_custom_styles'     => 'Global CSS is written with set_global_css, which backs it up and reports its size.',
        'x_custom_scripts'    => 'Global JS is written with set_global_js.',
        'cs_v1_custom_css'    => 'Legacy Global CSS is written with set_global_css.',
        'cs_v1_custom_js'     => 'Legacy Global JS is written with set_global_js.',
        'cornerstone_color_items' => 'The palette is written with set_colors, which keeps references to each colour intact.',
        'cornerstone_font_config' => 'Font settings are written with set_fonts.',
        'cornerstone_font_items'  => 'Fonts are written with set_fonts, which derives names, stacks and weights the way Cornerstone does.',
        'x_breakpoint_base'   => 'The breakpoint base decides how every element\'s stored responsive data is read; changing it here would reinterpret the whole site.',
        'x_breakpoint_ranges' => 'The breakpoint ranges decide how every element\'s stored responsive data is read; changing them here would reinterpret the whole site.',
        'x_stack'             => 'The stack changes the theme\'s markup and styling wholesale and is chosen in the Theme Options panel.',
        'cs_theme_variables'  => 'Global variables are written with set_variables, which validates each name and backs the list up.',
        'cs_global_parameter_json' => 'Global parameters are written with set_global_parameters, which checks the schema against the values.',
        'cs_global_parameter_data' => 'Global parameter values are written with set_global_parameters, which checks them against the schema.',
        'cs_twig_extension_advanced' => 'The Advanced Twig extension lets any Twig string call any PHP function and fire WordPress actions, which is arbitrary PHP on the site; switch it in the Theme Options panel if it is ever truly needed.',
    ];

    /** Suffixes that carry responsive data for a registered key. */
    private const RESPONSIVE_PREFIX = '_bp_data';

    /**
     * Work out what a set of updates would do.
     *
     * Font keys are normalised on the way in (see normalizeFont()) and each
     * change is listed in normalized; a family is then checked against the
     * Font Manager when its ids are known.
     *
     * @param  array<string, mixed> $updates    Key => the value asked for.
     * @param  string[]             $registered Keys Cornerstone registers.
     * @param  array<string, mixed> $current    The values as stored.
     * @param  string[]|null        $fontIds    The Font Manager's font ids; null when they cannot be read.
     * @return array{writes: array<int, array<string, mixed>>, unchanged: string[], errors: string[], normalized: array<int, array{key: string, from: mixed, to: mixed}>}
     */
    public static function plan(array $updates, array $registered, array $current, ?array $fontIds = null): array
    {
        $writes = [];
        $unchanged = [];
        $errors = [];
        $normalized = [];

        foreach ($updates as $key => $value) {
            if (! is_string($key) || $key === '') {
                $errors[] = 'Every key must be a theme option name.';
                continue;
            }

            $base = self::baseKey($key);

            if (isset(self::REFUSED[$base])) {
                $errors[] = sprintf('"%s" cannot be written here: %s', $key, self::REFUSED[$base]);
                continue;
            }

            if (! in_array($base, $registered, true)) {
                $errors[] = sprintf('"%s" is not a theme option this site registers. Use get_theme_options to see them.', $key);
                continue;
            }

            if (! self::isStorable($value)) {
                $errors[] = sprintf('"%s" must be a string, number, boolean, null or an array of those.', $key);
                continue;
            }

            $fixed = self::normalizeFont($base, $value);

            if ($fixed !== $value) {
                $normalized[] = ['key' => $key, 'from' => $value, 'to' => $fixed];
                $value = $fixed;
            }

            $problems = array_merge(self::fontErrors($key, $base, $value, $fontIds), self::valueErrors($key, $value));

            if ($problems !== []) {
                array_push($errors, ...$problems);
                continue;
            }

            $value = self::normalizeValue($key, $value);

            $was = $current[$key] ?? null;

            if (self::same($was, $value)) {
                $unchanged[] = $key;
                continue;
            }

            $writes[] = [
                'key'  => $key,
                'from' => $was,
                'to'   => $value,
                'responsive' => $base !== $key,
            ];
        }

        return ['writes' => $writes, 'unchanged' => $unchanged, 'errors' => $errors, 'normalized' => $normalized];
    }

    /**
     * A font key's value in the form Cornerstone resolves.
     *
     * *_font_family_selection holds a font's bare _id, so "global-ff:<id>"
     * becomes "<id>" (Cornerstone would read "global-ff" as a font source and
     * render the fallback font). *_font_weight_selection holds "fw-normal" or
     * "fw-bold": Cornerstone adds the family itself, so
     * "global-fw:<id>|fw-bold" and "<id>|fw-bold" become "fw-bold" (as given,
     * they render as inherit). A responsive variant's list is normalised
     * value by value. Every other key is returned as it is.
     */
    public static function normalizeFont(string $base, mixed $value): mixed
    {
        $normalize = match (true) {
            str_ends_with($base, FontReferences::THEME_FAMILY_SUFFIX) => [FontReferences::class, 'normalizeThemeFamily'],
            str_ends_with($base, FontReferences::THEME_WEIGHT_SUFFIX) => [FontReferences::class, 'normalizeThemeWeight'],
            default                                                   => null,
        };

        if ($normalize === null) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? $item : $normalize($item), $value);
        }

        return $normalize($value);
    }

    /**
     * A family written to *_font_family_selection must be a font the Font
     * Manager has (its _id), "inherit", or a "<source>:<name>" form
     * GlobalFonts::locate_font() looks up; anything else renders the
     * fallback font. Not checked when the ids cannot be read.
     *
     * @param  string[]|null $fontIds
     * @return string[]
     */
    public static function fontErrors(string $key, string $base, mixed $value, ?array $fontIds): array
    {
        if ($fontIds === null || ! str_ends_with($base, FontReferences::THEME_FAMILY_SUFFIX)) {
            return [];
        }

        $errors = [];

        foreach (is_array($value) ? $value : [$value] as $family) {
            if ($family === null || $family === '' || ! is_string($family)) {
                continue;
            }

            if (in_array($family, $fontIds, true) || $family === 'inherit' || FontReferences::isSourceName($family) || str_contains($family, '{{') || stripos($family, 'var(') !== false) {
                continue;
            }

            $errors[] = sprintf(
                '"%s" must be a font\'s _id from the Font Manager (%s), "inherit", or a "<source>:<name>" form such as "system:helveticaneue"; "%s" is none of these, so Cornerstone would render the fallback font.',
                $key,
                $fontIds === [] ? 'it has no fonts yet; add one with set_fonts' : 'one of "' . implode('", "', $fontIds) . '"',
                $family
            );
        }

        return $errors;
    }

    /**
     * The registered key a responsive variant belongs to.
     *
     * Cornerstone stores per-breakpoint values beside the key they belong to,
     * as "<key>_bp_data4_4", so those are allowed when their base key is.
     */
    public static function baseKey(string $key): string
    {
        $position = strpos($key, self::RESPONSIVE_PREFIX);

        return $position === false || $position === 0 ? $key : substr($key, 0, $position);
    }

    /**
     * Keys whose stored shape Cornerstone reads without checking, so a bad
     * value breaks rendering: they are validated before they are written.
     *
     * @return string[]
     */
    public static function valueErrors(string $key, mixed $value): array
    {
        return match ($key) {
            TwigTemplates::OPTION => TwigTemplates::errors($value),
            DocumentAssets::OPTION_SCRIPTS, DocumentAssets::OPTION_STYLES => DocumentAssets::optionErrors($key, $value),
            default               => [],
        };
    }

    /**
     * A validated value completed the way the builder stores it (Custom
     * Assets items take the list control's defaults).
     */
    public static function normalizeValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            DocumentAssets::OPTION_SCRIPTS => DocumentAssets::scripts($value, $key),
            DocumentAssets::OPTION_STYLES  => DocumentAssets::styles($value, $key),
            default                        => $value,
        };
    }

    /**
     * Keys whose writes need Cornerstone's Custom Assets permission.
     *
     * @param  array<int, array<string, mixed>> $writes plan()['writes']
     */
    public static function touchesDocumentAssets(array $writes): bool
    {
        foreach ($writes as $write) {
            if (in_array(self::baseKey((string) ($write['key'] ?? '')), [DocumentAssets::OPTION_SCRIPTS, DocumentAssets::OPTION_STYLES], true)) {
                return true;
            }
        }

        return false;
    }

    private static function isStorable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::isStorable($item)) {
                return false;
            }
        }

        return true;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }
}
