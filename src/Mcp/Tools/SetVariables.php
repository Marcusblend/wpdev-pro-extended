<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Settings\VariableItems;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class SetVariables implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
    ) {}

    public function name(): string
    {
        return 'set_variables';
    }

    public function description(): string
    {
        return 'Add, change or remove Cornerstone Global Variables. Each becomes a CSS custom property on :root, so an element set to var(--name) follows the variable instead of carrying a copy of the value — which is how a look becomes global and stays editable. variables is a map of name => value, or a list of {id, value, breakpoints} where breakpoints is one value per breakpoint. remove lists names to drop. Names take letters, numbers, dashes and underscores; a leading "--" is optional. The stored list is backed up first, and dry_run reports the result without writing. Read them back with get_theme_options (key cs_theme_variables).';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'variables' => [
                    'type'        => ['object', 'array', 'string'],
                    'description' => 'name => value, or a list of {id, value, breakpoints}. Accepts a JSON string.',
                ],
                'remove' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Optional. Variable names to remove.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report the result and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['variables', 'remove', 'dry_run'], 'arguments');

        $arguments = JsonArgs::decode($arguments, ['variables']);
        $dryRun = Args::bool($arguments, 'dry_run', false);
        $remove = Args::list($arguments, 'remove', 0, 200) ?? [];
        $updates = $arguments['variables'] ?? null;

        if (($updates === null || $updates === []) && $remove === []) {
            throw new \InvalidArgumentException('Pass variables, remove, or both.');
        }

        if ($updates !== null && ! is_array($updates)) {
            throw new \InvalidArgumentException('variables must be a map of name => value or a list of {id, value}.');
        }

        $stored = $this->gateway->getThemeOption(VariableItems::OPTION);
        $stored = is_array($stored) ? $stored : [];

        $merged = VariableItems::merge($stored, (array) ($updates ?? []), array_map('strval', $remove));

        if ($merged['errors'] !== []) {
            throw new \InvalidArgumentException("These variables could not be used:\n- " . implode("\n- ", $merged['errors']));
        }

        $result = [
            'dry_run'   => $dryRun,
            'added'     => $merged['added'],
            'changed'   => $merged['changed'],
            'removed'   => $merged['removed'],
            'count'     => count($merged['items']),
            // Stored items this plugin cannot read are kept and counted, but
            // there is nothing to report for them.
            'variables' => array_values(array_map(static fn (array $item): array => [
                'id'        => (string) $item['id'],
                'value'     => is_scalar($item['value'] ?? null) ? (string) $item['value'] : '',
                'property'  => VariableItems::property((string) $item['id']),
                'reference' => VariableItems::reference((string) $item['id']),
                'responsive' => isset($item['_bp']['value']),
            ], array_filter($merged['items'], static fn (mixed $item): bool => is_array($item) && is_scalar($item['id'] ?? null)))),
        ];

        if ($merged['added'] === [] && $merged['changed'] === [] && $merged['removed'] === []) {
            $result['note'] = 'Nothing would change.';

            return $result;
        }

        if ($dryRun) {
            return $result;
        }

        $result['backup'] = $this->backups->backup(SettingsBackups::OPTION_KEY_PREFIX . VariableItems::OPTION, 'set_variables')['backup_id'] ?? null;
        $outcome = $this->gateway->updateThemeOption(VariableItems::OPTION, $merged['items']);
        $result['write_path'] = $outcome['path'];
        $result['purge'] = $outcome['purge'];

        if ($outcome['warnings'] !== []) {
            $result['warnings'] = $outcome['warnings'];
        }

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::write('Set Variables', true, false);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
