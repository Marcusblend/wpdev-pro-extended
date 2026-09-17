<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Walks layout data in any of Cornerstone's storage shapes.
 */
final class ElementTree
{
    /**
     * Element types whose content is emitted without HTML filtering.
     */
    public const RAW_CONTENT_TYPES = ['raw-content', 'classic:raw-content'];

    /**
     * Whether any element in the data has one of the given `_type` values.
     *
     * @param string[] $types
     */
    public static function containsType(mixed $data, array $types): bool
    {
        if (! is_array($data)) {
            return false;
        }

        if (isset($data['_type']) && is_string($data['_type']) && in_array($data['_type'], $types, true)) {
            return true;
        }

        foreach ($data as $value) {
            if (is_array($value) && self::containsType($value, $types)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the data contains a Raw Content element.
     */
    public static function containsRawContent(mixed $data): bool
    {
        return self::containsType($data, self::RAW_CONTENT_TYPES);
    }
}
