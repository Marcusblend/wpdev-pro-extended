<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Cornerstone\ElementContext;
use ProExtended\Settings\GlobalParameters;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class SetGlobalParameters implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly SettingsBackups $backups,
        private readonly ElementContext $elements,
    ) {}

    public function name(): string
    {
        return 'set_global_parameters';
    }

    public function description(): string
    {
        return 'Write the site\'s global parameters: json is the schema (which parameters exist, their types and defaults, the same shape a component\'s _p_json uses) and data holds their values. An element control bound to a global parameter follows it everywhere, which is how one change moves a whole site instead of an element at a time. Per-breakpoint values live under "_bp_data<tag>" in data and need "_bp_base"; it is added when missing, because without it Cornerstone stores the values and renders nothing. Both options are backed up first. Run with dry_run: true to see what would be written. Read them back with get_theme_options.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'json' => [
                    'type'        => ['object', 'string'],
                    'description' => 'The parameter schema. A JSON object or a JSON string.',
                ],
                'data' => [
                    'type'        => ['object', 'string'],
                    'description' => 'The parameter values, including "_bp_base" and "_bp_data<tag>" for responsive ones.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report what would be written and change nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['json', 'data', 'dry_run'], 'arguments');

        // json is left as the string it may arrive as, so its exact shape is
        // what gets stored; GlobalParameters::prepare() checks it decodes.
        $arguments = JsonArgs::decode($arguments, ['data']);
        $dryRun = Args::bool($arguments, 'dry_run', false);

        $hasJson = array_key_exists('json', $arguments) && $arguments['json'] !== null;
        $hasData = array_key_exists('data', $arguments) && $arguments['data'] !== null;

        if (! $hasJson && ! $hasData) {
            throw new \InvalidArgumentException('Pass json, data, or both.');
        }

        $json = $hasJson ? $arguments['json'] : $this->gateway->getThemeOption(GlobalParameters::JSON_OPTION);
        $data = $hasData ? $arguments['data'] : $this->gateway->getThemeOption(GlobalParameters::DATA_OPTION);

        $prepared = GlobalParameters::prepare($json, $data, $this->elements->breakpointTag());

        if ($prepared['errors'] !== []) {
            throw new \InvalidArgumentException("The parameters could not be written:\n- " . implode("\n- ", $prepared['errors']));
        }

        $result = [
            'dry_run'    => $dryRun,
            'parameters' => $prepared['parameters'],
            'responsive' => $prepared['responsive'],
            'json_bytes' => strlen($prepared['json']),
            'data_keys'  => array_keys($prepared['data']),
        ];

        if ($prepared['warnings'] !== []) {
            $result['warnings'] = $prepared['warnings'];
        }

        if ($dryRun) {
            return $result;
        }

        $result['backups'] = [
            GlobalParameters::JSON_OPTION => $this->backups->backup(SettingsBackups::OPTION_KEY_PREFIX . GlobalParameters::JSON_OPTION, 'set_global_parameters')['backup_id'] ?? null,
            GlobalParameters::DATA_OPTION => $this->backups->backup(SettingsBackups::OPTION_KEY_PREFIX . GlobalParameters::DATA_OPTION, 'set_global_parameters')['backup_id'] ?? null,
        ];

        $this->gateway->updateThemeOption(GlobalParameters::JSON_OPTION, $prepared['json']);
        $outcome = $this->gateway->updateThemeOption(GlobalParameters::DATA_OPTION, $prepared['data']);

        $result['write_path'] = $outcome['path'];
        $result['purge'] = $outcome['purge'];

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::write('Set Global Parameters', true, false);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
