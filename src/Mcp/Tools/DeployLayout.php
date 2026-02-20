<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\HierarchyValidator;
use ProExtended\Layouts\LayoutService;

final class DeployLayout implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
        private readonly HierarchyValidator $validator,
    ) {}

    public function name(): string
    {
        return 'deploy_layout';
    }

    public function description(): string
    {
        return 'Deploy (write) Cornerstone layout data to a post. Automatically creates a backup before writing and validates the data. Use with caution — this overwrites the existing layout.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['post_id', 'layout_data'],
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The WordPress post ID to deploy the layout to.',
                ],
                'layout_data' => [
                    'type'        => ['array', 'object'],
                    'description' => 'The Cornerstone layout data to deploy.',
                ],
                'skip_validation' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Skip layout validation before deploying. Default: false.',
                ],
                'skip_backup' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Skip automatic backup before deploying. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId         = (int) ($arguments['post_id'] ?? 0);
        $layoutData     = $arguments['layout_data'] ?? null;
        $skipValidation = (bool) ($arguments['skip_validation'] ?? false);
        $skipBackup     = (bool) ($arguments['skip_backup'] ?? false);

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        if ($layoutData === null) {
            throw new \InvalidArgumentException('layout_data is required.');
        }

        $post = get_post($postId);
        if (! $post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', $postId));
        }

        // Determine context for validation.
        $context = ($post->post_type === 'cs_global_block') ? 'flat' : 'inline';

        // Validate layout data.
        if (! $skipValidation) {
            $validation = $this->validator->validate($layoutData, $context);

            if (! $validation->valid) {
                return [
                    'deployed'   => false,
                    'validation' => $validation->toArray(),
                ];
            }
        }

        // Create backup.
        $backupId = null;
        if (! $skipBackup) {
            try {
                $backupId = $this->layouts->backup($postId);
            } catch (\Throwable) {
                // First deploy — no existing data to back up.
            }
        }

        // Deploy.
        $success = $this->layouts->save($postId, $layoutData);

        return [
            'deployed'  => $success,
            'post_id'   => $postId,
            'backup_id' => $backupId,
        ];
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
