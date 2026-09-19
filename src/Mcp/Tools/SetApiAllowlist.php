<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Settings\ApiAllowlist;
use ProExtended\Settings\SettingsBackups;
use ProExtended\Site\Features;
use ProExtended\Support\Args;

final class SetApiAllowlist implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly SettingsBackups $backups) {}

    public function name(): string
    {
        return 'set_api_allowlist';
    }

    public function description(): string
    {
        return 'Add or remove entries on Cornerstone\'s External API allowlist — the endpoints an External API looper may call. Cornerstone matches a request against it by prefix, so each entry is normalised to https, a lowercase host and a trailing slash: without the slash an entry also matches a longer host that merely starts the same way. Plain http and entries carrying a query or fragment are refused. This never turns the External API feature on or off — that is a decision about what the site may reach out to, and get_site_info reports whether it is on. The allowlist is backed up first, and dry_run reports the result without writing.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'add' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Endpoints to allow, as https URLs.',
                ],
                'remove' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Entries to drop. A trailing slash is optional when removing.',
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
        Args::rejectUnknown($arguments, ['add', 'remove', 'dry_run'], 'arguments');

        $add = array_map('strval', Args::list($arguments, 'add', 0, 100) ?? []);
        $remove = array_map('strval', Args::list($arguments, 'remove', 0, 100) ?? []);
        $dryRun = Args::bool($arguments, 'dry_run', false);

        if ($add === [] && $remove === []) {
            throw new \InvalidArgumentException('Pass add, remove, or both.');
        }

        $stored = get_option(ApiAllowlist::OPTION, '');
        $stored = is_string($stored) ? $stored : '';

        $plan = ApiAllowlist::plan($stored, $add, $remove);

        if ($plan['errors'] !== []) {
            throw new \InvalidArgumentException("These entries could not be used:\n- " . implode("\n- ", $plan['errors']));
        }

        $result = [
            'dry_run'        => $dryRun,
            'feature_on'     => Features::externalApiEnabled(),
            'added'          => $plan['added'],
            'removed'        => $plan['removed'],
            'already_listed' => $plan['unchanged'],
            'count'          => count($plan['entries']),
            'entries'        => $plan['entries'],
        ];

        if ($plan['normalized'] !== []) {
            $result['normalized'] = $plan['normalized'];
        }

        if (! $result['feature_on']) {
            $result['note'] = 'The External API feature is off on this site, so nothing uses this list yet. Turning it on is a decision for a person, in Cornerstone\'s settings.';
        }

        if ($plan['added'] === [] && $plan['removed'] === []) {
            $result['note'] = 'Nothing would change.';

            return $result;
        }

        if ($dryRun) {
            return $result;
        }

        $result['backup'] = $this->backups->backup(SettingsBackups::OPTION_KEY_PREFIX . ApiAllowlist::OPTION, 'set_api_allowlist')['backup_id'] ?? null;
        update_option(ApiAllowlist::OPTION, ApiAllowlist::encode($plan['entries']));

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::write('Set API Allowlist', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
