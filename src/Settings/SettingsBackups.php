<?php

declare(strict_types=1);

namespace ProExtended\Settings;

use ProExtended\Cornerstone\DocumentGateway;

/**
 * Backups of Cornerstone's global settings options.
 *
 * Each backup lives in its own option (autoload off) and records the value
 * exactly as stored, whether the option existed, and its autoload flag. The
 * last MAX_BACKUPS backups are kept per settings key; an index option lists
 * them.
 */
final class SettingsBackups
{
    public const MAX_BACKUPS = 10;

    public const KEYS = ['colors', 'fonts', 'font_config', 'global_css'];

    /** Prefix that marks a backup key as a plain theme option. */
    public const OPTION_KEY_PREFIX = 'option:';

    private const INDEX_OPTION = 'pe_settings_backups';
    private const ENTRY_PREFIX = 'pe_settings_backup_';

    public function __construct(
        private readonly DocumentGateway $gateway,
    ) {}

    /**
     * The option name behind a settings key.
     *
     * @throws \InvalidArgumentException
     */
    public function optionFor(string $key): string
    {
        return match ($key) {
            'colors'      => 'cornerstone_color_items',
            'fonts'       => 'cornerstone_font_items',
            'font_config' => 'cornerstone_font_config',
            'global_css'  => $this->gateway->globalCssKey(),
            default       => self::themeOptionFor($key),
        };
    }

    /**
     * Theme option keys are backed up under their own name, prefixed so they
     * cannot be confused with the four named settings above.
     *
     * update_theme_options writes one key at a time and backs each up first, so
     * restore_settings can put a single option back without touching the rest.
     */
    private static function themeOptionFor(string $key): string
    {
        if (str_starts_with($key, self::OPTION_KEY_PREFIX)) {
            $option = substr($key, strlen(self::OPTION_KEY_PREFIX));

            if ($option !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $option) === 1) {
                return $option;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown settings key "%s". Allowed: %s, or "%s<theme option name>".',
            $key,
            implode(', ', self::KEYS),
            self::OPTION_KEY_PREFIX
        ));
    }

    /**
     * Read an option exactly as stored, bypassing filters and defaults.
     *
     * @return array{exists: bool, raw: string|null, value: mixed, autoload: string|null}
     */
    public function readRaw(string $option): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));

        if (! $row) {
            return ['exists' => false, 'raw' => null, 'value' => null, 'autoload' => null];
        }

        return [
            'exists'   => true,
            'raw'      => (string) $row->option_value,
            'value'    => maybe_unserialize($row->option_value),
            'autoload' => (string) $row->autoload,
        ];
    }

    /**
     * Back up the current value of a settings key.
     *
     * @return array<string, mixed> The backup's index entry.
     */
    public function backup(string $key, string $sourceTool): array
    {
        $option = $this->optionFor($key);
        $state = $this->readRaw($option);
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));

        $entry = [
            'backup_id'   => $id,
            'key'         => $key,
            'option'      => $option,
            'existed'     => $state['exists'],
            'autoload'    => $state['autoload'],
            'bytes'       => $state['raw'] === null ? 0 : strlen($state['raw']),
            'sha1'        => $state['raw'] === null ? null : sha1($state['raw']),
            'created_at'  => gmdate('c'),
            'source_tool' => $sourceTool,
        ];

        // The options API neither slashes nor unslashes, so the stored value is
        // kept byte for byte.
        $stored = add_option($this->entryOption($key, $id), $entry + ['value' => $state['value']], '', false);

        if (! $stored) {
            throw new \RuntimeException(sprintf('Could not store a backup of %s.', $option));
        }

        $index = $this->index();
        $index[$key][] = $entry;

        while (count($index[$key]) > self::MAX_BACKUPS) {
            $old = array_shift($index[$key]);
            delete_option($this->entryOption($key, (string) $old['backup_id']));
        }

        update_option(self::INDEX_OPTION, $index, false);

        return $entry;
    }

    /**
     * Backups, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $key = null): array
    {
        $index = $this->index();
        $entries = [];

        // The four named settings, plus every theme option that has been backed
        // up: those arrive as "option:<name>" keys and are only in the index.
        $candidates = array_values(array_unique(array_merge(self::KEYS, array_keys($index))));

        foreach ($candidates as $candidate) {
            if ($key !== null && $candidate !== $key) {
                continue;
            }

            foreach ($index[$candidate] ?? [] as $entry) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn(array $a, array $b): int => strcmp((string) $b['backup_id'], (string) $a['backup_id']));

        return $entries;
    }

    /**
     * Load a backup, including its stored value. Defaults to the latest.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function find(string $key, ?string $backupId): array
    {
        $this->optionFor($key);
        $entries = $this->index()[$key] ?? [];

        if ($entries === []) {
            throw new \RuntimeException(sprintf('There are no backups for "%s".', $key));
        }

        if ($backupId === null || $backupId === '') {
            $backupId = (string) end($entries)['backup_id'];
        }

        $known = array_column($entries, 'backup_id');

        if (! in_array($backupId, $known, true)) {
            throw new \RuntimeException(sprintf('Backup "%s" not found for "%s".', $backupId, $key));
        }

        $entry = get_option($this->entryOption($key, $backupId));

        if (! is_array($entry) || ! array_key_exists('value', $entry)) {
            throw new \RuntimeException(sprintf('Backup "%s" for "%s" is missing its stored value.', $backupId, $key));
        }

        return $entry;
    }

    /**
     * Put a backup back. Backs up the current value first.
     *
     * @return array<string, mixed>
     */
    public function restore(string $key, ?string $backupId, bool $dryRun, string $sourceTool): array
    {
        $entry = $this->find($key, $backupId);
        $option = (string) $entry['option'];
        $current = $this->readRaw($option);

        $currentSha = $current['raw'] === null ? null : sha1($current['raw']);
        $changed = $current['exists'] !== (bool) $entry['existed'] || $currentSha !== $entry['sha1'];

        $result = [
            'key'               => $key,
            'option'            => $option,
            'backup_id'         => $entry['backup_id'],
            'backup_created_at' => $entry['created_at'],
            'action'            => $entry['existed'] ? 'write' : 'delete',
            'changed'           => $changed,
            'before'            => ['exists' => $current['exists'], 'bytes' => $current['raw'] === null ? 0 : strlen($current['raw'])],
            'after'             => ['exists' => (bool) $entry['existed'], 'bytes' => (int) $entry['bytes']],
            'dry_run'           => $dryRun,
        ];

        if ($dryRun) {
            return $result;
        }

        $result['pre_restore_backup_id'] = $this->backup($key, $sourceTool)['backup_id'];

        if (! $entry['existed']) {
            delete_option($option);
        } elseif ($key === 'global_css') {
            $this->gateway->updateThemeOption($option, $entry['value']);
            $this->applyAutoload($option, $entry['autoload']);
        } else {
            $autoload = $this->autoloadFlag($entry['autoload']);

            if ($current['exists']) {
                update_option($option, $entry['value'], $autoload);
            } else {
                add_option($option, $entry['value'], '', $autoload ?? true);
            }
        }

        if ($key === 'fonts' || $key === 'font_config') {
            delete_option('x_cache_google_fonts_request');
        }

        $result['purge'] = $this->gateway->purgeGenerated();

        return $result;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function index(): array
    {
        $index = get_option(self::INDEX_OPTION, []);

        return is_array($index) ? $index : [];
    }

    private function entryOption(string $key, string $id): string
    {
        return self::ENTRY_PREFIX . $key . '_' . $id;
    }

    /**
     * Map a stored autoload value to what update_option() accepts. WordPress
     * decides the internal "auto-on"/"auto-off" values itself.
     */
    private function autoloadFlag(mixed $autoload): ?bool
    {
        return match ($autoload) {
            'on', 'yes', 'auto-on'  => true,
            'off', 'no', 'auto-off' => false,
            default                 => null,
        };
    }

    private function applyAutoload(string $option, mixed $autoload): void
    {
        $flag = $this->autoloadFlag($autoload);

        if ($flag !== null && function_exists('wp_set_option_autoload')) {
            wp_set_option_autoload($option, $flag);
        }
    }
}
