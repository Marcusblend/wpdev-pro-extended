<?php

declare(strict_types=1);

namespace ProExtended\Commands;

use ProExtended\Elements\SchemaExtractor;

/**
 * `wp pe warm` — store every element type's control surface.
 */
final class WarmCommand
{
    public function __construct(
        private readonly SchemaExtractor $schema,
    ) {}

    /**
     * Register the CLI command.
     */
    public function register(): void
    {
        \WP_CLI::add_command('pe warm', $this);
    }

    /**
     * Build and store the control surface of every element type.
     *
     * The css-over-control and literal-color warnings read stored surfaces
     * only, because validation runs inside every write and building one there
     * would enter builder context mid-save. Run this after each deploy or
     * Cornerstone update so they give the same answer on every run from the
     * first write on. It reads Cornerstone's Inspector data the way
     * get_element_schema does and writes one non-autoloaded option per
     * element type; the element definitions cache is cleared first.
     *
     * Exits non-zero when Cornerstone returns no Inspector data at all.
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
     *     wp pe warm
     *     wp pe warm --format=json
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (! function_exists('cornerstone')) {
            \WP_CLI::error('Cornerstone is not active, so there are no element types to warm.');
        }

        $deleted = $this->schema->clearCache();
        $warm = $this->schema->warmSurfaces();
        $format = $assocArgs['format'] ?? 'table';
        $version = $warm['cornerstone'] !== '' ? $warm['cornerstone'] : '(unknown version)';

        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode([
                'cornerstone' => $warm['cornerstone'],
                'cleared'     => $deleted,
                'stored'      => $warm['stored'],
                'empty'       => $warm['empty'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($warm['stored'] === []) {
                \WP_CLI::halt(1);
            }

            return;
        }

        $rows = [];

        foreach ($warm['stored'] as $type) {
            $rows[] = ['type' => $type, 'status' => 'stored'];
        }

        foreach ($warm['empty'] as $type) {
            $rows[] = ['type' => $type, 'status' => 'no Inspector data'];
        }

        if ($rows !== []) {
            \WP_CLI\Utils\format_items('table', $rows, ['type', 'status']);
        }

        if ($warm['stored'] === []) {
            \WP_CLI::error(sprintf('Cornerstone %s returned no Inspector data, so no control surfaces were stored.', $version));
        }

        \WP_CLI::success(sprintf(
            'Stored %d control surfaces for Cornerstone %s (%d types have no Inspector data).',
            count($warm['stored']),
            $version,
            count($warm['empty'])
        ));
    }
}
