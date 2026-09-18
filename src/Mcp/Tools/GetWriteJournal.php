<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Site\WriteJournal;
use ProExtended\Support\Args;

final class GetWriteJournal implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly WriteJournal $journal) {}

    public function name(): string
    {
        return 'get_write_journal';
    }

    public function description(): string
    {
        return 'What this plugin has changed on this site, newest first: the tool, who ran it, when, what it touched and a short summary of what changed. Dry runs are recorded too and can be filtered out. This answers "what happened on this site" for every write, where the settings backups only cover the few things they hold. The journal keeps the most recent entries and is read-only here.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'tool' => [
                    'type'        => 'string',
                    'description' => 'Optional. Only this tool\'s entries.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Optional. How many entries. Default 50.',
                ],
                'include_dry_runs' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Include calls that wrote nothing. Default: true.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['tool', 'limit', 'include_dry_runs'], 'arguments');

        $tool = Args::string($arguments, 'tool', null, 100);
        $limit = Args::int($arguments, 'limit', 50, 1, 250) ?? 50;
        $includeDryRuns = Args::bool($arguments, 'include_dry_runs', true);

        $entries = $this->journal->read($tool, $limit, $includeDryRuns);

        return [
            'count'   => count($entries),
            'stats'   => $this->journal->stats(),
            'entries' => $entries,
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('Get Write Journal');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
