<?php

declare(strict_types=1);

namespace ProExtended\Support;

/**
 * The site-wide switch for `skip_validation`.
 *
 * The `pe_allow_skip_validation` option (default "1", for upstream
 * compatibility) and the filter of the same name decide whether callers may
 * skip layout validation. "0" turns the escape hatch off.
 */
final class SkipValidation
{
    /**
     * The effective setting: "1" (allowed) or "0" (locked).
     */
    public static function setting(): string
    {
        $value = apply_filters('pe_allow_skip_validation', get_option('pe_allow_skip_validation', '1'));

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value === '0' ? '0' : '1';
    }

    /**
     * @throws \RuntimeException When skipping validation is requested but locked.
     */
    public static function assertAllowed(bool $requested): void
    {
        if ($requested && self::setting() === '0') {
            throw new \RuntimeException(
                'skip_validation is disabled on this site (pe_allow_skip_validation is 0). Fix the reported validation errors instead.'
            );
        }
    }
}
