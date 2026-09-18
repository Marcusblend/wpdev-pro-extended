<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * The document settings Pro Extended lets callers set, per document type.
 */
final class DocumentSettings
{
    private const MAX_CODE_BYTES = 262144;

    /**
     * @return string[]
     */
    public static function allowedKeys(string $docType): array
    {
        $common = ['assignments', 'assignment_priority', 'customCSS', 'customJS'];

        return match (true) {
            // A page (or any other content type) overrides which layout, header
            // and footer it uses, and can carry its own CSS and JS.
            str_starts_with($docType, 'content:') => ['layoutSingle', 'layoutHeader', 'layoutFooter', 'customCSS', 'customJS'],
            $docType === 'custom:component' => ['library_group', 'document_visibility', 'customCSS', 'customJS'],
            $docType === 'layout:header'    => [...$common, 'multi_region'],
            $docType === 'layout:footer'    => $common,
            str_starts_with($docType, 'layout:') => [...$common, 'header_enabled', 'footer_enabled'],
            default => [],
        };
    }

    /**
     * Validate and normalize settings for a document type.
     *
     * @param  array<array-key, mixed> $settings
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException With every problem found.
     */
    public static function validate(string $docType, array $settings): array
    {
        $allowed = self::allowedKeys($docType);
        $errors = [];
        $clean = [];

        foreach ($settings as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, $allowed, true)) {
                $errors[] = sprintf(
                    'Unknown setting "%s" for this document type. Allowed: %s.',
                    $key,
                    implode(', ', $allowed)
                );
                continue;
            }

            try {
                $clean[$key] = self::validateValue($key, $value);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return $clean;
    }

    /** The layout override keys a content document carries, and the doc type each points at. */
    public const LAYOUT_OVERRIDES = [
        'layoutSingle' => 'layout:single',
        'layoutHeader' => 'layout:header',
        'layoutFooter' => 'layout:footer',
    ];

    /**
     * Read a layout override: "default", "none", or a document ID.
     *
     * @return array{mode: string, id: int}
     */
    public static function readOverride(mixed $value): array
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;

            if ($id > 0) {
                return ['mode' => 'document', 'id' => $id];
            }
        }

        $mode = is_string($value) ? strtolower(trim($value)) : '';

        if (in_array($mode, ['default', 'none'], true)) {
            return ['mode' => $mode, 'id' => 0];
        }

        throw new \InvalidArgumentException(sprintf(
            '"%s" must be "default", "none", or the ID of the document to use.',
            is_scalar($value) ? (string) $value : 'value'
        ));
    }

    /**
     * Whether settings contain code that needs the unfiltered_html capability.
     *
     * @param array<string, mixed> $settings
     */
    public static function hasCode(array $settings): bool
    {
        foreach (['customCSS', 'customJS'] as $key) {
            if (isset($settings[$key]) && is_string($settings[$key]) && trim($settings[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function validateValue(string $key, mixed $value): mixed
    {
        switch ($key) {
            case 'layoutSingle':
            case 'layoutHeader':
            case 'layoutFooter':
                $override = self::readOverride($value);

                return $override['mode'] === 'document' ? (string) $override['id'] : $override['mode'];

            case 'assignments':
                return self::validateAssignments($value);

            case 'assignment_priority':
                if (is_string($value) && preg_match('/^\d+$/', $value)) {
                    $value = (int) $value;
                }

                if (! is_int($value) || $value < 0) {
                    throw new \InvalidArgumentException('"assignment_priority" must be a non-negative integer (the lowest number wins).');
                }

                return $value;

            case 'multi_region':
            case 'header_enabled':
            case 'footer_enabled':
                if (! is_bool($value)) {
                    throw new \InvalidArgumentException(sprintf('"%s" must be a boolean.', $key));
                }

                return $value;

            case 'library_group':
                if (! is_string($value) || strlen($value) > 100 || preg_match('/[\r\n<>]/', $value)) {
                    throw new \InvalidArgumentException('"library_group" must be a single-line string of at most 100 bytes.');
                }

                return $value;

            case 'document_visibility':
                if (! in_array($value, ['', 'hidden'], true)) {
                    throw new \InvalidArgumentException('"document_visibility" must be "" (visible) or "hidden".');
                }

                return $value;

            case 'customCSS':
            case 'customJS':
                if (! is_string($value) || strlen($value) > self::MAX_CODE_BYTES) {
                    throw new \InvalidArgumentException(sprintf('"%s" must be a string of at most 256 KB.', $key));
                }

                return $value;
        }

        throw new \InvalidArgumentException(sprintf('Unknown setting "%s".', $key));
    }

    /**
     * @return array<int, array{group: bool, condition: string, value: string}>
     *
     * @throws \InvalidArgumentException
     */
    private static function validateAssignments(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
            throw new \InvalidArgumentException('"assignments" must be an array of {group, condition, value} objects.');
        }

        $clean = [];

        foreach ($value as $i => $rule) {
            if (! is_array($rule) || ($rule !== [] && array_is_list($rule))) {
                throw new \InvalidArgumentException(sprintf('assignments[%d] must be an object.', $i));
            }

            $keys = array_map('strval', array_keys($rule));
            sort($keys);

            if ($keys !== ['condition', 'group', 'value']) {
                throw new \InvalidArgumentException(sprintf(
                    'assignments[%d] must have exactly the keys group, condition and value (got: %s).',
                    $i,
                    implode(', ', $keys)
                ));
            }

            if (! is_bool($rule['group'])) {
                throw new \InvalidArgumentException(sprintf('assignments[%d].group must be a boolean.', $i));
            }

            if (! is_string($rule['condition']) || $rule['condition'] === '' || strlen($rule['condition']) > 200) {
                throw new \InvalidArgumentException(sprintf('assignments[%d].condition must be a non-empty string such as "site:entire-site".', $i));
            }

            if (! is_string($rule['value']) || strlen($rule['value']) > 500) {
                throw new \InvalidArgumentException(sprintf('assignments[%d].value must be a string (use "" when the condition needs no value).', $i));
            }

            $clean[] = [
                'group'     => $rule['group'],
                'condition' => $rule['condition'],
                'value'     => $rule['value'],
            ];
        }

        return $clean;
    }
}
