<?php

declare(strict_types=1);

namespace ProExtended\Menus;

/**
 * Normalises menu item operations before anything is written.
 *
 * An operation list is ordered: later operations may point at items added by
 * earlier ones through `ref`, a name the caller gives a new item. Refs are
 * resolved to real IDs by the gateway once each item exists, so this class
 * only proves that every reference can be resolved in order, that required
 * fields are present, and that anchor graphic fields map to the meta keys
 * Cornerstone's navigation elements read.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class MenuItems
{
    /** Operations, in the order they may appear. */
    public const OPS = ['add', 'update', 'move', 'remove', 'set_graphic'];

    /** Item fields a caller may set, mapped to the wp_update_nav_menu_item key. */
    public const FIELDS = [
        'title'       => 'menu-item-title',
        'url'         => 'menu-item-url',
        'type'        => 'menu-item-type',
        'object'      => 'menu-item-object',
        'object_id'   => 'menu-item-object-id',
        'target'      => 'menu-item-target',
        'classes'     => 'menu-item-classes',
        'description' => 'menu-item-description',
        'attr_title'  => 'menu-item-attr-title',
        'xfn'         => 'menu-item-xfn',
        'status'      => 'menu-item-status',
    ];

    /**
     * Anchor graphic fields, mapped to the post meta Cornerstone's nav walker
     * reads. The admin screen writes these on save; a programmatic write has
     * to set the meta itself.
     */
    public const GRAPHIC_FIELDS = [
        'display'      => 'menu-item-anchor_graphic_menu_item_display',
        'icon'         => 'menu-item-anchor_graphic_icon',
        'icon_alt'     => 'menu-item-anchor_graphic_icon_alt',
        'image'        => 'menu-item-anchor_graphic_image_src',
        'image_alt'    => 'menu-item-anchor_graphic_image_src_alt',
        'image_text'   => 'menu-item-anchor_graphic_image_alt',
        'image_text_alt' => 'menu-item-anchor_graphic_image_alt_alt',
        'image_width'  => 'menu-item-anchor_graphic_image_width',
        'image_height' => 'menu-item-anchor_graphic_image_height',
    ];

    /** Item types WordPress accepts. */
    public const TYPES = ['custom', 'post_type', 'post_type_archive', 'taxonomy'];

    /**
     * Validate and normalise an operation list.
     *
     * @param  array<int, mixed> $operations
     * @return array{operations: array<int, array<string, mixed>>, refs: string[], errors: string[]}
     */
    public static function normalize(array $operations): array
    {
        $errors = [];
        $normalized = [];
        $refs = [];

        foreach ($operations as $index => $operation) {
            $label = sprintf('operations[%d]', $index);

            if (! is_array($operation)) {
                $errors[] = $label . ' must be an object.';
                continue;
            }

            $op = $operation['op'] ?? null;

            if (! is_string($op) || ! in_array($op, self::OPS, true)) {
                $errors[] = sprintf('%s.op must be one of %s.', $label, implode(', ', self::OPS));
                continue;
            }

            $row = ['op' => $op];

            if ($op === 'add') {
                $result = self::normalizeAdd($operation, $label, $refs);
            } else {
                $result = self::normalizeExisting($operation, $label, $op, $refs);
            }

            foreach ($result['errors'] as $error) {
                $errors[] = $error;
            }

            if ($result['errors'] !== []) {
                continue;
            }

            $normalized[] = $row + $result['row'];
        }

        return ['operations' => $normalized, 'refs' => array_values($refs), 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed> $operation
     * @param  string[]             $refs Refs defined so far, by name.
     * @return array{row: array<string, mixed>, errors: string[]}
     */
    private static function normalizeAdd(array $operation, string $label, array &$refs): array
    {
        $errors = [];
        $row = [];

        $title = $operation['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $errors[] = $label . '.title is required for an add.';
        }

        $ref = $operation['ref'] ?? null;

        if ($ref !== null) {
            if (! is_string($ref) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref)) {
                $errors[] = $label . '.ref must be 1-64 characters of letters, digits, hyphen or underscore.';
            } elseif (isset($refs[$ref])) {
                $errors[] = sprintf('%s.ref "%s" is already used by an earlier operation.', $label, $ref);
            } else {
                $refs[$ref] = $ref;
                $row['ref'] = $ref;
            }
        }

        $fields = self::fields($operation, $label, $errors);
        $type = $fields['type'] ?? 'custom';

        if ($type === 'custom' && ($fields['url'] ?? '') === '' && $errors === []) {
            $errors[] = $label . '.url is required for a custom link.';
        }

        if ($type !== 'custom' && ($fields['object_id'] ?? 0) === 0 && $errors === []) {
            $errors[] = sprintf('%s.object_id is required when type is "%s".', $label, $type);
        }

        $row['fields'] = $fields;
        $row['parent'] = self::reference($operation, 'parent', $label, $refs, $errors, true);
        $row['position'] = self::position($operation, $label, $errors);
        $row['graphic'] = self::graphic($operation, $label, $errors);

        return ['row' => $row, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed> $operation
     * @param  string[]             $refs
     * @return array{row: array<string, mixed>, errors: string[]}
     */
    private static function normalizeExisting(array $operation, string $label, string $op, array &$refs): array
    {
        $errors = [];
        $row = [];

        $row['item'] = self::reference($operation, 'item', $label, $refs, $errors, false);

        if ($row['item'] === null) {
            $errors[] = $label . '.item is required: an item ID, or the ref of an item added earlier.';
        }

        if ($op === 'update') {
            $row['fields'] = self::fields($operation, $label, $errors);
            $row['graphic'] = self::graphic($operation, $label, $errors);

            if ($row['fields'] === [] && $row['graphic'] === []) {
                $errors[] = $label . ' changes nothing: pass at least one field or a graphic.';
            }
        }

        if ($op === 'move') {
            $row['parent'] = self::reference($operation, 'parent', $label, $refs, $errors, true);
            $row['position'] = self::position($operation, $label, $errors);

            if ($row['parent'] === null && $row['position'] === null) {
                $errors[] = $label . ' needs a parent, a position, or both.';
            }
        }

        if ($op === 'set_graphic') {
            $row['graphic'] = self::graphic($operation, $label, $errors);

            if ($row['graphic'] === []) {
                $errors[] = $label . '.graphic must set at least one field.';
            }
        }

        return ['row' => $row, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed> $operation
     * @param  string[]             $errors
     * @return array<string, mixed>
     */
    private static function fields(array $operation, string $label, array &$errors): array
    {
        $fields = [];

        foreach (self::FIELDS as $name => $unused) {
            if (! array_key_exists($name, $operation)) {
                continue;
            }

            $value = $operation[$name];

            if ($name === 'object_id') {
                if (! is_int($value) || $value <= 0) {
                    $errors[] = $label . '.object_id must be a positive integer.';
                    continue;
                }

                $fields[$name] = $value;
                continue;
            }

            if (! is_string($value)) {
                $errors[] = sprintf('%s.%s must be a string.', $label, $name);
                continue;
            }

            if ($name === 'type' && ! in_array($value, self::TYPES, true)) {
                $errors[] = sprintf('%s.type must be one of %s.', $label, implode(', ', self::TYPES));
                continue;
            }

            if ($name === 'target' && ! in_array($value, ['', '_blank'], true)) {
                $errors[] = $label . '.target must be "" or "_blank".';
                continue;
            }

            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * An item reference: a positive ID, or `ref:<name>` / a bare ref name that
     * an earlier add defined. `parent` also accepts 0 for "top level".
     *
     * @param  array<string, mixed> $operation
     * @param  string[]             $refs
     * @param  string[]             $errors
     */
    private static function reference(array $operation, string $key, string $label, array $refs, array &$errors, bool $allowRoot): ?array
    {
        if (! array_key_exists($key, $operation)) {
            return null;
        }

        $value = $operation[$key];

        if (is_int($value)) {
            if ($value === 0 && $allowRoot) {
                return ['kind' => 'root'];
            }

            if ($value > 0) {
                return ['kind' => 'id', 'id' => $value];
            }

            $errors[] = sprintf('%s.%s must be a positive item ID%s.', $label, $key, $allowRoot ? ', 0 for top level, or a ref' : ' or a ref');

            return null;
        }

        if (is_string($value) && $value !== '') {
            $name = str_starts_with($value, 'ref:') ? substr($value, 4) : $value;

            if (! isset($refs[$name])) {
                $errors[] = sprintf('%s.%s refers to "%s", which no earlier operation added.', $label, $key, $name);

                return null;
            }

            return ['kind' => 'ref', 'ref' => $name];
        }

        $errors[] = sprintf('%s.%s must be an item ID or a ref.', $label, $key);

        return null;
    }

    /**
     * @param  array<string, mixed> $operation
     * @param  string[]             $errors
     */
    private static function position(array $operation, string $label, array &$errors): ?int
    {
        if (! array_key_exists('position', $operation)) {
            return null;
        }

        $value = $operation['position'];

        if (! is_int($value) || $value < 1) {
            $errors[] = $label . '.position must be a positive integer (1 is first).';

            return null;
        }

        return $value;
    }

    /**
     * Anchor graphic fields, keyed by meta key.
     *
     * @param  array<string, mixed> $operation
     * @param  string[]             $errors
     * @return array<string, string>
     */
    private static function graphic(array $operation, string $label, array &$errors): array
    {
        if (! array_key_exists('graphic', $operation)) {
            return [];
        }

        $graphic = $operation['graphic'];

        if (! is_array($graphic)) {
            $errors[] = $label . '.graphic must be an object.';

            return [];
        }

        $meta = [];

        foreach ($graphic as $name => $value) {
            if (! is_string($name) || ! isset(self::GRAPHIC_FIELDS[$name])) {
                $errors[] = sprintf('%s.graphic.%s is not a graphic field (%s).', $label, (string) $name, implode(', ', array_keys(self::GRAPHIC_FIELDS)));
                continue;
            }

            if (is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value)) {
                $errors[] = sprintf('%s.graphic.%s must be a string.', $label, $name);
                continue;
            }

            if ($name === 'display' && ! in_array($value, ['on', 'off'], true)) {
                $errors[] = $label . '.graphic.display must be "on" or "off".';
                continue;
            }

            if (in_array($name, ['image_width', 'image_height'], true) && $value !== '' && ! preg_match('/^\d{1,4}$/', $value)) {
                $errors[] = sprintf('%s.graphic.%s must be a plain pixel number without a unit.', $label, $name);
                continue;
            }

            $meta[self::GRAPHIC_FIELDS[$name]] = $value;
        }

        return $meta;
    }

    /**
     * The parent an add or update writes.
     *
     * wp_update_nav_menu_item() reads a missing menu-item-parent-id as 0, so
     * one has to be sent every time: the parent the operation names, or, for
     * an existing item, the one it already has. A new item with none named
     * goes at the top level.
     *
     * @param int|null                         $named    The parent the operation names, resolved to an ID (0 for the top level), or null.
     * @param array<int, array<string, mixed>> $existing The menu's items by ID, each with its current parent.
     */
    public static function parentId(?int $named, int $itemId, array $existing): int
    {
        if ($named !== null) {
            return $named;
        }

        return $itemId > 0 ? (int) ($existing[$itemId]['parent'] ?? 0) : 0;
    }

    /**
     * Build the nested tree a menu's flat item list describes, for reporting.
     *
     * @param  array<int, array<string, mixed>> $items Each with id, parent, order.
     * @return array<int, array<string, mixed>>
     */
    public static function tree(array $items): array
    {
        $byParent = [];

        foreach ($items as $item) {
            $byParent[(int) ($item['parent'] ?? 0)][] = $item;
        }

        foreach ($byParent as $parent => $children) {
            usort($children, static fn(array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
            $byParent[$parent] = $children;
        }

        $build = static function (int $parent) use (&$build, $byParent): array {
            $branch = [];

            foreach ($byParent[$parent] ?? [] as $item) {
                $id = (int) ($item['id'] ?? 0);
                $children = $id > 0 ? $build($id) : [];

                if ($children !== []) {
                    $item['children'] = $children;
                }

                $branch[] = $item;
            }

            return $branch;
        };

        return $build(0);
    }
}
