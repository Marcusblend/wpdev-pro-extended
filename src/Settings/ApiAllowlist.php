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
 * It fails closed. Cornerstone reads an empty allowlist as "allow every URL",
 * so while the feature is on no change may leave the list empty. And an
 * entry may not point back inside: a loopback, private-network or link-local
 * host would let a looper read the site's own admin endpoints, the host's
 * metadata service or the office network, and a user name in the URL is
 * refused rather than quietly dropped.
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
     * @param  string   $stored    The option as it stands.
     * @param  string[] $add
     * @param  string[] $remove
     * @param  bool     $featureOn Whether the External API is on, when an empty list means "allow everything".
     * @return array{entries: string[], added: string[], removed: string[], unchanged: string[], errors: string[], normalized: array<string, string>}
     */
    public static function plan(string $stored, array $add, array $remove, bool $featureOn = false): array
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

        if ($featureOn && $entries === []) {
            $errors[] = 'That would leave the allowlist empty while the External API is on, and Cornerstone reads an empty allowlist as "allow every URL". Add the endpoints that should stay allowed in the same call, or have a person turn the feature off first.';
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

        if (isset($parts['user']) || isset($parts['pass']) || preg_match('~^[a-z][a-z0-9+.-]*://[^/?#]*@~i', $entry) === 1) {
            $errors[] = sprintf('"%s" carries a user name or password before the host. Credentials do not belong in an allowlist entry, and a URL with them is easy to misread (https://api.example.com@other.host goes to other.host), so it is refused rather than stripped.', $entry);

            return null;
        }

        $host = strtolower((string) $parts['host']);
        $internal = self::internalHost($host);

        if ($internal !== null) {
            $errors[] = sprintf('"%s" points at %s. An External API looper could then read what only the server can reach, so it is refused.', $entry, $internal);

            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        // A prefix match on an entry with no trailing slash also matches a
        // longer host or path that merely starts the same way, so every entry
        // ends in one.
        $path = rtrim($path, '/') . '/';

        return sprintf('https://%s%s%s', $host, $port, $path);
    }

    /**
     * Why a host is inside the site's own network, or null when it is not.
     *
     * Only the host as written is checked: a public name that resolves to a
     * private address is not caught here.
     */
    public static function internalHost(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        $bare = trim($host, '[]');

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return 'localhost';
        }

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = (string) inet_pton($bare);

            // An IPv4 address written as IPv6 (::ffff:127.0.0.1) is that IPv4 address.
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
                return self::internalIpv4((string) inet_ntop(substr($packed, 12)));
            }

            $first = ord($packed[0]);
            $second = ord($packed[1]);

            return match (true) {
                $packed === str_repeat("\0", 15) . "\1" => 'the IPv6 loopback address',
                $packed === str_repeat("\0", 16)         => 'the unspecified IPv6 address',
                $first === 0xfe && ($second & 0xc0) === 0x80 => 'an IPv6 link-local address',
                ($first & 0xfe) === 0xfc                  => 'an IPv6 unique local address',
                default                                   => null,
            };
        }

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::internalIpv4($bare);
        }

        // 2130706433, 0x7f.1 and 127.1 are all 127.0.0.1 to curl. A host made
        // only of digits, dots and hex is an address in a form that hides what
        // it is.
        if (preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/', $host) === 1) {
            return 'an IP address written in a non-standard form';
        }

        return null;
    }

    private static function internalIpv4(string $ip): ?string
    {
        $long = ip2long($ip);

        if ($long === false) {
            return 'an address that cannot be read';
        }

        $in = static fn (string $network, int $bits): bool => ($long >> (32 - $bits)) === (ip2long($network) >> (32 - $bits));

        return match (true) {
            $in('127.0.0.0', 8)    => 'a loopback address',
            $in('10.0.0.0', 8), $in('172.16.0.0', 12), $in('192.168.0.0', 16) => 'a private network address (RFC 1918)',
            $in('169.254.0.0', 16) => 'a link-local address, where cloud hosts serve their metadata',
            $in('0.0.0.0', 8)      => 'an address for this host',
            default                => null,
        };
    }
}
