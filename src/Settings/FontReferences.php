<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * The forms Cornerstone 7.9.4 accepts for a font reference, and the fixes for
 * the forms it does not.
 *
 * - A font family value is a global font's bare `_id` ("body").
 *   GlobalFonts::locate_font() matches it against the Font Manager items, or
 *   else splits "<source>:<name>" ("system:helveticaneue") and looks that up
 *   in the font data. "global-ff:body" splits into the source "global-ff",
 *   which does not exist, so it renders the fallback font (Helvetica, Arial,
 *   sans-serif).
 * - A font weight value is "fw-normal" or "fw-bold" (or "inherit", a number,
 *   or var()). The TSS global-fw function joins the element's family to it
 *   ("<family>|fw-normal") before GlobalFonts::cssPostProcessFontWeight()
 *   resolves the pair, so a stored "body|fw-normal" becomes
 *   "<family>|body|fw-normal", which has three parts and renders as inherit.
 * - Theme Options follow the same rules: *_font_family_selection holds the
 *   `_id`, *_font_weight_selection holds "fw-normal" or "fw-bold".
 *
 * tests/fixtures/cornerstone-7.9.4/font-values.txt records each form and what
 * it renders. Pure PHP with no WordPress calls, so it is unit-tested without
 * a site.
 */
final class FontReferences
{
    public const FAMILY_PREFIX = 'global-ff:';
    public const WEIGHT_PREFIX = 'global-fw:';

    /** The weight keywords a global font resolves. */
    public const WEIGHTS = ['fw-normal', 'fw-bold'];

    /** Sources GlobalFonts::resolveFontDefinition() knows for "<source>:<name>". */
    public const SOURCES = ['system', 'google', 'typekit', 'custom'];

    /** Theme option keys holding a font family or weight reference. */
    public const THEME_FAMILY_SUFFIX = '_font_family_selection';
    public const THEME_WEIGHT_SUFFIX = '_font_weight_selection';

    /**
     * The `_id` of every font item in the Font Manager list (groups skipped).
     *
     * @param  array<int, mixed> $items cornerstone_font_items, decoded.
     * @return string[]
     */
    public static function ids(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (is_array($item) && ! array_key_exists('children', $item) && isset($item['_id']) && is_scalar($item['_id']) && (string) $item['_id'] !== '') {
                $ids[] = (string) $item['_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a value is the "<source>:<name>" form locate_font() looks up in
     * Cornerstone's font data ("system:helveticaneue").
     */
    public static function isSourceName(string $value): bool
    {
        return preg_match('/^(' . implode('|', self::SOURCES) . '):[^\s:|,"\']+$/', $value) === 1;
    }

    /**
     * Whether a value starts with a global-ff: or global-fw: prefix.
     */
    public static function hasPrefix(string $value): bool
    {
        $value = ltrim($value);

        return str_starts_with($value, self::FAMILY_PREFIX) || str_starts_with($value, self::WEIGHT_PREFIX);
    }

    /**
     * The family value a prefixed one should have been: "global-ff:body" and
     * "global-ff:body, sans-serif" are "body". Null when there is no prefix
     * or no id after it.
     */
    public static function familyFix(string $value): ?string
    {
        $value = trim($value);

        foreach ([self::FAMILY_PREFIX, self::WEIGHT_PREFIX] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                // Font ids are not always slugs: fonts made in older builders
                // carry ids such as "Hind Semi Bold". Everything up to a weight
                // ("|fw-bold") or a fallback list (", sans-serif") is the id.
                $rest = substr($value, strlen($prefix));
                $rest = (string) preg_split('/[|,]/', $rest, 2)[0];
                $id = trim($rest, " \t\n\r\0\x0B\"'");

                return $id !== '' ? $id : null;
            }
        }

        return null;
    }

    /**
     * The weight value a pipe-joined or prefixed one should have been:
     * "global-fw:body|fw-bold" and "body|fw-bold" are "fw-bold". Null when the
     * value carries no weight Cornerstone would read on its own.
     */
    public static function weightFix(string $value): ?string
    {
        $value = trim($value);

        if (str_contains($value, '|')) {
            $weight = trim(substr($value, (int) strrpos($value, '|') + 1));
        } elseif (str_starts_with($value, self::WEIGHT_PREFIX)) {
            $weight = trim(substr($value, strlen(self::WEIGHT_PREFIX)));
        } else {
            return null;
        }

        return self::isPlainWeight($weight) ? $weight : null;
    }

    /**
     * A weight Cornerstone reads without a family attached.
     */
    public static function isPlainWeight(string $weight): bool
    {
        return in_array($weight, self::WEIGHTS, true) || $weight === 'inherit' || preg_match('/^[1-9]00$/', $weight) === 1;
    }

    /**
     * Normalise a *_font_family_selection value: "global-ff:<id>" becomes
     * "<id>". Anything else is returned as it is.
     */
    public static function normalizeThemeFamily(mixed $value): mixed
    {
        if (! is_string($value) || ! str_starts_with(trim($value), self::FAMILY_PREFIX)) {
            return $value;
        }

        // Only an exact "global-ff:<id>" is rewritten; a value that also
        // carries a weight or a fallback list is left for validation to refuse.
        if (str_contains($value, '|') || str_contains($value, ',')) {
            return $value;
        }

        return self::familyFix($value) ?? $value;
    }

    /**
     * Normalise a *_font_weight_selection value: "global-fw:<id>|fw-bold" and
     * "<id>|fw-bold" become "fw-bold". Anything else is returned as it is.
     */
    public static function normalizeThemeWeight(mixed $value): mixed
    {
        if (! is_string($value) || (! str_contains($value, '|') && ! str_starts_with(trim($value), self::WEIGHT_PREFIX))) {
            return $value;
        }

        return self::weightFix($value) ?? $value;
    }
}
