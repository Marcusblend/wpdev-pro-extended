<?php

declare(strict_types=1);

/**
 * Minimal harness for the plain-PHP units (no WordPress, no Composer).
 *
 *     php tests/unit/run.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'ProExtended\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/stubs.php';

final class T
{
    public static int $passed = 0;
    /** @var string[] */
    public static array $failures = [];
    public static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
    }

    public static function ok(bool $condition, string $label, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            echo "PASS  " . self::$group . " > {$label}\n";
            return;
        }

        self::$failures[] = self::$group . " > {$label}" . ($detail !== '' ? " ({$detail})" : '');
        echo "FAIL  " . self::$group . " > {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }

    public static function same(mixed $expected, mixed $actual, string $label): void
    {
        self::ok($expected === $actual, $label, $expected === $actual ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public static function throws(callable $fn, string $label, string $contains = ''): void
    {
        try {
            $fn();
            self::ok(false, $label, 'no exception');
        } catch (\Throwable $e) {
            self::ok($contains === '' || stripos($e->getMessage(), $contains) !== false, $label, $e->getMessage());
        }
    }
}
