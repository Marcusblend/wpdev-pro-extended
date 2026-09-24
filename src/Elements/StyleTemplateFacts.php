<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Reads what an element's TSS style template always emits.
 *
 * TSS is Cornerstone's SCSS-like style language (classes/Tss): declarations,
 * nested rules ("& .child { }"), @include mixins, @if / @else / @each flow and
 * $variables. Cornerstone compiles each element's declarations under a
 * generated class (ModuleReducer's selector format ".$m"), so a top-level
 * declaration lands on ".m<id>-<n>" — one class, specificity 0,1,0 — and a
 * nested rule adds its own selector to that.
 *
 * This is a reader, not a compiler: "always" means a literal declaration that
 * sits outside any @if, @each or at-rule block. A declaration whose value
 * comes out empty is still dropped by Cornerstone (DeclarationReducer), and a
 * mixin's output is not expanded; mixins are listed by name.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class StyleTemplateFacts
{
    /** Definitions that emit nothing where they are written. */
    private const DEFINITION_AT = ['module', 'mixin', 'function'];

    /**
     * The body of "@module <name>(…) { … }" in a TSS source, or null.
     */
    public static function moduleBody(string $source, string $name): ?string
    {
        $pattern = '/@module\s+' . preg_quote($name, '/') . '\b\s*(?:\([^{]*\))?\s*\{/';

        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $open = (int) $match[0][1] + strlen($match[0][0]) - 1;
        $close = self::matchingBrace($source, $open);

        return $close === null ? null : substr($source, $open + 1, $close - $open - 1);
    }

    /**
     * @return array{
     *     always: string[],
     *     conditional: string[],
     *     mixins: string[],
     *     nested: array<int, array{selector: string, specificity: string, always: string[]}>
     * }
     */
    public static function analyze(string $tss): array
    {
        $facts = ['always' => [], 'conditional' => [], 'mixins' => [], 'nested' => []];
        self::walk($tss, false, '&', $facts);

        foreach (['always', 'conditional', 'mixins'] as $key) {
            $facts[$key] = array_values(array_unique($facts[$key]));
        }

        $facts['conditional'] = array_values(array_diff($facts['conditional'], $facts['always']));

        return $facts;
    }

    /**
     * CSS specificity of a TSS selector relative to the element, as "a,b,c".
     * "&" is the element's generated class; a selector without "&" is a
     * descendant of it. For a list, the highest part wins.
     */
    public static function specificity(string $selector): string
    {
        $best = [0, 1, 0];

        foreach (self::splitList($selector) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (! str_contains($part, '&')) {
                $part = '& ' . $part;
            }

            $score = self::score(str_replace('&', '.m', $part));

            if ($score > $best) {
                $best = $score;
            }
        }

        return implode(',', $best);
    }

    /**
     * @param array{always: string[], conditional: string[], mixins: string[], nested: array<int, array<string, mixed>>} $facts
     */
    private static function walk(string $body, bool $conditional, string $selector, array &$facts): void
    {
        foreach (self::statements($body) as $statement) {
            $head = trim($statement['head']);

            if ($statement['block'] === null) {
                self::declaration($head, $conditional, $selector, $facts);
                continue;
            }

            if (preg_match('/^@([\w-]+)/', $head, $at)) {
                $name = strtolower($at[1]);

                if (in_array($name, self::DEFINITION_AT, true)) {
                    continue;
                }

                if ($name === 'include' && preg_match('/^@include\s+([\w-]+)/', $head, $mixin)) {
                    $facts['mixins'][] = $mixin[1];
                }

                // Flow (@if, @else, @each), media and any other at-rule: what
                // is inside only sometimes applies.
                self::walk($statement['block'], true, $selector, $facts);
                continue;
            }

            // A nested style rule.
            $nested = trim(preg_replace('/\s+/', ' ', $head) ?? $head);
            $inner = ['always' => [], 'conditional' => [], 'mixins' => [], 'nested' => []];
            self::walk($statement['block'], false, $nested, $inner);

            $facts['nested'][] = [
                'selector'    => $nested,
                'specificity' => self::specificity($nested),
                'always'      => $conditional ? [] : array_values(array_unique($inner['always'])),
            ];

            $facts['mixins'] = array_merge($facts['mixins'], $inner['mixins']);
            $facts['conditional'] = array_merge($facts['conditional'], $inner['conditional'], $conditional ? $inner['always'] : []);

            foreach ($inner['nested'] as $deeper) {
                $facts['nested'][] = $deeper;
            }
        }
    }

    /**
     * @param array{always: string[], conditional: string[], mixins: string[], nested: array<int, array<string, mixed>>} $facts
     */
    private static function declaration(string $text, bool $conditional, string $selector, array &$facts): void
    {
        if ($text === '' || $text[0] === '$') {
            return; // Empty, or a variable.
        }

        if (preg_match('/^@include\s+([\w-]+)/', $text, $match)) {
            $facts['mixins'][] = $match[1];

            return;
        }

        if ($text[0] === '@') {
            return; // @warn, @debug, @return and the like.
        }

        if (preg_match('/^(-?[a-z][a-z-]*)\s*:/i', $text, $match)) {
            $facts[$conditional ? 'conditional' : 'always'][] = strtolower($match[1]);
        }
    }

    /**
     * Top-level statements of a block: declarations (head only) and blocks
     * (head and body), with comments, strings and #{} interpolation skipped.
     *
     * @return array<int, array{head: string, block: string|null}>
     */
    private static function statements(string $body): array
    {
        $out = [];
        $length = strlen($body);
        $start = 0;
        $paren = 0;
        $i = 0;
        $clean = '';

        while ($i < $length) {
            $char = $body[$i];
            $next = $body[$i + 1] ?? '';

            if ($char === '/' && $next === '/') {
                $end = strpos($body, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($body, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = self::stringEnd($body, $i);
                $clean .= substr($body, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            if ($char === '#' && $next === '{') {
                $close = self::matchingBrace($body, $i + 1);
                $end = $close ?? $length - 1;
                $clean .= substr($body, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            if ($char === '(') {
                $paren++;
            } elseif ($char === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($char === '{' && $paren === 0) {
                $close = self::matchingBrace($body, $i) ?? $length;
                $out[] = ['head' => $clean, 'block' => substr($body, $i + 1, max(0, $close - $i - 1))];
                $clean = '';
                $i = $close + 1;
                continue;
            } elseif ($char === ';' && $paren === 0) {
                $out[] = ['head' => $clean, 'block' => null];
                $clean = '';
                $i++;
                continue;
            }

            $clean .= $char;
            $i++;
        }

        if (trim($clean) !== '') {
            $out[] = ['head' => $clean, 'block' => null];
        }

        return $out;
    }

    /**
     * The offset of the brace that closes the one at $open, or null.
     */
    private static function matchingBrace(string $text, int $open): ?int
    {
        $depth = 0;
        $length = strlen($text);

        for ($i = $open; $i < $length; $i++) {
            $char = $text[$i];
            $next = $text[$i + 1] ?? '';

            if ($char === '/' && $next === '/' && $i > $open) {
                $end = strpos($text, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($text, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = self::stringEnd($text, $i);
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function stringEnd(string $text, int $start): int
    {
        $quote = $text[$start];
        $length = strlen($text);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($text[$i] === '\\') {
                $i++;
                continue;
            }

            if ($text[$i] === $quote || $text[$i] === "\n") {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * @return string[]
     */
    private static function splitList(string $selector): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($selector) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function score(string $selector): array
    {
        // Interpolation and :where() add nothing; :is(), :not() and :has()
        // count as their argument.
        $selector = preg_replace('/#\{[^}]*\}/', '', $selector) ?? $selector;
        $selector = preg_replace('/:where\((?:[^()]|\([^()]*\))*\)/', '', $selector) ?? $selector;
        $selector = preg_replace('/:(?:is|not|has)\(((?:[^()]|\([^()]*\))*)\)/', ' $1', $selector) ?? $selector;

        $ids = preg_match_all('/#[\w-]+/', $selector);
        $classes = preg_match_all('/\.[\w-]+|\[[^\]]*\]|(?<!:):(?!:)(?!before\b|after\b|first-line\b|first-letter\b)[\w-]+(?:\([^)]*\))?/', $selector);
        $elements = preg_match_all('/::[\w-]+|(?<!:):(?:before|after|first-line|first-letter)\b|(?:^|[\s>+~])(?:[a-z][\w-]*)/i', $selector);

        return [(int) $ids, (int) $classes, (int) $elements];
    }
}
