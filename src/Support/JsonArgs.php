<?php

declare(strict_types=1);

namespace ProExtended\Support;

/**
 * Normalizes tool arguments that some MCP clients send as JSON strings.
 *
 * Several clients serialize object- and array-typed arguments (especially
 * union-typed ones) as JSON text instead of structured JSON. Every tool that
 * accepts an object or array passes its arguments through here *before*
 * validating them, so a layout sent as `"[{...}]"` is treated exactly like one
 * sent as `[{...}]`, and a string that is not JSON is rejected instead of being
 * stored as-is or silently dropped.
 *
 * @see https://github.com/renandadalte/wpdev-pro-extended/pull/1
 */
final class JsonArgs
{
    /**
     * Decode the named arguments in place when they arrive as JSON strings.
     *
     * Missing and null arguments are left alone. A string that does not decode
     * to an object or array is an error.
     *
     * @param  array<string, mixed> $arguments
     * @param  string[]             $keys
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function decode(array $arguments, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $arguments)) {
                $arguments[$key] = self::decodeValue($arguments[$key], $key);
            }
        }

        return $arguments;
    }

    /**
     * Decode one value if it is a JSON string; return anything else unchanged.
     *
     * @throws \InvalidArgumentException
     */
    public static function decodeValue(mixed $value, string $name): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" must be an object or array. A string was given that is not a JSON object or array.',
                $name
            ));
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" was given as a JSON string that could not be decoded: %s.',
                $name,
                $e->getMessage()
            ));
        }

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" must decode to an object or array.',
                $name
            ));
        }

        return $decoded;
    }
}
