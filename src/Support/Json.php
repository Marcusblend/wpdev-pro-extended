<?php

declare(strict_types=1);

namespace ProExtended\Support;

/**
 * JSON helpers that match how Cornerstone stores its data.
 *
 * Cornerstone encodes documents and global settings with `cs_json_encode()`
 * (unescaped slashes) and stores them through `wp_slash()`. The options API
 * does not unslash on read, so option values come back still slashed, while
 * `post_content` comes back unslashed. `decodeStored()` accepts either.
 */
final class Json
{
    /**
     * Encode a value the way Cornerstone does.
     *
     * @throws \RuntimeException When the value cannot be encoded.
     */
    public static function encodeCs(mixed $value): string
    {
        $json = function_exists('cs_json_encode')
            ? cs_json_encode($value)
            : wp_json_encode($value, JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new \RuntimeException('Failed to encode data as JSON.');
        }

        return $json;
    }

    /**
     * Decode a stored JSON value, trying the plain form first and the slashed
     * form second (the same order as `cs_maybe_json_decode()`).
     *
     * Returns null when the value is not a JSON object or array.
     *
     * @return array<mixed>|null
     */
    public static function decodeStored(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $decoded = json_decode(wp_unslash($raw), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Encode a value for a tool response.
     */
    public static function pretty(mixed $value): string
    {
        $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }
}
