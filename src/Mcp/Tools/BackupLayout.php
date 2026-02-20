<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Layouts\LayoutService;

final class BackupLayout implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'backup_layout';
    }

    public function description(): string
    {
        return 'Create a backup of a Cornerstone layout. Returns a backup ID that can be used to restore later. Up to 10 backups are kept per post.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['post_id'],
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The WordPress post ID to back up.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId = (int) ($arguments['post_id'] ?? 0);

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        $backupId = $this->layouts->backup($postId);

        return [
            'post_id'   => $postId,
            'backup_id' => $backupId,
            'message'   => sprintf('Backup created successfully with ID "%s".', $backupId),
        ];
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
