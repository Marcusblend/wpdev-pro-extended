<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Settings\ItemMerger;
use ProExtended\Settings\ItemRemover;
use ProExtended\Settings\ReferenceScanner;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Settings\StoredList;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class SetColors implements ToolInterface, AnnotatedToolInterface
{
    public const OPTION = 'cornerstone_color_items';
    public const ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{2,63}$/';

    private const ARGUMENTS = ['colors', 'group', 'allow_locked', 'dry_run', 'remove', 'force'];

    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
        private readonly ?ReferenceScanner $scanner = null,
    ) {}

    public function name(): string
    {
        return 'set_colors';
    }

    public function description(): string
    {
        return 'Add or update global palette colors by _id ({_id, title, value}); existing entries keep every key you do not change. Reference colors in layouts as "global-color:<_id>". group ({_id, title}) is created if missing and new colors are added to it. remove lists color or group IDs to delete: each color is first looked up in documents, element data, templates, theme options and other palette entries, and a color still in use is only removed with force: true (Cornerstone renders missing colors as transparent). Entries stored as locked (for example a starter kit\'s palette) change only with allow_locked: true. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'colors' => [
                    'type'        => 'array',
                    'minItems'    => 1,
                    'maxItems'    => 200,
                    'items'       => [
                        'type'       => 'object',
                        'required'   => ['_id'],
                        'properties' => [
                            '_id'   => ['type' => 'string', 'pattern' => '^[A-Za-z][A-Za-z0-9_-]{2,63}$'],
                            'title' => ['type' => 'string'],
                            'value' => ['type' => 'string', 'description' => 'Any CSS color: hex, rgb(), rgba(), hsl(), transparent, var(--token).'],
                        ],
                    ],
                    'description' => 'Colors to add or update. New colors need title and value. Required unless remove is given.',
                ],
                'remove' => [
                    'type'        => 'array',
                    'minItems'    => 1,
                    'maxItems'    => 200,
                    'items'       => ['type' => 'string'],
                    'description' => 'Optional. Color or group IDs to delete. Removing a group keeps its colors.',
                ],
                'force' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Remove colors even when the site still uses them. Default: false.',
                ],
                'group' => [
                    'type'        => 'object',
                    'properties'  => [
                        '_id'   => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                    ],
                    'description' => 'Optional. Palette group for the new colors.',
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
        $arguments = JsonArgs::decode($arguments, ['colors', 'group', 'remove']);
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $colors = Args::list($arguments, 'colors', 1, 200);
        $remove = Args::list($arguments, 'remove', 1, 200);

        if ($colors === null && $remove === null) {
            throw new \InvalidArgumentException('"colors" or "remove" is required.');
        }

        $colors ??= [];
        $allowLocked = Args::bool($arguments, 'allow_locked', false);
        $dryRun = Args::bool($arguments, 'dry_run', false);
        $force = Args::bool($arguments, 'force', false);
        $errors = [];
        $items = [];

        foreach ($colors as $i => $color) {
            $item = self::validateColor($color, sprintf('colors[%d]', $i), $errors);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $group = self::validateGroup(Args::object($arguments, 'group'), $errors);

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $stored = StoredList::listFrom($this->backups->readRaw(self::OPTION), self::OPTION);
        $merge = ItemMerger::merge($stored, $items, $group, $allowLocked);
        $errors = $merge['errors'];

        foreach ($items as $item) {
            if (in_array($item['_id'], $merge['added'], true) && (! isset($item['title']) || ! isset($item['value']))) {
                $errors[] = sprintf('New color "%s" needs both title and value.', $item['_id']);
            }
        }

        $removal = null;

        if ($remove !== null) {
            $both = array_intersect(array_column($items, '_id'), array_filter($remove, 'is_string'));

            if ($both !== []) {
                $errors[] = sprintf('%s cannot be both updated and removed.', implode(', ', array_map(static fn(string $id): string => '"' . $id . '"', $both)));
            }

            $removal = ItemRemover::remove($merge['items'], $remove, $allowLocked);
            $errors = array_merge($errors, $removal['errors']);
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $uses = $removal !== null && $removal['removed'] !== []
            ? ($this->scanner ?? new ReferenceScanner())->scan(ReferenceScanner::KIND_COLOR, $removal['removed'], $removal['items'])
            : [];
        $inUse = array_filter($uses, static fn(array $use): bool => $use['count'] > 0);

        $groupChanged = in_array($merge['group']['action'] ?? null, ['created', 'updated'], true);
        $removed = $removal !== null && ($removal['removed'] !== [] || $removal['removed_groups'] !== []);
        $changed = $merge['added'] !== [] || $merge['updated'] !== [] || $groupChanged || $removed;

        $result = [
            'changed'        => $changed,
            'dry_run'        => $dryRun,
            'added'          => $merge['added'],
            'updated'        => $merge['updated'],
            'unchanged'      => $merge['unchanged'],
            'group'          => $merge['group'],
            'removed'        => $removal['removed'] ?? [],
            'removed_groups' => $removal['removed_groups'] ?? [],
            'uses'           => $uses === [] ? (object) [] : $uses,
            'blocked'        => $inUse !== [] && ! $force,
            'backup_id'      => null,
            'write_path'     => null,
            'warnings'       => $changed ? [] : ['Nothing to change; nothing was written.'],
        ];

        if ($inUse !== []) {
            $result['warnings'][] = ($force ? 'Removed although still in use (those references now render as transparent): ' : 'Still in use, so nothing will be removed without force: true: ')
                . ReferenceScanner::describe($inUse) . '.';
        }

        if ($dryRun || ! $changed) {
            return $result;
        }

        if ($result['blocked']) {
            throw new \RuntimeException('Nothing was written. ' . ReferenceScanner::describe($inUse) . '. Replace those references first, or pass force: true to remove the colors anyway (Cornerstone renders missing colors as transparent).');
        }

        $finalItems = $removal !== null ? $removal['items'] : $merge['items'];
        $backup = $this->backups->backup('colors', 'set_colors');
        update_option(self::OPTION, StoredList::encode(array_values($finalItems)));
        $purge = $this->gateway->purgeGenerated();

        $result['backup_id'] = $backup['backup_id'];
        $result['write_path'] = $purge['path'];

        return $result;
    }

    /**
     * @param  string[] $errors
     * @return array<string, string>|null
     */
    private static function validateColor(mixed $color, string $label, array &$errors): ?array
    {
        if (! is_array($color) || ($color !== [] && array_is_list($color))) {
            $errors[] = sprintf('%s must be an object.', $label);
            return null;
        }

        $unknown = array_diff(array_map('strval', array_keys($color)), ['_id', 'title', 'value']);

        if ($unknown !== []) {
            $errors[] = sprintf('%s has unknown keys: %s (allowed: _id, title, value).', $label, implode(', ', $unknown));
            return null;
        }

        if (! isset($color['_id']) || ! is_string($color['_id']) || ! preg_match(self::ID_PATTERN, $color['_id'])) {
            $errors[] = sprintf('%s._id must be 3-64 characters: a letter, then letters, digits, "_" or "-".', $label);
            return null;
        }

        $item = ['_id' => $color['_id']];

        if (array_key_exists('title', $color)) {
            if (! is_string($color['title']) || trim($color['title']) === '' || strlen($color['title']) > 200 || preg_match('/[\r\n]/', $color['title'])) {
                $errors[] = sprintf('%s.title must be a non-empty single-line string.', $label);
            } else {
                $item['title'] = $color['title'];
            }
        }

        if (array_key_exists('value', $color)) {
            $value = $color['value'];

            if (! is_string($value) || trim($value) === '' || strlen($value) > 100 || preg_match('/[;{}<>\r\n]/', $value)) {
                $errors[] = sprintf('%s.value must be a CSS color of at most 100 characters without ; { } < > or line breaks.', $label);
            } else {
                $item['value'] = $value;
            }
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>|null $group
     * @param  string[]                  $errors
     * @return array{_id: string, title: string}|null
     */
    public static function validateGroup(?array $group, array &$errors): ?array
    {
        if ($group === null) {
            return null;
        }

        $unknown = array_diff(array_map('strval', array_keys($group)), ['_id', 'title']);

        if ($unknown !== []) {
            $errors[] = sprintf('group has unknown keys: %s (allowed: _id, title).', implode(', ', $unknown));
            return null;
        }

        if (! isset($group['_id']) || ! is_string($group['_id']) || ! preg_match(self::ID_PATTERN, $group['_id'])) {
            $errors[] = 'group._id must be 3-64 characters: a letter, then letters, digits, "_" or "-".';
            return null;
        }

        if (! isset($group['title']) || ! is_string($group['title']) || trim($group['title']) === '' || strlen($group['title']) > 200) {
            $errors[] = 'group.title is required.';
            return null;
        }

        return ['_id' => $group['_id'], 'title' => $group['title']];
    }

    public function annotations(): array
    {
        return Annotations::write('Set Colors', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
