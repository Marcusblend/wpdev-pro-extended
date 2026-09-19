<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Settings\SettingsBackups;
use ProExtended\Site\SiteSnapshot;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class RestoreSnapshot implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly SiteSnapshot $snapshot,
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'restore_snapshot';
    }

    public function description(): string
    {
        return 'Put a snapshot from create_snapshot back. Writes the palette, fonts, Global CSS, Theme Options, Global Variables and global parameters; menus and documents are reported as skipped, because rebuilding them is create_menu, update_menu, deploy_layout and import_tco rather than an option write. Every option is backed up before it is changed, so restore_settings can undo any single one. Without confirm: true nothing is written and the response is the diff, which is the way to run it first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['snapshot'],
            'properties' => [
                'snapshot' => [
                    'type'        => ['object', 'string'],
                    'description' => 'A snapshot from create_snapshot. Accepts a JSON string.',
                ],
                'parts' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string', 'enum' => SiteSnapshot::PARTS],
                    'description' => 'Optional. Which parts to restore. Default: everything the snapshot carries.',
                ],
                'confirm' => [
                    'type'        => 'boolean',
                    'description' => 'Required to write. Without it the response is the diff only. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['snapshot', 'parts', 'confirm'], 'arguments');

        $arguments = JsonArgs::decode($arguments, ['snapshot']);
        $snapshot = Args::object($arguments, 'snapshot');
        $confirm = Args::bool($arguments, 'confirm', false);
        $parts = Args::list($arguments, 'parts', 0, 20) ?? [];

        if ($snapshot === null || $snapshot === []) {
            throw new \InvalidArgumentException('Pass snapshot: the object create_snapshot returned.');
        }

        $carried = array_values(array_intersect(SiteSnapshot::PARTS, array_keys($snapshot)));

        if ($carried === []) {
            throw new \InvalidArgumentException('That object carries none of the parts a snapshot holds (' . implode(', ', SiteSnapshot::PARTS) . ').');
        }

        $parts = $parts === [] ? $carried : array_map('strval', $parts);
        $unknown = array_diff($parts, SiteSnapshot::PARTS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown parts: %s.', implode(', ', $unknown)));
        }

        $backups = [];
        $backup = function (string $option) use (&$backups): void {
            try {
                $entry = $this->backups->backup(SettingsBackups::OPTION_KEY_PREFIX . $option, 'restore_snapshot');
                $backups[$option] = $entry['backup_id'] ?? null;
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('"%s" could not be backed up, so it was not written: %s', $option, $e->getMessage()));
            }
        };

        $outcome = $this->snapshot->restore($snapshot, $parts, ! $confirm, $backup);

        $result = [
            'confirmed' => $confirm,
            'from'      => $snapshot['meta'] ?? null,
            'parts'     => $parts,
            'changes'   => $outcome['changes'],
            'skipped'   => $outcome['skipped'],
        ];

        if ($outcome['changes'] === []) {
            $result['note'] = 'This site already matches the snapshot for the parts given.';

            return $result;
        }

        if (! $confirm) {
            $result['note'] = 'Nothing was written. Call again with confirm: true to apply these.';

            return $result;
        }

        $result['written'] = $outcome['written'];
        $result['backups'] = $backups;

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::write('Restore Snapshot', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
