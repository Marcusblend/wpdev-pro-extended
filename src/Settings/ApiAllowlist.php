<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * The External API allowlist: which endpoints Cornerstone may call.
 *
 * Stored as one newline-separated option. Cornerstone matches a request
 * against it by prefix, which makes the exact spelling of an entry the whole
 * security boundary: "https://api.example.com" without a trailing slash also
 * matches "https://api.example.com.attacker.net", and a bare host with no
 * scheme matches nothing at all. So entries are normalised and anything
 * ambiguous is refused rather than stored and quietly wrong.
 *
 * Turning the feature on is not done here. That is a decision about what a
 * site may reach out to, and it belongs to a person.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ApiAllowlist
{
    public const OPTION = 'cs_api_extension_allowlist';

    /**
     * Work out the new allowlist.
     *
     * @param  string   $stored The option as it stands.
     * @param  string[] $add
     * @param  string[] $remove
     * @return array{entries: string[], added: string[], removed: string[], unchanged: string[], errors: string[], normalized: array<string, string>}
     */
    public static function plan(string $stored, array $add, array $remove): array
    {
        $entries = self::parse($stored);
        $errors = [];
        $added = [];
        $unchanged = [];
        $normalized = [];

        foreach ($add as $entry) {
            if (! is_string($entry)) {
                $errors[] = 'Every entry must be a URL.';
                continue;
            }

            $clean = self::normalize($entry, $errors);

            if ($clean === null) {
                continue;
            }

            if ($clean !== trim($entry)) {
                $normalized[trim($entry)] = $clean;
            }

            if (in_array($clean, $entries, true)) {
                $unchanged[] = $clean;
                continue;
            }

            $entries[] = $clean;
            $added[] = $clean;
        }

        $removed = [];

        foreach ($remove as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $target = rtrim(trim($entry), '/');

            foreach ($entries as $index => $existing) {
                if (rtrim($existing, '/') === $target) {
                    unset($entries[$index]);
                    $removed[] = $existing;
                }
            }
        }

        return [
            'entries'    => array_values($entries),
            'added'      => $added,
            'removed'    => $removed,
            'unchanged'  => $unchanged,
            'errors'     => $errors,
            'normalized' => $normalized,
        ];
    }

    /**
     * @return string[]
     */
    public static function parse(string $stored): array
    {
        $entries = [];

        foreach (explode("\n", $stored) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * @param string[] $entries
     */
    public static function encode(array $entries): string
    {
        return implode("\n", $entries);
    }

    /**
     * Normalise one entry, or refuse it.
     *
     * @param string[] $errors
     */
    public static function normalize(string $entry, array &$errors): ?string
    {
        $entry = trim($entry);

        if ($entry === '') {
            $errors[] = 'An allowlist entry cannot be empty.';

            return null;
        }

        $parts = parse_url($entry);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $errors[] = sprintf('"%s" needs a scheme and a host, as in "https://api.example.com/v1/".', $entry);

            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'https') {
            $errors[] = sprintf('"%s" is %s; an allowlist entry must be https, or the site\'s own requests are readable in transit.', $entry, $scheme === 'http' ? 'plain http' : 'not http(s)');

            return null;
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            $errors[] = sprintf('"%s" carries a query or fragment. Cornerstone matches by prefix, so allow the path and let the caller add the rest.', $entry);

            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        // A prefix match on an entry with no trailing slash also matches a
        // longer host or path that merely starts the same way, so every entry
        // ends in one.
        $path = rtrim($path, '/') . '/';

        return sprintf('https://%s%s%s', $host, $port, $path);
    }
}
