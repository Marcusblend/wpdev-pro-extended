<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Layouts\LayoutService;

final class UpdateLayout implements ToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'update_layout';
    }

    public function description(): string
    {
        return 'Apply patch operations to an existing Cornerstone layout. Supports adding, removing, and updating elements. Automatically creates a backup first.';
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
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $postId     = (int) ($arguments['post_id'] ?? 0);
        $operations = $arguments['operations'] ?? [];

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        if (empty($operations)) {
            throw new \InvalidArgumentException('At least one operation is required.');
        }

        // Get current layout data.
        $envelope = $this->layouts->get($postId);
        $data = $envelope['data'];

        // Create backup.
        $backupId = null;
        try {
            $backupId = $this->layouts->backup($postId);
        } catch (\Throwable) {
            // Ignore if backup fails.
        }

        // Apply operations.
        $applied = 0;
        $errors = [];

        foreach ($operations as $index => $op) {
            $opType = $op['op'] ?? '';
            $path = $op['path'] ?? '';
            $value = $op['value'] ?? null;

            try {
                match ($opType) {
                    'update' => $this->applyUpdate($data, $path, $value),
                    'add'    => $this->applyAdd($data, $path, $value),
                    'remove' => $this->applyRemove($data, $path),
                    default  => throw new \InvalidArgumentException(sprintf('Unknown operation "%s".', $opType)),
                };
                $applied++;
            } catch (\Throwable $e) {
                $errors[] = sprintf('Operation %d (%s): %s', $index, $opType, $e->getMessage());
            }
        }

        // Save modified data.
        if ($applied > 0) {
            $this->layouts->save($postId, $data);
        }

        return [
            'post_id'            => $postId,
            'backup_id'          => $backupId,
            'operations_applied' => $applied,
            'operations_total'   => count($operations),
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
