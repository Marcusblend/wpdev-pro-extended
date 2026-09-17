<?php

declare(strict_types=1);

namespace ProExtended\Css;

/**
 * Named blocks that Pro Extended manages inside Global CSS.
 *
 *     /* pe:begin <name> *\/
 *     ...
 *     /* pe:end <name> *\/
 *
 * Every edit keeps the CSS outside the managed blocks byte-identical: a block
 * owns exactly one separating newline (the one before it, or the one after
 * it when it sits at the top of the stylesheet), and outside() removes blocks
 * by the same rule.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class CssBlocks
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';
    public const MAX_BYTES = 262144; // 256 KB
    public const MAX_IMPORTANT = 50;

    private const BEGIN = '/\/\* pe:begin ([a-z0-9][a-z0-9-]{0,62}) \*\//';
    private const END = '/\/\* pe:end ([a-z0-9][a-z0-9-]{0,62}) \*\//';

    /**
     * Find the managed blocks in a stylesheet.
     *
     * @return array{
     *     blocks: array<int, array{name: string, start: int, end: int, content: string, line_start: int, line_end: int, bytes: int}>,
     *     errors: string[]
     * }
     */
    public static function parse(string $css): array
    {
        $errors = [];
        $blocks = [];

        preg_match_all(self::BEGIN, $css, $begins, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        preg_match_all(self::END, $css, $ends, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $markers = [];

        foreach ($begins as $match) {
            $markers[] = ['kind' => 'begin', 'name' => $match[1][0], 'offset' => $match[0][1], 'length' => strlen($match[0][0])];
        }

        foreach ($ends as $match) {
            $markers[] = ['kind' => 'end', 'name' => $match[1][0], 'offset' => $match[0][1], 'length' => strlen($match[0][0])];
        }

        usort($markers, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $recognised = count($markers);
        $mentioned = preg_match_all('/pe:(?:begin|end)\b/i', $css);

        if ($mentioned !== $recognised) {
            $errors[] = 'The stylesheet contains a malformed pe:begin/pe:end marker.';
        }

        $open = null;
        $seen = [];

        foreach ($markers as $marker) {
            if ($marker['kind'] === 'begin') {
                if ($open !== null) {
                    $errors[] = sprintf('Block "%s" starts before block "%s" ends.', $marker['name'], $open['name']);
                    continue;
                }

                $open = $marker;
                continue;
            }

            if ($open === null || $open['name'] !== $marker['name']) {
                $errors[] = sprintf('pe:end %s has no matching pe:begin.', $marker['name']);
                continue;
            }

            $name = $open['name'];
            $start = $open['offset'];
            $end = $marker['offset'] + $marker['length'];

            $contentStart = $open['offset'] + $open['length'];
            if (($css[$contentStart] ?? '') === "\n") {
                $contentStart++;
            }

            $contentEnd = $marker['offset'];
            if ($contentEnd > $contentStart && $css[$contentEnd - 1] === "\n") {
                $contentEnd--;
            }

            $content = $contentEnd > $contentStart ? substr($css, $contentStart, $contentEnd - $contentStart) : '';

            if (isset($seen[$name])) {
                $errors[] = sprintf('Block "%s" appears more than once.', $name);
            }

            $seen[$name] = true;

            $blocks[] = [
                'name'       => $name,
                'start'      => $start,
                'end'        => $end,
                'content'    => $content,
                'line_start' => substr_count($css, "\n", 0, $start) + 1,
                'line_end'   => substr_count($css, "\n", 0, $end) + 1,
                'bytes'      => strlen($content),
            ];

            $open = null;
        }

        if ($open !== null) {
            $errors[] = sprintf('Block "%s" has no pe:end marker.', $open['name']);
        }

        return ['blocks' => $blocks, 'errors' => $errors];
    }

    /**
     * The exact text of a managed block.
     */
    public static function blockText(string $name, string $content): string
    {
        $content = rtrim($content, "\r\n");

        return "/* pe:begin {$name} */\n" . ($content === '' ? '' : $content . "\n") . "/* pe:end {$name} */";
    }

    /**
     * The stylesheet with every managed block (and the newline it owns) removed.
     */
    public static function outside(string $css): string
    {
        $parsed = self::parse($css);
        $out = '';
        $pos = 0;

        foreach (self::spans($css, $parsed['blocks']) as [$start, $end]) {
            $out .= substr($css, $pos, $start - $pos);
            $pos = $end;
        }

        return $out . substr($css, $pos);
    }

    /**
     * Create a block or replace an existing block's content.
     *
     * @return array{css: string, created: bool, before: string|null}
     *
     * @throws \InvalidArgumentException
     */
    public static function upsert(string $css, string $name, string $content, string $position = 'end'): array
    {
        self::assertName($name);
        $parsed = self::parseOrFail($css);
        $block = self::blockText($name, $content);

        foreach ($parsed['blocks'] as $existing) {
            if ($existing['name'] === $name) {
                return [
                    'css'     => substr_replace($css, $block, $existing['start'], $existing['end'] - $existing['start']),
                    'created' => false,
                    'before'  => $existing['content'],
                ];
            }
        }

        if ($css === '') {
            $new = $block;
        } elseif ($position === 'start') {
            $new = $block . "\n" . $css;
        } else {
            $new = $css . "\n" . $block;
        }

        return ['css' => $new, 'created' => true, 'before' => null];
    }

    /**
     * Remove a block and the newline it owns.
     *
     * @return array{css: string, before: string}
     *
     * @throws \InvalidArgumentException
     */
    public static function remove(string $css, string $name): array
    {
        self::assertName($name);
        $parsed = self::parseOrFail($css);
        $spans = self::spans($css, $parsed['blocks']);

        foreach ($parsed['blocks'] as $i => $block) {
            if ($block['name'] === $name) {
                [$start, $end] = $spans[$i];

                return [
                    'css'    => substr($css, 0, $start) . substr($css, $end),
                    'before' => $block['content'],
                ];
            }
        }

        throw new \InvalidArgumentException(sprintf('There is no block named "%s".', $name));
    }

    /**
     * Problems that make CSS unsafe or unusable to store.
     *
     * @return string[]
     */
    public static function inputErrors(string $css, bool $allowMarkers = false): array
    {
        $errors = [];

        if (preg_match('/<\/style|<script|<\?/i', $css)) {
            $errors[] = 'CSS must not contain "</style", "<script" or "<?".';
        }

        if (! $allowMarkers && preg_match('/pe:(?:begin|end)\b/i', $css)) {
            $errors[] = 'CSS must not contain pe:begin or pe:end markers; blocks are managed by name.';
        }

        return array_merge($errors, self::balanceErrors($css));
    }

    /**
     * Unbalanced braces, comments or strings.
     *
     * @return string[]
     */
    public static function balanceErrors(string $css): array
    {
        $errors = [];
        $depth = 0;
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            $char = $css[$i];

            if ($char === '\\') {
                $i += 2;
                continue;
            }

            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $close = strpos($css, '*/', $i + 2);

                if ($close === false) {
                    $errors[] = sprintf('Unclosed comment starting on line %d.', self::lineAt($css, $i));
                    break;
                }

                $i = $close + 2;
                continue;
            }

            if ($char === '*' && ($css[$i + 1] ?? '') === '/') {
                $errors[] = sprintf('Unexpected "*/" on line %d.', self::lineAt($css, $i));
                $i += 2;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $j = $i + 1;
                $closed = false;

                while ($j < $length) {
                    if ($css[$j] === '\\') {
                        $j += 2;
                        continue;
                    }

                    if ($css[$j] === "\n") {
                        break;
                    }

                    if ($css[$j] === $char) {
                        $closed = true;
                        break;
                    }

                    $j++;
                }

                if (! $closed) {
                    $errors[] = sprintf('Unclosed string on line %d.', self::lineAt($css, $i));
                    $i = $j;
                    continue;
                }

                $i = $j + 1;
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                if ($depth === 0) {
                    $errors[] = sprintf('Unexpected "}" on line %d.', self::lineAt($css, $i));
                } else {
                    $depth--;
                }
            }

            $i++;
        }

        if ($depth > 0) {
            $errors[] = sprintf('%d unclosed "{".', $depth);
        }

        return $errors;
    }

    /**
     * Things worth a second look that do not block the write.
     *
     * @return string[]
     */
    public static function warnings(string $css): array
    {
        $warnings = [];
        $clean = self::stripCommentsAndStrings($css);

        $missingColor = [];

        if (preg_match_all('/([^{};]*)\{([^{}]*)\}/', $clean, $rules, PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                $selector = trim(preg_replace('/\s+/', ' ', $rule[1]) ?? '');

                if ($selector === '' || str_starts_with($selector, '@')) {
                    continue;
                }

                $properties = [];

                foreach (explode(';', $rule[2]) as $declaration) {
                    $parts = explode(':', $declaration, 2);

                    if (count($parts) === 2) {
                        $properties[strtolower(trim($parts[0]))] = strtolower(trim(str_replace('!important', '', $parts[1])));
                    }
                }

                $background = $properties['background-color'] ?? $properties['background'] ?? null;

                if (
                    $background !== null
                    && ! in_array($background, ['none', 'transparent', 'inherit', 'initial', 'unset', 'revert', 'revert-layer'], true)
                    && ! array_key_exists('color', $properties)
                ) {
                    $missingColor[] = $selector;
                }
            }
        }

        if ($missingColor !== []) {
            $shown = array_slice(array_values(array_unique($missingColor)), 0, 10);
            $warnings[] = sprintf(
                'Sets a background without a text color (set both to avoid unreadable text): %s%s.',
                implode(', ', $shown),
                count(array_unique($missingColor)) > count($shown) ? ' and more' : ''
            );
        }

        if (preg_match('/@import\s+(?:url\(\s*)?["\']?\s*(?:https?:)?\/\//i', $css)) {
            $warnings[] = 'Imports remote CSS with @import (for example web fonts); load fonts through Cornerstone\'s font manager instead.';
        }

        $important = substr_count(strtolower($clean), '!important');

        if ($important > self::MAX_IMPORTANT) {
            $warnings[] = sprintf('Uses !important %d times.', $important);
        }

        return $warnings;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertName(string $name): void
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid block name "%s": use lowercase letters, digits and hyphens (start with a letter or digit, 63 characters at most).',
                $name
            ));
        }
    }

    /**
     * @return array{blocks: array<int, array<string, mixed>>, errors: string[]}
     *
     * @throws \InvalidArgumentException
     */
    private static function parseOrFail(string $css): array
    {
        $parsed = self::parse($css);

        if ($parsed['errors'] !== []) {
            throw new \InvalidArgumentException('The stored Global CSS has broken Pro Extended blocks: ' . implode(' ', $parsed['errors']));
        }

        return $parsed;
    }

    /**
     * Byte ranges each block owns, including its separating newline.
     *
     * @param  array<int, array<string, mixed>> $blocks
     * @return array<int, array{0: int, 1: int}>
     */
    private static function spans(string $css, array $blocks): array
    {
        $spans = [];
        $pos = 0;
        $emitted = false;

        foreach ($blocks as $i => $block) {
            $start = (int) $block['start'];
            $end = (int) $block['end'];

            if (! $emitted && $start === $pos) {
                // At the top of the stylesheet a block owns the newline after it.
                if (($css[$end] ?? '') === "\n") {
                    $end++;
                }
            } else {
                if ($start - 1 >= $pos && $css[$start - 1] === "\n") {
                    $start--;
                }

                if ($start > $pos) {
                    $emitted = true;
                }
            }

            $spans[$i] = [$start, $end];
            $pos = $end;
        }

        return $spans;
    }

    private static function lineAt(string $css, int $offset): int
    {
        return substr_count($css, "\n", 0, $offset) + 1;
    }

    /**
     * Replace comments with spaces and string contents with blanks, keeping
     * the structure intact for rule scanning.
     */
    private static function stripCommentsAndStrings(string $css): string
    {
        $css = preg_replace('/\/\*.*?\*\//s', ' ', $css) ?? $css;

        return preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '""', $css) ?? $css;
    }
}
