<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Settings\SettingsBackups;
use ProExtended\Support\Args;

final class ListSettingsBackups implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'list_settings_backups';
    }

    public function description(): string
    {
        return 'List the automatic backups of global settings (colors, fonts, font_config, global_css), newest first. The last 10 are kept per key. Use restore_settings to put one back.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'key' => [
                    'type'        => 'string',
                    'description' => 'Optional. Only this settings key.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['key'], 'arguments');
        $key = Args::string($arguments, 'key', null, 200);

        if ($key !== null && ! in_array($key, SettingsBackups::KEYS, true) && ! str_starts_with($key, SettingsBackups::OPTION_KEY_PREFIX)) {
            throw new \InvalidArgumentException(sprintf('key must be one of %s, or "%s<theme option name>" for a theme option.', implode(', ', SettingsBackups::KEYS), SettingsBackups::OPTION_KEY_PREFIX));
        }
        $backups = $this->backups->list($key);

        return [
            'count'   => count($backups),
            'backups' => $backups,
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('List Settings Backups');
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
