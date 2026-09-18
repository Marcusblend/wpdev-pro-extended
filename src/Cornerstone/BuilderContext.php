<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Cornerstone assembles some of its registries only for a builder request.
 *
 * Control definitions and prefab elements are registered through cs_remember /
 * cs_recall, which return a fallback and raise a warning unless the request is
 * a builder one. Cornerstone marks a request as such by firing
 * cs_before_late_data before it assembles its late data, so this fires the same
 * action — once per request, from read paths only, never while saving — and
 * quiets the warnings Cornerstone raises while it decides.
 */
final class BuilderContext
{
    private static bool $entered = false;

    /**
     * Put this request into builder context, if it is not already.
     */
    public static function enter(): void
    {
        if (self::$entered || ! function_exists('cornerstone')) {
            return;
        }

        self::$entered = true;
        do_action('cs_before_late_data');
    }

    /**
     * Run a read with Cornerstone's own warnings quieted.
     *
     * @template T
     * @param  callable(): T $read
     * @param  T             $fallback
     * @return T
     */
    public static function read(callable $read, mixed $fallback = null): mixed
    {
        self::enter();

        set_error_handler(static fn (): bool => true, E_USER_WARNING | E_WARNING);

        try {
            return $read();
        } catch (\Throwable) {
            return $fallback;
        } finally {
            restore_error_handler();
        }
    }
}
