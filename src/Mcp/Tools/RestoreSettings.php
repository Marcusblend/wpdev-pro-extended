<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Support\Args;
use ProExtended\Support\Json;

final class RestoreSettings implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['key', 'backup_id', 'dry_run'];

    public function __construct(
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'restore_settings';
    }

    public function description(): string
    {
        return 'Put a global settings backup back (colors, fonts, font_config or global_css). Defaults to the latest backup. The current value is backed up first; an option that did not exist when the backup was taken is deleted. Use dry_run: true to preview.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['key'],
            'properties' => [
                'key' => [
                    'type'        => 'string',
                    'description' => 'Which settings to restore: colors, fonts, font_config, global_css, or "option:<theme option name>" for one Theme Option written by update_theme_options.',
                ],
                'backup_id' => [
                    'type'        => 'string',
                    'description' => 'Optional. Backup to restore. Default: the latest.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report what would change and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $key = Args::string($arguments, 'key', null, 200);

        if ($key !== null && ! in_array($key, SettingsBackups::KEYS, true) && ! str_starts_with($key, SettingsBackups::OPTION_KEY_PREFIX)) {
            throw new \InvalidArgumentException(sprintf('key must be one of %s, or "%s<theme option name>" for a theme option.', implode(', ', SettingsBackups::KEYS), SettingsBackups::OPTION_KEY_PREFIX));
        }

        if ($key === null) {
            throw new \InvalidArgumentException('"key" is required.');
        }

        $backupId = Args::string($arguments, 'backup_id', null, 100);
        $dryRun = Args::bool($arguments, 'dry_run', false);

        if ($this->needsEditCss($key, $backupId) && ! current_user_can('edit_css')) {
            throw new ToolPermissionException(sprintf('Restoring %s requires the edit_css capability.', $key));
        }

        $restore = $this->backups->restore($key, $backupId, $dryRun, 'restore_settings');

        return [
            'restored'                   => ! $dryRun,
            'dry_run'                    => $dryRun,
            'key'                        => $restore['key'],
            'option'                     => $restore['option'],
            'restored_backup_id'         => $restore['backup_id'],
            'restored_backup_created_at' => $restore['backup_created_at'],
            'action'                     => $restore['action'],
            'changed'                    => $restore['changed'],
            'before'                     => $restore['before'],
            'after'                      => $restore['after'],
            // The backup of the value that was just replaced.
            'backup_id'                  => $restore['pre_restore_backup_id'] ?? null,
            'write_path'                 => $restore['purge']['path'] ?? null,
            'warnings'                   => [],
        ];
    }

    /**
     * Global CSS always needs edit_css; a font config does when either the
     * backup or the current value has custom @font-face CSS.
     */
    private function needsEditCss(string $key, ?string $backupId): bool
    {
        if ($key === 'global_css') {
            return true;
        }

        if ($key !== 'font_config') {
            return false;
        }

        $entry = $this->backups->find($key, $backupId);
        $restored = Json::decodeStored($entry['value'] ?? null) ?? [];
        $current = Json::decodeStored(get_option('cornerstone_font_config', '')) ?? [];

        return trim((string) ($restored['customFontFaceCSS'] ?? '')) !== ''
            || trim((string) ($current['customFontFaceCSS'] ?? '')) !== '';
    }

    public function annotations(): array
    {
        return Annotations::write('Restore Settings', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
