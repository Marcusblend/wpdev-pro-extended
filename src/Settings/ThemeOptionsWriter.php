<?php

declare(strict_types=1);

namespace ProExtended\Settings;

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
    ];

    /** Suffixes that carry responsive data for a registered key. */
    private const RESPONSIVE_PREFIX = '_bp_data';

    /**
     * Work out what a set of updates would do.
     *
     * @param  array<string, mixed> $updates    Key => the value asked for.
     * @param  string[]             $registered Keys Cornerstone registers.
     * @param  array<string, mixed> $current    The values as stored.
     * @return array{writes: array<int, array<string, mixed>>, unchanged: string[], errors: string[]}
     */
    public static function plan(array $updates, array $registered, array $current): array
    {
        $writes = [];
        $unchanged = [];
        $errors = [];

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

        return ['writes' => $writes, 'unchanged' => $unchanged, 'errors' => $errors];
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
