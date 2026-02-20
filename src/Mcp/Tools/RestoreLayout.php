<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Layouts\LayoutService;

final class RestoreLayout implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'restore_layout';
    }

    public function description(): string
    {
        return 'Restore a Cornerstone layout from a previously created backup. If no backup_id is specified, the most recent backup is used.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['post_id'],
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The WordPress post ID to restore.',
                ],
                'backup_id' => [
                    'type'        => 'string',
                    'description' => 'Optional. The backup ID to restore from. Defaults to the most recent backup.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId   = (int) ($arguments['post_id'] ?? 0);
        $backupId = $arguments['backup_id'] ?? null;

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        $success = $this->layouts->restore($postId, $backupId);

        return [
            'restored' => $success,
            'post_id'  => $postId,
        ];
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
