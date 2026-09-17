<?php

declare(strict_types=1);

namespace ProExtended\Support;

/**
 * Unified diff of the region that changed between two texts.
 *
 * Trims the common leading and trailing lines and reports the rest as one
 * hunk with context. That is exact for the single-region edits the CSS tool
 * makes, and still correct (if not minimal) for a full replacement.
 */
final class LineDiff
{
    public static function unified(string $old, string $new, int $context = 3, int $maxLines = 400): string
    {
        if ($old === $new) {
            return '';
        }

        $a = self::lines($old);
        $b = self::lines($new);
        $countA = count($a);
        $countB = count($b);

        $prefix = 0;
        while ($prefix < $countA && $prefix < $countB && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < $countA - $prefix
            && $suffix < $countB - $prefix
            && $a[$countA - 1 - $suffix] === $b[$countB - 1 - $suffix]
        ) {
            $suffix++;
        }

        $start = max(0, $prefix - $context);
        $endA = min($countA, $countA - $suffix + $context);
        $endB = min($countB, $countB - $suffix + $context);

        $lines = [];
        $lines[] = '--- before';
        $lines[] = '+++ after';
        $lines[] = sprintf(
            '@@ -%d,%d +%d,%d @@',
            $endA > $start ? $start + 1 : $start,
            $endA - $start,
            $endB > $start ? $start + 1 : $start,
            $endB - $start
        );

        for ($i = $start; $i < $prefix; $i++) {
            $lines[] = ' ' . $a[$i];
        }

        for ($i = $prefix; $i < $countA - $suffix; $i++) {
            $lines[] = '-' . $a[$i];
        }

        for ($i = $prefix; $i < $countB - $suffix; $i++) {
            $lines[] = '+' . $b[$i];
        }

        for ($i = $countA - $suffix; $i < $endA; $i++) {
            $lines[] = ' ' . $a[$i];
        }

        $total = count($lines);

        if ($total > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = sprintf('... diff truncated (%d more lines)', $total - $maxLines);
        }

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return explode("\n", $text);
    }
}
