<?php

declare(strict_types=1);

namespace ProExtended\Commands;

use ProExtended\Site\Health;

/**
 * `wp pe doctor` — check that Pro Extended can work on this site.
 */
final class DoctorCommand
{
    public function __construct(
        private readonly Health $health,
    ) {}

    /**
     * Register the CLI command.
     */
    public function register(): void
    {
        \WP_CLI::add_command('pe doctor', $this);
    }

    /**
     * Check permalinks, application passwords, Cornerstone, capabilities, the
     * component registry, cache purging and the Pro Extended settings.
     *
     * Exits non-zero only when a check fails; warnings are informational.
     * With --format=json only the JSON report is printed.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp pe doctor --user=1
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (get_current_user_id() === 0) {
            \WP_CLI::error('Run this with --user=<administrator ID> so the capability checks describe a real user.');
        }

        $report = $this->health->report();
        $checks = $this->health->checks($report);
        $format = $assocArgs['format'] ?? 'table';

        $count = static fn(string $status): int => count(array_filter($checks, static fn(array $c): bool => $c['status'] === $status));
        $failures = $count('fail');

        if ($format === 'json') {
            // JSON only on stdout, so the output can be piped; the exit code
            // still reports failures.
            \WP_CLI::line((string) wp_json_encode(['checks' => $checks, 'report' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($failures > 0) {
                \WP_CLI::halt(1);
            }

            return;
        }

        $rows = array_map(static fn(array $line): array => [
            'status' => strtoupper($line['status']),
            'check'  => $line['check'],
            'detail' => $line['detail'],
        ], $checks);

        \WP_CLI\Utils\format_items('table', $rows, ['status', 'check', 'detail']);

        $summary = sprintf('%d passed, %d warnings, %d failed.', $count('pass'), $count('warn'), $failures);

        if ($failures > 0) {
            \WP_CLI::error($summary);
        }

        \WP_CLI::success($summary);
    }
}
