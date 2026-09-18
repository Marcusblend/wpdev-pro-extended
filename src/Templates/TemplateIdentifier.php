<?php

declare(strict_types=1);

namespace ProExtended\Templates;

/**
 * What kind of thing a Cornerstone template is.
 *
 * Every template carries an identifier of "<type>|<subType>", stored on the
 * post as _cs_template_identifier and inside post_content. The pair decides
 * how Cornerstone reads the template's data, and passing one Cornerstone does
 * not know is not a soft failure: Template::instanceFromTemplateType() returns
 * null and Template::create() then calls initialize() on it, which is a fatal.
 * Everything here exists so that never reaches the site.
 *
 *   element|__multi__      a block: a list of elements, insertable anywhere
 *   element|<element type> a preset: saved settings for one type of element
 *   document|layout:header a whole header, footer or layout document
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class TemplateIdentifier
{
    public const ELEMENT = 'element';
    public const DOCUMENT = 'document';

    /** The subType a block uses: "any number of elements, of any type". */
    public const MULTI = '__multi__';

    /**
     * Split "element|headline" into its parts.
     *
     * @return array{type: string, sub_type: string}
     */
    public static function parse(string $identifier): array
    {
        $parts = explode('|', $identifier, 2);

        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            throw new \InvalidArgumentException(sprintf('"%s" is not a template identifier. Use "<type>|<subtype>", such as "element|__multi__" or "document|layout:header".', $identifier));
        }

        return ['type' => trim($parts[0]), 'sub_type' => trim($parts[1])];
    }

    public static function format(string $type, string $subType): string
    {
        return $type . '|' . $subType;
    }

    /**
     * Check a type and subtype before Cornerstone is asked to build one.
     *
     * @param  string[] $elementTypes Element types the registry knows.
     * @param  string[] $documentTypes Document types the resolver allows.
     * @return string[] Reasons it cannot be used; empty when it can.
     */
    public static function problems(string $type, string $subType, array $elementTypes, array $documentTypes): array
    {
        if (! in_array($type, [self::ELEMENT, self::DOCUMENT], true)) {
            return [sprintf('type must be "%s" or "%s", not "%s".', self::ELEMENT, self::DOCUMENT, $type)];
        }

        if ($type === self::DOCUMENT) {
            if ($documentTypes === []) {
                return ['This site did not report which document types it allows, so a document template cannot be created safely.'];
            }

            return in_array($subType, $documentTypes, true)
                ? []
                : [sprintf('"%s" is not a document type this site allows (%s).', $subType, implode(', ', $documentTypes))];
        }

        if ($subType === self::MULTI) {
            return [];
        }

        if ($elementTypes === []) {
            return ['This site did not report its element types, so a preset cannot be created safely.'];
        }

        return in_array($subType, $elementTypes, true)
            ? []
            : [sprintf('"%s" is neither an element type on this site nor "%s" (a block of any elements).', $subType, self::MULTI)];
    }

    /**
     * What to call this template in a listing.
     */
    public static function kind(string $type, string $subType): string
    {
        if ($type === self::DOCUMENT) {
            return 'document';
        }

        if ($type === self::ELEMENT) {
            return $subType === self::MULTI ? 'block' : 'preset';
        }

        return $type;
    }

    /**
     * Whether a template holds saved settings for one element type.
     */
    public static function isPreset(string $type, string $subType): bool
    {
        return $type === self::ELEMENT && $subType !== self::MULTI && $subType !== '';
    }
}
