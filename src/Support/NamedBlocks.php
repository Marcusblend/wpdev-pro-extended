<?php

declare(strict_types=1);

namespace ProExtended\Support;

/**
 * Named, marker-delimited blocks inside a larger text that other people also
 * edit, such as Global JS.
 *
 * The marker syntax is given by the caller; the rules are the ones CssBlocks
 * has always used for Global CSS. Every edit keeps the text outside the
 * managed blocks byte-identical: a block owns exactly one separating newline
 * (the one before it, or the one after it when it sits at the very top), and
 * outside() removes blocks by the same rule.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class NamedBlocks
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * @param string $beginPattern Regex for a begin marker; group 1 is the name.
     * @param string $endPattern   Regex for an end marker; group 1 is the name.
     * @param string $beginFormat  sprintf() format that writes a begin marker for a name.
     * @param string $endFormat    sprintf() format that writes an end marker for a name.
     * @param string $what         What the text is, for messages ("Global JS").
     */
    public function __construct(
        private readonly string $beginPattern,
        private readonly string $endPattern,
        private readonly string $beginFormat,
        private readonly string $endFormat,
        private readonly string $what,
    ) {}

    /**
     * Find the managed blocks.
     *
     * @return array{
     *     blocks: array<int, array{name: string, start: int, end: int, content: string, line_start: int, line_end: int, bytes: int}>,
     *     errors: string[]
     * }
     */
    public function parse(string $text): array
    {
        $errors = [];
        $blocks = [];

        preg_match_all($this->beginPattern, $text, $begins, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        preg_match_all($this->endPattern, $text, $ends, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $markers = [];

        foreach ($begins as $match) {
            $markers[] = ['kind' => 'begin', 'name' => $match[1][0], 'offset' => $match[0][1], 'length' => strlen($match[0][0])];
        }

        foreach ($ends as $match) {
            $markers[] = ['kind' => 'end', 'name' => $match[1][0], 'offset' => $match[0][1], 'length' => strlen($match[0][0])];
        }

        usort($markers, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        if (preg_match_all('/pe:(?:begin|end)\b/i', $text) !== count($markers)) {
            $errors[] = sprintf('%s contains a malformed pe:begin/pe:end marker.', $this->what);
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

            if (($text[$contentStart] ?? '') === "\n") {
                $contentStart++;
            }

            $contentEnd = $marker['offset'];

            if ($contentEnd > $contentStart && $text[$contentEnd - 1] === "\n") {
                $contentEnd--;
            }

            $content = $contentEnd > $contentStart ? substr($text, $contentStart, $contentEnd - $contentStart) : '';

            if (isset($seen[$name])) {
                $errors[] = sprintf('Block "%s" appears more than once.', $name);
            }

            $seen[$name] = true;

            $blocks[] = [
                'name'       => $name,
                'start'      => $start,
                'end'        => $end,
                'content'    => $content,
                'line_start' => substr_count($text, "\n", 0, $start) + 1,
                'line_end'   => substr_count($text, "\n", 0, $end) + 1,
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
     * The exact text of a block.
     */
    public function blockText(string $name, string $content): string
    {
        $content = rtrim($content, "\r\n");

        return sprintf($this->beginFormat, $name) . "\n" . ($content === '' ? '' : $content . "\n") . sprintf($this->endFormat, $name);
    }

    /**
     * The text with every managed block (and the newline it owns) removed.
     */
    public function outside(string $text): string
    {
        $parsed = $this->parse($text);
        $out = '';
        $pos = 0;

        foreach ($this->spans($text, $parsed['blocks']) as [$start, $end]) {
            $out .= substr($text, $pos, $start - $pos);
            $pos = $end;
        }

        return $out . substr($text, $pos);
    }

    /**
     * Create a block or replace an existing block's content.
     *
     * @return array{text: string, created: bool, before: string|null}
     *
     * @throws \InvalidArgumentException
     */
    public function upsert(string $text, string $name, string $content, string $position = 'end'): array
    {
        self::assertName($name);
        $parsed = $this->parseOrFail($text);
        $block = $this->blockText($name, $content);

        foreach ($parsed['blocks'] as $existing) {
            if ($existing['name'] === $name) {
                return [
                    'text'    => substr_replace($text, $block, $existing['start'], $existing['end'] - $existing['start']),
                    'created' => false,
                    'before'  => $existing['content'],
                ];
            }
        }

        if ($text === '') {
            $new = $block;
        } elseif ($position === 'start') {
            $new = $block . "\n" . $text;
        } else {
            $new = $text . "\n" . $block;
        }

        return ['text' => $new, 'created' => true, 'before' => null];
    }

    /**
     * Remove a block and the newline it owns.
     *
     * @return array{text: string, before: string}
     *
     * @throws \InvalidArgumentException
     */
    public function remove(string $text, string $name): array
    {
        self::assertName($name);
        $parsed = $this->parseOrFail($text);
        $spans = $this->spans($text, $parsed['blocks']);

        foreach ($parsed['blocks'] as $i => $block) {
            if ($block['name'] === $name) {
                [$start, $end] = $spans[$i];

                return [
                    'text'   => substr($text, 0, $start) . substr($text, $end),
                    'before' => $block['content'],
                ];
            }
        }

        throw new \InvalidArgumentException(sprintf('There is no block named "%s".', $name));
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
    private function parseOrFail(string $text): array
    {
        $parsed = $this->parse($text);

        if ($parsed['errors'] !== []) {
            throw new \InvalidArgumentException(sprintf('The stored %s has broken Pro Extended blocks: %s', $this->what, implode(' ', $parsed['errors'])));
        }

        return $parsed;
    }

    /**
     * Byte ranges each block owns, including its separating newline.
     *
     * @param  array<int, array<string, mixed>> $blocks
     * @return array<int, array{0: int, 1: int}>
     */
    private function spans(string $text, array $blocks): array
    {
        $spans = [];
        $pos = 0;
        $emitted = false;

        foreach ($blocks as $i => $block) {
            $start = (int) $block['start'];
            $end = (int) $block['end'];

            if (! $emitted && $start === $pos) {
                // At the top of the text a block owns the newline after it.
                if (($text[$end] ?? '') === "\n") {
                    $end++;
                }
            } else {
                if ($start - 1 >= $pos && $text[$start - 1] === "\n") {
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
}
