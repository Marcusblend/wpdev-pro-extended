<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\ElementContext;
use ProExtended\Elements\ElementStamper;
use ProExtended\Elements\HierarchyValidator;
use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;
use ProExtended\Cornerstone\Prefabs;
use ProExtended\Templates\TemplateGateway;
use ProExtended\Templates\TemplateIdentifier;
use ProExtended\Support\SkipValidation;

final class UpdateLayout implements ToolInterface, AnnotatedToolInterface
{
    /** Every operation patch() handles, and so every one the schema offers. */
    public const OPS = ['add', 'remove', 'update', 'preset', 'move', 'duplicate', 'wrap', 'unwrap', 'prefab'];

    public function __construct(
        private readonly LayoutService $layouts,
        private readonly HierarchyValidator $validator,
        private readonly ?ElementContext $elements = null,
        private readonly ?TemplateGateway $templates = null,
        private readonly ?Prefabs $prefabs = null,
    ) {}

    public function name(): string
    {
        return 'update_layout';
    }

    public function description(): string
    {
        return 'Apply patch operations to an existing Cornerstone layout. Operations are {"op": ..., "path": "0._modules.1", ...}: update merges value into the element at the path, add inserts one, remove deletes one, and preset applies a saved preset\'s settings to the element there ({"op": "preset", "path": "0._modules.1", "preset": 122} — an ID or the preset\'s exact title, from list_templates with kind: "preset"). A preset keeps the element\'s content, id and children and takes its styling keys, and is refused when it is for a different element type. The editing operations: move takes "to", a path read in the tree as it is now: the element lands there and the one there moves down; duplicate copies an element in beside itself, without its ids; wrap puts it inside the element in "value"; unwrap removes it and leaves its children where it was; and prefab inserts one of Cornerstone\'s prefab elements by "group" and "name", already configured (call list_prefabs with them first: a write takes the values that read cached). Operations are all-or-nothing: if any one fails, nothing is written. The result is validated before saving, and a backup is created first.';
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
                                'enum' => self::OPS,
                                'description' => 'Operation type.',
                            ],
                            'path' => [
                                'type'        => 'string',
                                'description' => 'JSON pointer path to the target element (e.g. "0._modules.0._modules.1").',
                            ],
                            'value' => [
                                'description' => 'For "add": the element to insert. For "update": object with properties to merge. For "wrap": the element to wrap with, including its _type.',
                            ],
                            'preset' => [
                                'description' => 'For "preset": the preset to apply, as its template ID or its title.',
                            ],
                            'to' => [
                                'type'        => 'string',
                                'description' => 'For "move": the path the element lands at, read in the tree as it stands before the move (e.g. "0._modules.2").',
                            ],
                            'group' => [
                                'type'        => 'string',
                                'description' => 'For "prefab": the prefab\'s group, from list_prefabs.',
                            ],
                            'name' => [
                                'type'        => 'string',
                                'description' => 'For "prefab": the prefab\'s name, from list_prefabs.',
                            ],
                        ],
                    ],
                ],
                'skip_validation' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Skip layout validation of the patched result before saving. Default: false.',
                ],
                'stamp_new' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Give elements inserted by "add" operations, and the wrapper a "wrap" puts in, the migration (_m) and breakpoint (_bp_base) markers Cornerstone gives new elements, where missing. Default: true.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $arguments      = JsonArgs::decode($arguments, ['operations']);
        $postId         = (int) ($arguments['post_id'] ?? 0);
        $operations     = $arguments['operations'] ?? [];
        $skipValidation = (bool) ($arguments['skip_validation'] ?? false);
        $stampNew       = Args::bool($arguments, 'stamp_new', true);

        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id must be a positive integer.');
        }

        if (! is_array($operations) || empty($operations)) {
            throw new \InvalidArgumentException('At least one operation is required.');
        }

        SkipValidation::assertAllowed($skipValidation);

        // Some clients send an operation's value as a JSON string.
        foreach ($operations as $index => $op) {
            if (is_array($op) && array_key_exists('value', $op)) {
                $operations[$index]['value'] = JsonArgs::decodeValue($op['value'], sprintf('operations[%s].value', (string) $index));
            }
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
        $warnings = [];
        $post     = get_post($postId);
        $flat     = $post instanceof \WP_Post && $post->post_type === 'cs_global_block';
        $stamper  = $stampNew && $this->elements !== null ? $this->elements->stamper() : null;

        // Apply every operation to an in-memory copy first. Nothing is written
        // unless all of them succeed, so a failed patch can never leave a
        // half-applied element tree on the post.
        $patched = $this->patch($data, $operations, $flat, $stamper);
        $data    = $patched['data'];
        $errors  = $patched['errors'];

        if (! empty($errors)) {
            return $this->result($postId, false, 0, $total, null, $warnings, $errors, null);
        }

        // Validate the patched result before it reaches the database. Patch
        // operations can produce the sparse `_bp_data` that crashes Cornerstone's
        // editor just as easily as a full deploy can.
        $validation = null;

        if (! $skipValidation) {
            $context = $flat ? 'flat' : 'inline';

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
            $backupId = $this->layouts->backup($postId, ['source_tool' => 'update_layout']);
        } catch (\Throwable $e) {
            $warnings[] = 'Backup was not created: ' . $e->getMessage();
        }

        $saved = $this->layouts->save($postId, $data);
        $write = $this->layouts->lastWrite();

        if (! $saved) {
            $errors[] = sprintf('Writing the patched layout to post %d failed.', $postId);
        }

        $result = $this->result(
            $postId,
            $saved,
            $saved ? $total : 0,
            $total,
            $backupId,
            array_merge($warnings, $write['warnings']),
            $errors,
            $validation
        );
        $result['write_path'] = $write['path'];
        $result['stamped'] = $stamper?->counts();

        return $result;
    }

    /**
     * Apply operations to layout data in memory, in order.
     *
     * Nothing is read from or written to the site here: execute() loads the
     * layout, runs this, and saves only when it reports no errors. Public so
     * the operations can be tested without a site.
     *
     * @param  array<mixed>             $data
     * @param  array<int|string, mixed> $operations
     * @return array{data: array<mixed>, errors: string[]}
     */
    public function patch(array $data, array $operations, bool $flat = false, ?ElementStamper $stamper = null): array
    {
        $errors = [];

        foreach ($operations as $index => $op) {
            if (! is_array($op)) {
                $errors[] = sprintf('Operation %s is not an object.', (string) $index);
                continue;
            }

            $opType = $op['op'] ?? '';
            $path   = $op['path'] ?? '';
            $value  = $op['value'] ?? null;

            // What an operation brings in is new to the document and gets the
            // markers Cornerstone gives a new element: the element "add"
            // inserts, and the wrapper "wrap" puts around an existing one. The
            // wrapper is stamped before the existing element goes inside it, so
            // that element keeps exactly the markers it had.
            if (($opType === 'add' || $opType === 'wrap') && $stamper !== null && is_array($value)) {
                $value = $this->stampInserted($stamper, $value, $flat);
            }

            try {
                match ($opType) {
                    'update' => $this->applyUpdate($data, $path, $value),
                    'add'    => $this->applyAdd($data, $path, $value),
                    'remove' => $this->applyRemove($data, $path),
                    'preset' => $this->applyPreset($data, $path, $op['preset'] ?? $value),
                    'move'      => $this->applyMove($data, $path, (string) ($op['to'] ?? '')),
                    'duplicate' => $this->applyDuplicate($data, $path),
                    'wrap'      => $this->applyWrap($data, $path, $value),
                    'unwrap'    => $this->applyUnwrap($data, $path),
                    'prefab'    => $this->applyPrefab($data, $path, (string) ($op['group'] ?? ''), (string) ($op['name'] ?? '')),
                    default  => throw new \InvalidArgumentException(sprintf('Unknown operation "%s".', is_scalar($opType) ? (string) $opType : gettype($opType))),
                };
            } catch (\Throwable $e) {
                $errors[] = sprintf('Operation %s (%s): %s', (string) $index, is_scalar($opType) ? (string) $opType : gettype($opType), $e->getMessage());
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Stamp what an "add" or "wrap" operation inserts: one element (with its children),
     * a list of elements, or, in a component document's flat map, a single
     * element whose children are ID strings.
     *
     * @param  array<mixed> $value
     * @return array<mixed>
     */
    private function stampInserted(ElementStamper $stamper, array $value, bool $flat): array
    {
        if (isset($value['_type'])) {
            return $flat ? $stamper->stampFlat(['new' => $value])['new'] : $stamper->stampElement($value);
        }

        return array_is_list($value) ? $stamper->stampTree($value) : $value;
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
     * Apply a saved preset's settings to the element at a path.
     *
     * A preset is a template of settings for one element type, and applying one
     * is how a client-editable look is reused: the element keeps its own
     * content, id and children, and takes the preset's styling keys. The
     * element's type has to match the preset's, or the settings would mean
     * nothing on it.
     */
    private function applyPreset(array &$data, string $path, mixed $preset): void
    {
        if ($this->templates === null) {
            throw new \RuntimeException('The template library is not available on this site, so presets cannot be applied.');
        }

        $atts = $this->presetAtts($preset);
        $forType = (string) ($atts['_type'] ?? '');
        unset($atts['_type']);

        if ($atts === []) {
            throw new \InvalidArgumentException('That preset holds no settings.');
        }

        $target = &$this->resolvePointer($data, $path);

        if (! is_array($target)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
        }

        $targetType = (string) ($target['_type'] ?? '');

        if ($forType !== '' && $targetType !== '' && $forType !== $targetType) {
            throw new \InvalidArgumentException(sprintf('That preset is for a "%s"; the element at "%s" is a "%s".', $forType, $path, $targetType));
        }

        // The element's own identity and children are never part of a preset.
        unset($atts['_id'], $atts['_modules'], $atts['_region'], $atts['_parent'], $atts['_c_id']);

        $target = array_merge($target, $atts);
    }

    /**
     * @return array<string, mixed>
     */
    private function presetAtts(mixed $preset): array
    {
        $row = null;

        if (is_int($preset) || (is_string($preset) && ctype_digit($preset))) {
            $row = $this->templates->get((int) $preset);
        } elseif (is_string($preset) && $preset !== '') {
            foreach ($this->templates->all(null, $preset, 50) as $candidate) {
                if ($candidate['kind'] === 'preset' && strcasecmp($candidate['title'], $preset) === 0) {
                    $row = $this->templates->get((int) $candidate['id']);
                    break;
                }
            }
        }

        if ($row === null) {
            throw new \InvalidArgumentException('preset must be a preset template ID or its exact title. Use list_templates with kind: "preset".');
        }

        if (! TemplateIdentifier::isPreset((string) ($row['type'] ?? ''), (string) ($row['sub_type'] ?? ''))) {
            throw new \InvalidArgumentException(sprintf('Template %d is a %s, not a preset.', (int) $row['id'], (string) ($row['kind'] ?? 'template')));
        }

        $atts = $row['content']['atts'] ?? null;

        if (! is_array($atts)) {
            throw new \InvalidArgumentException(sprintf('Preset %d has no stored settings.', (int) $row['id']));
        }

        $atts['_type'] ??= (string) $row['sub_type'];

        return $atts;
    }

    /**
     * Move an element to another place in the tree.
     *
     * "to" is read in the tree as it stands before the move, the way dropping
     * an element in the builder reads it: the element lands where the one at
     * "to" is now, and that one moves down. Taking the element out first
     * shifts every later sibling up by one, so "to" is translated into the
     * tree without the element before it is inserted.
     */
    private function applyMove(array &$data, string $path, string $to): void
    {
        if ($to === '') {
            throw new \InvalidArgumentException('A move needs "to": the path it should land at.');
        }

        $source = array_values($this->parsePath($path));
        $target = array_values($this->parsePath($to));

        if ($source === []) {
            throw new \InvalidArgumentException('A move needs "path": the element to move.');
        }

        if ($source === $target) {
            return;
        }

        if (array_slice($target, 0, count($source)) === $source) {
            throw new \InvalidArgumentException(sprintf('"%s" is inside "%s", so the element cannot move into itself.', $to, $path));
        }

        $node = $this->resolvePointer($data, $path);

        if (! is_array($node)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
        }

        // Resolve the destination against the tree as it stands, before
        // anything is taken out of it.
        $targetParentPath = implode('.', array_slice($target, 0, -1));
        $targetIndex = end($target);
        $targetParent = $this->resolvePointer($data, $targetParentPath);

        if (! is_array($targetParent)) {
            throw new \InvalidArgumentException(sprintf('Parent path "%s" does not point to an array.', $targetParentPath));
        }

        if (is_numeric($targetIndex) && ((int) $targetIndex < 0 || (int) $targetIndex > count($targetParent))) {
            throw new \InvalidArgumentException(sprintf('"%s" is past the end of its list, which holds %d.', $to, count($targetParent)));
        }

        $landing = implode('.', self::afterRemoval($source, $target));
        $moving = $node;

        $this->applyRemove($data, $path);

        try {
            $this->applyAdd($data, $landing, $moving);
        } catch (\Throwable $e) {
            // Put it back rather than leaving the element nowhere.
            $this->applyAdd($data, $path, $moving);

            throw new \InvalidArgumentException(sprintf('The element could not be moved to "%s": %s', $to, $e->getMessage()));
        }
    }

    /**
     * Where a path points once the element at another path has been taken out.
     *
     * Removing an element shifts its later siblings up by one, so a target
     * that runs through one of them (a later sibling itself, or anything
     * inside one) has that index lowered. Everything else is unchanged.
     *
     * @param  string[] $removed Segments of the path taken out.
     * @param  string[] $target  Segments of the path to translate.
     * @return string[]
     */
    public static function afterRemoval(array $removed, array $target): array
    {
        $depth = count($removed) - 1;

        if ($depth < 0 || count($target) <= $depth) {
            return $target;
        }

        $removedIndex = $removed[$depth];
        $targetIndex = $target[$depth];

        if (
            array_slice($target, 0, $depth) !== array_slice($removed, 0, $depth)
            || ! is_numeric($removedIndex)
            || ! is_numeric($targetIndex)
            || (int) $targetIndex <= (int) $removedIndex
        ) {
            return $target;
        }

        $target[$depth] = (string) ((int) $targetIndex - 1);

        return $target;
    }

    /**
     * Copy an element in beside itself.
     */
    private function applyDuplicate(array &$data, string $path): void
    {
        $node = $this->resolvePointer($data, $path);

        if (! is_array($node)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
        }

        $parts = $this->parsePath($path);
        $index = end($parts);

        if (! is_numeric($index)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not in a list, so there is nowhere beside it to copy to.', $path));
        }

        // An id is per-element; a copy that kept it would be two elements
        // claiming the same one.
        $copy = $this->forgetIds($node);
        $parentPath = implode('.', array_slice($parts, 0, -1));

        $this->applyAdd($data, ($parentPath === '' ? '' : $parentPath . '.') . ((int) $index + 1), $copy);
    }

    /**
     * Put an element inside a new parent, in place.
     */
    private function applyWrap(array &$data, string $path, mixed $wrapper): void
    {
        if (! is_array($wrapper) || ! is_string($wrapper['_type'] ?? null) || $wrapper['_type'] === '') {
            throw new \InvalidArgumentException('A wrap needs "value": the element to wrap with, including its _type.');
        }

        $target = &$this->resolvePointer($data, $path);

        if (! is_array($target)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
        }

        $existing = is_array($wrapper['_modules'] ?? null) ? $wrapper['_modules'] : [];
        $existing[] = $target;
        $wrapper['_modules'] = $existing;

        $target = $wrapper;
        unset($target);
    }

    /**
     * Take an element out and leave its children in its place.
     */
    private function applyUnwrap(array &$data, string $path): void
    {
        $node = $this->resolvePointer($data, $path);

        if (! is_array($node)) {
            throw new \InvalidArgumentException(sprintf('Path "%s" does not point to an element.', $path));
        }

        $children = is_array($node['_modules'] ?? null) ? array_values($node['_modules']) : [];

        if ($children === []) {
            throw new \InvalidArgumentException(sprintf('"%s" has no children, so unwrapping it would only delete it. Use remove.', $path));
        }

        $parts = $this->parsePath($path);
        $index = end($parts);
        $parentPath = implode('.', array_slice($parts, 0, -1));

        if (! is_numeric($index)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not in a list, so its children have nowhere to go.', $path));
        }

        $parent = &$this->resolvePointer($data, $parentPath);

        if (! is_array($parent)) {
            throw new \InvalidArgumentException(sprintf('Parent path "%s" does not point to an array.', $parentPath));
        }

        array_splice($parent, (int) $index, 1, $children);
        unset($parent);
    }

    /**
     * Insert one of Cornerstone's prefab elements by name.
     *
     * The values come from the cache list_prefabs fills, never from the
     * registry: reading the registry enters Cornerstone's builder context,
     * and this runs inside a write.
     */
    private function applyPrefab(array &$data, string $path, string $group, string $name): void
    {
        if ($group === '' || $name === '') {
            throw new \InvalidArgumentException('A prefab operation needs "group" and "name". list_prefabs reports both.');
        }

        $prefabs = $this->prefabs ?? new Prefabs();
        $values = $prefabs->cached($group, $name);

        if ($values === null) {
            if ($prefabs->cachedAll()) {
                throw new \InvalidArgumentException(sprintf('No prefab "%s" in group "%s". Use list_prefabs to see them.', $name, $group));
            }

            throw new \InvalidArgumentException(sprintf(
                'Prefab "%s" in group "%s" has not been read yet. Its values come from Cornerstone\'s builder, which a write must not enter, so call list_prefabs with group "%s" and name "%s" first and then run this operation again.',
                $name,
                $group,
                $group,
                $name
            ));
        }

        $this->applyAdd($data, $path, $values);
    }

    /**
     * Strip the ids from a copied subtree.
     *
     * @param  array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function forgetIds(array $element): array
    {
        unset($element['_id'], $element['_c_id'], $element['_parent']);

        if (isset($element['_modules']) && is_array($element['_modules'])) {
            foreach ($element['_modules'] as $key => $child) {
                if (is_array($child)) {
                    $element['_modules'][$key] = $this->forgetIds($child);
                }
            }
        }

        return $element;
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

    public function annotations(): array
    {
        return Annotations::write('Update Layout', true, false);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
