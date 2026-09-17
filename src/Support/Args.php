<?php

declare(strict_types=1);

namespace ProExtended\Support;

/**
 * Small, strict argument readers shared by the tools.
 *
 * Every reader throws \InvalidArgumentException with a message that names the
 * argument, so a caller always learns which input was wrong and why.
 */
final class Args
{
    /**
     * Reject keys that are not in the allowlist.
     *
     * @param array<array-key, mixed> $input
     * @param string[]                $allowed
     *
     * @throws \InvalidArgumentException
     */
    public static function rejectUnknown(array $input, array $allowed, string $context): void
    {
        $unknown = array_diff(array_map('strval', array_keys($input)), $allowed);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown %s: %s. Allowed: %s.',
                count($unknown) === 1 ? 'key in ' . $context : 'keys in ' . $context,
                implode(', ', array_map(static fn($k) => '"' . $k . '"', $unknown)),
                $allowed === [] ? '(none)' : implode(', ', $allowed)
            ));
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function bool(array $args, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $args) || $args[$key] === null) {
            return $default;
        }

        $value = $args[$key];

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw new \InvalidArgumentException(sprintf('"%s" must be a boolean.', $key));
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function int(array $args, string $key, ?int $default, ?int $min = null, ?int $max = null): ?int
    {
        if (! array_key_exists($key, $args) || $args[$key] === null) {
            return $default;
        }

        $value = $args[$key];

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be an integer.', $key));
        }

        if ($min !== null && $value < $min) {
            throw new \InvalidArgumentException(sprintf('"%s" must be at least %d.', $key, $min));
        }

        if ($max !== null && $value > $max) {
            throw new \InvalidArgumentException(sprintf('"%s" must be at most %d.', $key, $max));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function string(array $args, string $key, ?string $default, int $maxLength = 0, bool $allowEmpty = true): ?string
    {
        if (! array_key_exists($key, $args) || $args[$key] === null) {
            return $default;
        }

        $value = $args[$key];

        if (! is_string($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a string.', $key));
        }

        if (! $allowEmpty && trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('"%s" must not be empty.', $key));
        }

        if ($maxLength > 0 && strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('"%s" must be at most %d bytes.', $key, $maxLength));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $args
     * @param string[]             $allowed
     */
    public static function enum(array $args, string $key, array $allowed, ?string $default): ?string
    {
        $value = self::string($args, $key, $default);

        if ($value !== null && ! in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown value "%s" for "%s". Allowed: %s.',
                $value,
                $key,
                implode(', ', $allowed)
            ));
        }

        return $value;
    }

    /**
     * Read an optional object argument (associative array).
     *
     * @param  array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    public static function object(array $args, string $key): ?array
    {
        if (! array_key_exists($key, $args) || $args[$key] === null) {
            return null;
        }

        $value = $args[$key];

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException(sprintf('"%s" must be an object.', $key));
        }

        return $value;
    }

    /**
     * Read an optional list argument.
     *
     * @param  array<string, mixed> $args
     * @return array<int, mixed>|null
     */
    public static function list(array $args, string $key, int $min = 0, int $max = 0): ?array
    {
        if (! array_key_exists($key, $args) || $args[$key] === null) {
            return null;
        }

        $value = $args[$key];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be an array.', $key));
        }

        if (count($value) < $min) {
            throw new \InvalidArgumentException(sprintf('"%s" needs at least %d item%s.', $key, $min, $min === 1 ? '' : 's'));
        }

        if ($max > 0 && count($value) > $max) {
            throw new \InvalidArgumentException(sprintf('"%s" accepts at most %d items.', $key, $max));
        }

        return $value;
    }

    /**
     * Throw when a positive post ID argument is missing or invalid.
     *
     * @param array<string, mixed> $args
     */
    public static function postId(array $args, string $key): int
    {
        $id = self::int($args, $key, null, 1);

        if ($id === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is required and must be a positive integer.', $key));
        }

        return $id;
    }
}
