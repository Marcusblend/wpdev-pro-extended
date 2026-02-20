<?php

declare(strict_types=1);

namespace ProExtended\Commands;

use ProExtended\Layouts\LayoutService;

/**
 * WP-CLI commands for layout management.
 *
 * ## EXAMPLES
 *
 *     wp pe layout list
 *     wp pe layout export 42
 *     wp pe layout backup 42
 *     wp pe layout restore 42
 */
final class LayoutCommand
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    /**
     * Register the CLI commands.
     */
    public function register(): void
    {
        \WP_CLI::add_command('pe layout', $this);
    }

    /**
     * List all Cornerstone layouts.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Filter by post type.
     *
     * [--format=<format>]
     * : Output format. Default: table.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp pe layout list
     *     wp pe layout list --type=cs_header --format=json
     *
     * @subcommand list
     */
    public function list_(array $args, array $assocArgs): void
    {
        $type = $assocArgs['type'] ?? null;
        $types = $type ? [$type] : [];

        $layouts = $this->layouts->listAll($types);

        if (empty($layouts)) {
            \WP_CLI::warning('No layouts found.');
            return;
        }

        $format = $assocArgs['format'] ?? 'table';
        \WP_CLI\Utils\format_items($format, $layouts, ['id', 'title', 'type', 'status', 'modified']);
    }

    /**
     * Export a layout to JSON.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID to export.
     *
     * [--output=<file>]
     * : Output file path. Default: stdout.
     *
     * ## EXAMPLES
     *
     *     wp pe layout export 42
     *     wp pe layout export 42 --output=layout-42.json
     *
     * @subcommand export
     */
    public function export(array $args, array $assocArgs): void
    {
        $postId = (int) ($args[0] ?? 0);

        if ($postId <= 0) {
            \WP_CLI::error('Post ID is required.');
        }

        try {
            $envelope = $this->layouts->get($postId);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }

        $json = wp_json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $output = $assocArgs['output'] ?? null;

        if ($output) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($output, $json);
            \WP_CLI::success(sprintf('Layout exported to %s', $output));
        } else {
            echo $json . "\n";
        }
    }

    /**
     * Import a layout from JSON.
     *
     * ## OPTIONS
     *
     * <file>
     * : JSON file to import.
     *
     * [--post_id=<id>]
     * : Override the target post ID.
     *
     * ## EXAMPLES
     *
     *     wp pe layout import layout-42.json
     *     wp pe layout import layout.json --post_id=99
     *
     * @subcommand import
     */
    public function import(array $args, array $assocArgs): void
    {
        $file = $args[0] ?? '';

        if (empty($file) || ! file_exists($file)) {
            \WP_CLI::error('File not found: ' . $file);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json = file_get_contents($file);
        $envelope = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \WP_CLI::error('Invalid JSON: ' . json_last_error_msg());
        }

        $postId = (int) ($assocArgs['post_id'] ?? $envelope['post_id'] ?? 0);

        if ($postId <= 0) {
            \WP_CLI::error('Could not determine target post ID. Use --post_id=<id>.');
        }

        $data = $envelope['data'] ?? null;

        if ($data === null) {
            \WP_CLI::error('No "data" key found in the JSON envelope.');
        }

        // Backup before import.
        try {
            $backupId = $this->layouts->backup($postId);
            \WP_CLI::log(sprintf('Backup created: %s', $backupId));
        } catch (\Throwable) {
            \WP_CLI::warning('Could not create backup (post may not have existing data).');
        }

        $success = $this->layouts->save($postId, $data);

        if ($success) {
            \WP_CLI::success(sprintf('Layout imported to post %d.', $postId));
        } else {
            \WP_CLI::error('Failed to import layout.');
        }
    }

    /**
     * Create a backup of a layout.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID to back up.
     *
     * ## EXAMPLES
     *
     *     wp pe layout backup 42
     *
     * @subcommand backup
     */
    public function backup(array $args, array $assocArgs): void
    {
        $postId = (int) ($args[0] ?? 0);

        if ($postId <= 0) {
            \WP_CLI::error('Post ID is required.');
        }

        try {
            $backupId = $this->layouts->backup($postId);
            \WP_CLI::success(sprintf('Backup created with ID "%s" for post %d.', $backupId, $postId));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Restore a layout from backup.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID to restore.
     *
     * [--backup_id=<id>]
     * : The backup ID to restore from. Defaults to most recent.
     *
     * ## EXAMPLES
     *
     *     wp pe layout restore 42
     *     wp pe layout restore 42 --backup_id=1708400000
     *
     * @subcommand restore
     */
    public function restore(array $args, array $assocArgs): void
    {
        $postId   = (int) ($args[0] ?? 0);
        $backupId = $assocArgs['backup_id'] ?? null;

        if ($postId <= 0) {
            \WP_CLI::error('Post ID is required.');
        }

        try {
            $this->layouts->restore($postId, $backupId);
            \WP_CLI::success(sprintf('Layout restored for post %d.', $postId));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }
}
