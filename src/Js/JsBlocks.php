<?php

declare(strict_types=1);

namespace ProExtended\Js;

use ProExtended\Support\NamedBlocks;

/**
 * Named blocks that Pro Extended manages inside Global JS.
 *
 *     // pe:begin <name>
 *     ...
 *     // pe:end <name>
 *
 * Each marker is a whole line. The block rules (and the guarantee that the
 * script outside the blocks never changes) are NamedBlocks'; this adds what is
 * specific to JavaScript that Cornerstone prints inside its own script tag.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class JsBlocks
{
    public const MAX_BYTES = 262144; // 256 KB

    private static ?NamedBlocks $engine = null;

    public static function engine(): NamedBlocks
    {
        return self::$engine ??= new NamedBlocks(
            '/^\/\/ pe:begin ([a-z0-9][a-z0-9-]{0,62})\r?$/m',
            '/^\/\/ pe:end ([a-z0-9][a-z0-9-]{0,62})\r?$/m',
            '// pe:begin %s',
            '// pe:end %s',
            'Global JS'
        );
    }

    /**
     * @return array{blocks: array<int, array<string, mixed>>, errors: string[]}
     */
    public static function parse(string $js): array
    {
        return self::engine()->parse($js);
    }

    public static function outside(string $js): string
    {
        return self::engine()->outside($js);
    }

    /**
     * @return array{js: string, created: bool, before: string|null}
     */
    public static function upsert(string $js, string $name, string $content, string $position = 'end'): array
    {
        $result = self::engine()->upsert($js, $name, $content, $position);

        return ['js' => $result['text'], 'created' => $result['created'], 'before' => $result['before']];
    }

    /**
     * @return array{js: string, before: string}
     */
    public static function remove(string $js, string $name): array
    {
        $result = self::engine()->remove($js, $name);

        return ['js' => $result['text'], 'before' => $result['before']];
    }

    public static function assertName(string $name): void
    {
        NamedBlocks::assertName($name);
    }

    /**
     * Reasons a script cannot be stored.
     *
     * Cornerstone prints Global JS inside its own <script> tag
     * (EnqueueScripts::globalCustomJs), so a tag in the content would close it
     * early or nest another; "<?" has no business in a script either.
     *
     * @return string[]
     */
    public static function inputErrors(string $js, bool $allowMarkers = false): array
    {
        $errors = [];

        if (preg_match('/<\s*\/?\s*script/i', $js)) {
            $errors[] = 'Global JS is printed inside Cornerstone\'s own <script> tag, so it must not contain "<script" or "</script"; write only the JavaScript.';
        }

        if (str_contains($js, '<?')) {
            $errors[] = 'JavaScript must not contain "<?".';
        }

        if (! $allowMarkers && preg_match('/pe:(?:begin|end)\b/i', $js)) {
            $errors[] = 'JavaScript must not contain pe:begin or pe:end markers; blocks are managed by name.';
        }

        if (strlen($js) > self::MAX_BYTES) {
            $errors[] = 'The JavaScript is larger than 256 KB.';
        }

        return $errors;
    }

    /**
     * Things worth a second look that do not block the write.
     *
     * @return string[]
     */
    public static function warnings(string $js): array
    {
        $warnings = [];

        if (str_contains($js, '{{') || str_contains($js, '{%')) {
            $warnings[] = 'Cornerstone runs Global JS through Dynamic Content (and Twig, when it is on) before printing it, so "{{" and "{%" are read as tokens or Twig. Keep them out of plain JavaScript.';
        }

        if (preg_match('/\beval\s*\(|new\s+Function\s*\(/', $js)) {
            $warnings[] = 'Uses eval or new Function.';
        }

        if (preg_match('/document\.write\s*\(/', $js)) {
            $warnings[] = 'Uses document.write, which blocks rendering and is ignored after the page loads.';
        }

        return $warnings;
    }
}
