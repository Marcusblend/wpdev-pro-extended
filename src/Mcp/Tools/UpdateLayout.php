<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\HierarchyValidator;
use ProExtended\Layouts\LayoutService;

final class UpdateLayout implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
        private readonly HierarchyValidator $validator,
    ) {}

    public function name(): string
    {
        return 'update_layout';
    }

    public function description(): string
    {
        return 'Apply patch operations to an existing Cornerstone layout. Supports adding, removing, and updating elements. Operations are all-or-nothing: if any one fails, nothing is written. The result is validated before saving, and a backup is created first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['post_id', 'operations'],
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The WordPress post ID to update.',
                ],
                'operations' => [
                    'type'        => 'array',
                    'description' => 'Array of patch operations to apply.',
                    'items'       => [
                        'type'       => 'object',
                        'required'   => ['op'],
                        'properties' => [
                            'op' => [
                                'type' => 'string',
                                'enum' => ['add', 'remove', 'update'],
                                'description' => 'Operation type.',
                            ],
                            'path' => [
                                'type'        => 'string',
                                'description' => 'JSON pointer path to the target element (e.g. "0._modules.0._modules.1").',
                            ],
                            'value' => [
                                'description' => 'For "add": the element to insert. For "update": object with properties to merge.',
                            ],
                        ],
                    ],
                ],
                'skip_validation' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Skip layout validation of the patched result before saving. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId         = (int) ($arguments['post_id'] ?? 0);
        $operations     = $arguments['operations'] ?? [];
        $skipValidation = (bool) ($arguments['skip_validation'] ?? false);

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        if (! is_array($operations) || empty($operations)) {
            throw new \InvalidArgumentException('At least one operation is required.');
        }

        $envelope = $this->layouts->get($postId);
        $data = $envelope['data'];

        if (! is_array($data)) {
            throw new \RuntimeException(
                sprintf('Layout data for post %d is not an array and cannot be patched.', $postId)
            );
        }

        $total    = count($operations);
        $original = $data;
        $errors   = [];
        $warnings = [];

        // Apply every operation to an in-memory copy first. Nothing is written
        // unless all of them succeed, so a failed patch can never leave a
        // half-applied element tree on the post.
        foreach ($operations as $index => $op) {
            if (! is_array($op)) {
                $errors[] = sprintf('Operation %s is not an object.', (string) $index);
                continue;
            }

            $opType = $op['op'] ?? '';
            $path   = $op['path'] ?? '';
            $value  = $op['value'] ?? null;

            try {
                match ($opType) {
                    'update' => $this->applyUpdate($data, $path, $value),
                    'add'    => $this->applyAdd($data, $path, $value),
                    'remove' => $this->applyRemove($data, $path),
                    default  => throw new \InvalidArgumentException(sprintf('Unknown operation "%s".', $opType)),
                };
            } catch (\Throwable $e) {
                $errors[] = sprintf('Operation %s (%s): %s', (string) $index, (string) $opType, $e->getMessage());
            }
        }

        if (! empty($errors)) {
            return $this->result($postId, false, 0, $total, null, $warnings, $errors, null);
        }

        // Validate the patched result before it reaches the database. Patch
        // operations can produce the sparse `_bp_data` that crashes Cornerstone's
        // editor just as easily as a full deploy can.
        $validation = null;

        if (! $skipValidation) {
            $post = get_post($postId);
            $context = ($post && $post->post_type === 'cs_global_block') ? 'flat' : 'inline';

            $after = $this->validator->validate($data, $context);
            $validation = $after->toArray();

            if (! $after->valid) {
                // Only block when this patch is what broke the layout. If the
                // stored data was already invalid, refusing to write would make
                // the tool unable to repair it.
                $before = $this->validator->validate($original, $context);

                if ($before->valid) {
                    return $this->result(
                        $postId,
                        false,
                        0,
                        $total,
                        null,
                        $warnings,
                        ['Patched layout failed validation; nothing was written.'],
                        $validation
                    );
                }

                $warnings[] = 'Layout is still invalid, but it was already invalid before this patch — writing anyway.';
            }
        }

        $backupId = null;

        try {
            $backupId = $this->layouts->backup($postId);
        } catch (\Throwable $e) {
            $warnings[] = 'Backup was not created: ' . $e->getMessage();
        }

        $saved = $this->layouts->save($postId, $data);

        if (! $saved) {
            $errors[] = sprintf('Writing the patched layout to post %d failed.', $postId);
        }

        return $this->result(
            $postId,
            $saved,
            $saved ? $total : 0,
            $total,
            $backupId,
            $warnings,
            $errors,
            $validation
        );
    }

    /**
     * Build the tool's response.
     *
     * Every exit point returns the same keys so a caller never has to guess
     * which shape it got.
     *
     * @param  string[]                  $warnings
     * @param  string[]                  $errors
     * @param  array<string, mixed>|null $validation
     * @return array<string, mixed>
     */
    private function result(
        int $postId,
        bool $updated,
        int $applied,
        int $total,
        ?string $backupId,
        array $warnings,
        array $errors,
        ?array $validation
    ): array {
        return [
            'updated'            => $updated,
            'post_id'            => $postId,
            'backup_id'          => $backupId,
            'operations_applied' => $applied,
            'operations_total'   => $total,
            'validation'         => $validation,
            'warnings'           => $warnings,
            'errors'             => $errors,
        ];
    }

    /**
     * Apply an "update" operation — merge properties into the target element.
     */
    private function applyUpdate(array &$data, string $path, mixed $value): void
    {
        if ($value === null || ! is_array($value)) {
            throw new \InvalidArgumentException('Value must be an object for update operations.');
        }

        $target = &$this->resolvePointer($data, $path);
        if (! is_array($target)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
        }

        $target = array_merge($target, $value);
    }

    /**
     * Apply an "add" operation — insert an element at the specified path.
     */
    private function applyAdd(array &$data, string $path, mixed $value): void
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Value is required for add operations.');
        }

        // Parse path to find parent and index.
        $parts = $this->parsePath($path);
        $parentPath = implode('.', array_slice($parts, 0, -1));
        $index = end($parts);

        $parent = &$this->resolvePointer($data, $parentPath);

        if (! is_array($parent)) {
            throw new \InvalidArgumentException(sprintf('Parent path "%s" does not point to an array.', $parentPath));
        }

        if (is_numeric($index)) {
            array_splice($parent, (int) $index, 0, [$value]);
        } else {
            $parent[$index] = $value;
        }
    }

    /**
     * Apply a "remove" operation — remove the element at the specified path.
     */
    private function applyRemove(array &$data, string $path): void
    {
        $parts = $this->parsePath($path);
        $parentPath = implode('.', array_slice($parts, 0, -1));
        $index = end($parts);

        $parent = &$this->resolvePointer($data, $parentPath);

        if (! is_array($parent)) {
            throw new \InvalidArgumentException(sprintf('Parent path "%s" does not point to an array.', $parentPath));
        }

        if (is_numeric($index)) {
            array_splice($parent, (int) $index, 1);
        } elseif (isset($parent[$index])) {
            unset($parent[$index]);
        } else {
            throw new \InvalidArgumentException(sprintf('Index "%s" not found in parent.', $index));
        }
    }

    /**
     * Resolve a dot-notation path to a reference in the data structure.
     *
     * @return mixed Reference to the resolved element.
     */
    private function &resolvePointer(array &$data, string $path): mixed
    {
        if ($path === '' || $path === '.') {
            return $data;
        }

        $parts = $this->parsePath($path);
        $current = &$data;

        foreach ($parts as $key) {
            if (is_numeric($key)) {
                $key = (int) $key;
            }

            if (! is_array($current) || ! isset($current[$key])) {
                throw new \InvalidArgumentException(sprintf('Path segment "%s" not found in data.', (string) $key));
            }

            $current = &$current[$key];
        }

        return $current;
    }

    /**
     * Parse a dot-notation path into segments.
     *
     * @return string[]
     */
    private function parsePath(string $path): array
    {
        return array_filter(explode('.', $path), fn($s) => $s !== '');
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
