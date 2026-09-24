<?php

declare(strict_types=1);

use ProExtended\Layouts\LayoutService;

T::group('LayoutService');

/**
 * Enough of $wpdb to watch what a settings restore asks of it.
 */
final class RestoreFakeWpdb
{
    public string $postmeta = 'wp_postmeta';
    public string $last_error = '';

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $calls = [];

    public function __construct(
        private int $rows = 0,
        private int|false $result = 1,
    ) {}

    public function prepare(string $query, mixed ...$args): string
    {
        return vsprintf(str_replace(['%d', '%s'], ['%s', "'%s'"], $query), $args);
    }

    public function get_var(string $query): string
    {
        $this->calls[] = ['get_var', $query];

        return (string) $this->rows;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $where */
    public function update(string $table, array $data, array $where, array $format = [], array $whereFormat = []): int|false
    {
        $this->calls[] = ['update', $data + $where];

        return $this->result;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data, array $format = []): int|false
    {
        $this->calls[] = ['insert', $data];

        return $this->result;
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where, array $format = []): int|false
    {
        $this->calls[] = ['delete', $where];

        return $this->result;
    }
}

// A page that had no settings when it was backed up -----------------------------

$wpdb = new RestoreFakeWpdb(1);
T::same('deleted', LayoutService::restoreSettingsRow($wpdb, 42, ['data' => '[]', 'source' => 'post_meta', 'settings' => null, 'had_settings' => false]), 'restoring removes settings written after the backup');
T::same([['delete', ['post_id' => 42, 'meta_key' => '_cornerstone_settings']]], $wpdb->calls, 'by deleting the settings row, and only that');

$gone = new RestoreFakeWpdb(0, 0);
T::same('deleted', LayoutService::restoreSettingsRow($gone, 42, ['had_settings' => false, 'settings' => null]), 'a row already gone is not an error');

// A page that had settings ----------------------------------------------------------

$existing = new RestoreFakeWpdb(1);
T::same('updated', LayoutService::restoreSettingsRow($existing, 42, ['had_settings' => true, 'settings' => '{"customCSS":""}']), 'settings that existed are written back over the current row');
T::same(['meta_value' => '{"customCSS":""}', 'post_id' => 42, 'meta_key' => '_cornerstone_settings'], $existing->calls[1][1], 'byte for byte');

$missing = new RestoreFakeWpdb(0);
T::same('inserted', LayoutService::restoreSettingsRow($missing, 42, ['had_settings' => true, 'settings' => '{}']), 'and inserted when the row has since been deleted');

// Old backups, from before the settings were captured -------------------------------

$old = new RestoreFakeWpdb(1);
T::same(null, LayoutService::restoreSettingsRow($old, 42, ['data' => '[]', 'source' => 'post_meta', 'created_at' => '2026-01-01T00:00:00+00:00']), 'a backup with no had_settings key leaves the row alone');
T::same([], $old->calls, 'without touching the database');

// Failures are reported ----------------------------------------------------------------

foreach ([
    'a delete' => ['had_settings' => false, 'settings' => null],
    'an update' => ['had_settings' => true, 'settings' => '{}'],
] as $what => $backup) {
    $failing = new RestoreFakeWpdb(1, false);
    $failing->last_error = 'Deadlock found';
    T::throws(static fn () => LayoutService::restoreSettingsRow($failing, 42, $backup), sprintf('%s the database refuses is an error', $what), 'Deadlock found');
}
