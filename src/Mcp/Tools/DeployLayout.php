<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\HierarchyValidator;
use ProExtended\Layouts\LayoutService;
use ProExtended\Support\JsonArgs;
use ProExtended\Support\SkipValidation;

final class DeployLayout implements ToolInterface, AnnotatedToolInterface
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
        return 'Deploy (write) Cornerstone layout data to a post. Automatically creates a backup before writing and validates the data. Use with caution — this overwrites the existing layout. For headers, footers, layouts and component documents this is a full replace of the shape get_layout returns: settings left out return to their defaults (title, slug and a single/archive layout\'s type are kept).';
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
        $arguments      = JsonArgs::decode($arguments, ['layout_data']);
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

        SkipValidation::assertAllowed($skipValidation);

        $post = get_post($postId);
        if (! $post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', $postId));
        }

        // Determine context for validation.
        $context = ($post->post_type === 'cs_global_block') ? 'flat' : 'inline';

        // Validate layout data.
        $backupId = null;
        $warnings = [];

        if (! $skipValidation) {
            $validation = $this->validator->validate($layoutData, $context);

            if (! $validation->valid) {
                return [
                    'deployed'   => false,
                    'post_id'    => $postId,
                    'backup_id'  => null,
                    'warnings'   => $warnings,
                    'validation' => $validation->toArray(),
                ];
            }

            // Carry warnings through to the caller. A validation that fell back
            // to a different reading of the data, or that flagged a suspect
            // parent/child pairing, must not vanish just because it passed.
            foreach ($validation->warnings as $warning) {
                $warnings[] = $warning;
            }
        }

        // Create backup.

        if (! $skipBackup) {
            try {
                $backupId = $this->layouts->backup($postId, ['source_tool' => 'deploy_layout']);
            } catch (\Throwable $e) {
                // Usually a first deploy with no existing data to back up, but
                // report it either way — silently skipping the backup is exactly
                // what the caller needs to know about before overwriting a layout.
                $warnings[] = 'Backup was not created: ' . $e->getMessage();
            }
        }

        // Deploy.
        $success = $this->layouts->save($postId, $layoutData);
        $write = $this->layouts->lastWrite();

        return [
            'deployed'   => $success,
            'post_id'    => $postId,
            'backup_id'  => $backupId,
            'warnings'   => array_merge($warnings, $write['warnings']),
            'write_path' => $write['path'],
        ];
    }

    public function annotations(): array
    {
        return Annotations::write('Deploy Layout', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
