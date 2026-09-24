<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Finding Twig in element data, the way Cornerstone's Twig integration will
 * see it.
 *
 * Cornerstone expands Dynamic Content first and hands the result to Twig
 * (the `cs_dynamic_content_after_render` filter in its Twig integration), so a
 * `{{dc:post:title}}` token is text by the time Twig parses the string. Before
 * a string is checked, every token is therefore replaced with a neutral
 * placeholder; what is left is what Twig will actually parse.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class TwigSyntax
{
    /** What a Dynamic Content token becomes before Twig is asked about a string. */
    public const PLACEHOLDER = '0';

    /**
     * The string with every Dynamic Content token (nested ones included)
     * replaced by the placeholder. An unclosed token is left as it is; the
     * token lint reports it.
     */
    public static function withoutTokens(string $value): string
    {
        if (! str_contains($value, '{{dc:')) {
            return $value;
        }

        $out = '';
        $length = strlen($value);
        $position = 0;

        while (($start = strpos($value, '{{dc:', $position)) !== false) {
            $out .= substr($value, $position, $start - $position);
            $depth = 0;
            $i = $start;
            $closed = false;

            while ($i < $length) {
                if (substr_compare($value, '{{dc:', $i, 5) === 0) {
                    $depth++;
                    $i += 5;
                    continue;
                }

                if (substr_compare($value, '}}', $i, 2) === 0) {
                    $depth--;
                    $i += 2;

                    if ($depth === 0) {
                        $closed = true;
                        break;
                    }

                    continue;
                }

                $i++;
            }

            if (! $closed) {
                return $out . substr($value, $start);
            }

            $out .= self::PLACEHOLDER;
            $position = $i;
        }

        return $out . substr($value, $position);
    }

    /**
     * Whether a string holds Twig once its Dynamic Content tokens are set
     * aside: a `{%` tag, a `{{` that is not a `{{dc:` token, or a `{# #}`
     * comment.
     */
    public static function containsTwig(string $value): bool
    {
        if (! str_contains($value, '{')) {
            return false;
        }

        $stripped = self::withoutTokens($value);

        return str_contains($stripped, '{%')
            || str_contains($stripped, '{{')
            || preg_match('/\{#.*?#\}/s', $stripped) === 1;
    }

    /**
     * A short excerpt of the Twig in a string, for messages.
     */
    public static function excerpt(string $value, int $max = 60): string
    {
        $stripped = self::withoutTokens($value);

        if (preg_match('/\{[{%#]/', $stripped, $match, PREG_OFFSET_CAPTURE) === 1) {
            $stripped = substr($stripped, (int) $match[0][1]);
        }

        $stripped = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;

        return strlen($stripped) > $max ? substr($stripped, 0, $max - 1) . '…' : $stripped;
    }
}
