<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Settings\FontItems;
use ProExtended\Settings\ItemMerger;
use ProExtended\Settings\ItemRemover;
use ProExtended\Settings\ReferenceScanner;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Settings\StoredList;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class SetFonts implements ToolInterface, AnnotatedToolInterface
{
    private const ITEMS_OPTION  = 'cornerstone_font_items';
    private const CONFIG_OPTION = 'cornerstone_font_config';
    private const ARGUMENTS = ['fonts', 'group', 'config', 'allow_locked', 'dry_run', 'remove', 'force'];

    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
        private readonly ?ReferenceScanner $scanner = null,
    ) {}

    public function name(): string
    {
        return 'set_fonts';
    }

    public function description(): string
    {
        return 'Add or update global fonts by _id ({_id, title, family, source: google|typekit|custom|system, stack, weightNormal, weightBold, weightSelection, name, fallback}) and optionally merge font settings (config: googleSubsets, typekitKitID, googleDisabled, googleFontsURL, fontDisplay, customFontItems, customFontFaceCSS). name, stack and weights are derived the way Cornerstone derives them when omitted. Reference fonts as "global-ff:<_id>" and "global-fw:<_id>|fw-normal". remove lists font or group IDs to delete: a font still used by documents, element data, templates or theme options (such as the body font) is only removed with force: true, and the last font cannot be removed. Same group/allow_locked rules as set_colors. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'fonts' => [
                    'type'        => 'array',
                    'maxItems'    => 200,
                    'items'       => ['type' => 'object', 'required' => ['_id']],
                    'description' => 'Fonts to add or update. New fonts need _id, title, family and source.',
                ],
                'group' => [
                    'type'        => 'object',
                    'description' => 'Optional. {_id, title}: created if missing; new fonts are added to it.',
                ],
                'remove' => [
                    'type'        => 'array',
                    'minItems'    => 1,
                    'maxItems'    => 200,
                    'items'       => ['type' => 'string'],
                    'description' => 'Optional. Font or group IDs to delete. Removing a group keeps its fonts.',
                ],
                'force' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Remove fonts even when the site still uses them. Default: false.',
                ],
                'config' => [
                    'type'        => 'object',
                    'description' => 'Optional. Partial font settings to merge (customFontItems merge by _id; customFontFaceCSS needs edit_css).',
                ],
                'allow_locked' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Allow changing entries stored with locked: true. Default: false.',
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
        $arguments = JsonArgs::decode($arguments, ['fonts', 'group', 'config', 'remove']);
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $fonts = Args::list($arguments, 'fonts', 0, 200) ?? [];
        $configUpdate = Args::object($arguments, 'config');
        $remove = Args::list($arguments, 'remove', 1, 200);
        $allowLocked = Args::bool($arguments, 'allow_locked', false);
        $dryRun = Args::bool($arguments, 'dry_run', false);
        $force = Args::bool($arguments, 'force', false);

        if ($fonts === [] && $configUpdate === null && $remove === null) {
            throw new \InvalidArgumentException('Pass "fonts", "config", "remove", or a combination.');
        }

        if ($configUpdate !== null && array_key_exists('customFontFaceCSS', $configUpdate) && ! current_user_can('edit_css')) {
            throw new ToolPermissionException('Changing config.customFontFaceCSS requires the edit_css capability.');
        }

        $errors = [];
        $items = [];

        foreach ($fonts as $i => $font) {
            $item = FontItems::validateShape($font, sprintf('fonts[%d]', $i), $errors);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $group = SetColors::validateGroup(Args::object($arguments, 'group'), $errors);

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $storedItems = StoredList::listFrom($this->backups->readRaw(self::ITEMS_OPTION), self::ITEMS_OPTION);
        $storedConfig = StoredList::objectFrom($this->backups->readRaw(self::CONFIG_OPTION), self::CONFIG_OPTION);

        $config = $storedConfig;
        $configChanged = [];

        if ($configUpdate !== null) {
            $merged = FontItems::mergeConfig($storedConfig, $configUpdate, $errors);
            $config = $merged['config'];
            $configChanged = $merged['changed'];
        }

        $merge = ItemMerger::merge($storedItems, $items, $group, $allowLocked);
        $errors = array_merge($errors, $merge['errors']);

        if ($errors === []) {
            $catalog = $this->gateway->fontCatalog();
            $touched = array_merge($merge['added'], array_column($merge['updated'], '_id'));

            $before = [];

            foreach ($storedItems as $storedEntry) {
                if (is_array($storedEntry) && isset($storedEntry['_id']) && is_string($storedEntry['_id'])) {
                    $before[$storedEntry['_id']] = $storedEntry;
                }
            }

            foreach ($merge['items'] as $position => $entry) {
                if (! is_array($entry) || ! in_array($entry['_id'] ?? null, $touched, true)) {
                    continue;
                }

                $isNew = in_array($entry['_id'], $merge['added'], true);
                $merge['items'][$position] = FontItems::complete($entry, $config, $catalog, $isNew, $errors, $before[(string) $entry['_id']] ?? null);
            }

            // Report the completed entries.
            foreach ($merge['updated'] as $n => $update) {
                foreach ($merge['items'] as $entry) {
                    if (is_array($entry) && ($entry['_id'] ?? null) === $update['_id']) {
                        $merge['updated'][$n]['after'] = $entry;
                    }
                }
            }
        }

        $removal = null;

        if ($remove !== null && $errors === []) {
            $both = array_intersect(array_column($items, '_id'), array_filter($remove, 'is_string'));

            if ($both !== []) {
                $errors[] = sprintf('%s cannot be both updated and removed.', implode(', ', array_map(static fn(string $id): string => '"' . $id . '"', $both)));
            }

            $removal = ItemRemover::remove($merge['items'], $remove, $allowLocked);
            $errors = array_merge($errors, $removal['errors']);

            if ($removal['removed'] !== [] && ItemRemover::countItems($removal['items']) === 0) {
                $errors[] = 'At least one font must remain (the builder does not allow removing the last font).';
            }
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $uses = $removal !== null && $removal['removed'] !== []
            ? ($this->scanner ?? new ReferenceScanner())->scan(ReferenceScanner::KIND_FONT, $removal['removed'])
            : [];
        $inUse = array_filter($uses, static fn(array $use): bool => $use['count'] > 0);

        $groupChanged = in_array($merge['group']['action'] ?? null, ['created', 'updated'], true);
        $removed = $removal !== null && ($removal['removed'] !== [] || $removal['removed_groups'] !== []);
        $fontsChanged = $merge['added'] !== [] || $merge['updated'] !== [] || $groupChanged || $removed;

        $result = [
            'changed'        => $fontsChanged || $configChanged !== [],
            'dry_run'        => $dryRun,
            'added'          => $merge['added'],
            'updated'        => $merge['updated'],
            'unchanged'      => $merge['unchanged'],
            'group'          => $merge['group'],
            'removed'        => $removal['removed'] ?? [],
            'removed_groups' => $removal['removed_groups'] ?? [],
            'uses'           => $uses === [] ? (object) [] : $uses,
            'blocked'        => $inUse !== [] && ! $force,
            'config'         => [
                'changed' => $configChanged,
                'before'  => (object) array_intersect_key($storedConfig, array_flip($configChanged)),
                'after'   => (object) array_intersect_key($config, array_flip($configChanged)),
            ],
            'backup_ids'     => (object) [],
            'write_path'     => null,
            'warnings'       => [],
        ];

        if (! $result['changed']) {
            $result['warnings'][] = 'Nothing to change; nothing was written.';
        }

        if ($inUse !== []) {
            $result['warnings'][] = ($force ? 'Removed although still in use (those references now fall back to the browser font): ' : 'Still in use, so nothing will be removed without force: true: ')
                . ReferenceScanner::describe($inUse) . '.';
        }

        if ($dryRun || ! $result['changed']) {
            return $result;
        }

        if ($result['blocked']) {
            throw new \RuntimeException('Nothing was written. ' . ReferenceScanner::describe($inUse) . '. Replace those references first, or pass force: true to remove the fonts anyway (the browser default font is used instead).');
        }

        $backupIds = [];

        if ($fontsChanged) {
            $finalItems = $removal !== null ? $removal['items'] : $merge['items'];
            $backupIds['fonts'] = $this->backups->backup('fonts', 'set_fonts')['backup_id'];
            update_option(self::ITEMS_OPTION, StoredList::encode(array_values($finalItems)));
        }

        if ($configChanged !== []) {
            $backupIds['font_config'] = $this->backups->backup('font_config', 'set_fonts')['backup_id'];
            update_option(self::CONFIG_OPTION, StoredList::encode($config, true));
        }

        delete_option('x_cache_google_fonts_request');
        $purge = $this->gateway->purgeGenerated();

        $result['backup_ids'] = $backupIds;

        if (count($backupIds) === 1) {
            $result['backup_id'] = reset($backupIds);
        }

        $result['write_path'] = $purge['path'];

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::write('Set Fonts', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
