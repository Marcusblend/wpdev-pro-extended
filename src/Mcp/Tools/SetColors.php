<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Settings\ItemMerger;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Settings\StoredList;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class SetColors implements ToolInterface, AnnotatedToolInterface
{
    public const OPTION = 'cornerstone_color_items';
    public const ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{2,63}$/';

    private const ARGUMENTS = ['colors', 'group', 'allow_locked', 'dry_run'];

    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'set_colors';
    }

    public function description(): string
    {
        return 'Add or update global palette colors by _id ({_id, title, value}); existing entries keep every key you do not change, and nothing is removed. Reference colors in layouts as "global-color:<_id>". group ({_id, title}) is created if missing and new colors are added to it. Entries stored as locked (for example a starter kit\'s palette) change only with allow_locked: true, which keeps every existing reference working. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['colors'],
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
                    'description' => 'Colors to add or update. New colors need title and value.',
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
        $arguments = JsonArgs::decode($arguments, ['colors', 'group']);
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $colors = Args::list($arguments, 'colors', 1, 200);

        if ($colors === null) {
            throw new \InvalidArgumentException('"colors" is required.');
        }

        $allowLocked = Args::bool($arguments, 'allow_locked', false);
        $dryRun = Args::bool($arguments, 'dry_run', false);
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

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $groupChanged = in_array($merge['group']['action'] ?? null, ['created', 'updated'], true);
        $changed = $merge['added'] !== [] || $merge['updated'] !== [] || $groupChanged;

        $result = [
            'changed'    => $changed,
            'dry_run'    => $dryRun,
            'added'      => $merge['added'],
            'updated'    => $merge['updated'],
            'unchanged'  => $merge['unchanged'],
            'group'      => $merge['group'],
            'backup_id'  => null,
            'write_path' => null,
            'warnings'   => $changed ? [] : ['Nothing to change; nothing was written.'],
        ];

        if ($dryRun || ! $changed) {
            return $result;
        }

        $backup = $this->backups->backup('colors', 'set_colors');
        update_option(self::OPTION, StoredList::encode(array_values($merge['items'])));
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
