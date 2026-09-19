<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Site\SiteSnapshot;
use ProExtended\Support\Args;

final class CreateSnapshot implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly SiteSnapshot $snapshot) {}

    public function name(): string
    {
        return 'create_snapshot';
    }

    public function description(): string
    {
        return 'Capture what makes this site look like itself: the palette, fonts, Global CSS, the Theme Options that differ from their defaults, Global Variables, global parameters, the menus with their items and locations, and an inventory of the documents. Read-only. The result is the object restore_snapshot takes, so it is insurance against a change going wrong and a way to carry a starter setup into the next build. Documents are listed, not captured — their element data belongs in backup_layout and export_tco, and carrying megabytes of it here would bury the settings. parts narrows what is taken.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'parts' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string', 'enum' => SiteSnapshot::PARTS],
                    'description' => 'Optional. What to capture. Default: everything.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['parts'], 'arguments');

        $parts = Args::list($arguments, 'parts', 0, 20) ?? [];
        $parts = $parts === [] ? SiteSnapshot::PARTS : array_map('strval', $parts);

        $unknown = array_diff($parts, SiteSnapshot::PARTS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown parts: %s. Known: %s.', implode(', ', $unknown), implode(', ', SiteSnapshot::PARTS)));
        }

        $snapshot = $this->snapshot->capture($parts);
        $encoded = wp_json_encode($snapshot);

        return [
            'snapshot'   => $snapshot,
            'bytes'      => is_string($encoded) ? strlen($encoded) : 0,
            'restorable' => array_values(array_intersect($parts, SiteSnapshot::RESTORABLE)),
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('Create Snapshot');
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
