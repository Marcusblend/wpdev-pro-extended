<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * The regions each Cornerstone document type renders.
 *
 * Headers, footers and theme layouts declare them with getRegions() (filtered
 * by `cs_layout_type_required_regions`); components and pages build their
 * single region in getInitialElements(). Both are read from a fresh document
 * of each type, the way Cornerstone builds one for the builder.
 *
 * regionsFromElements() is pure, so it is unit-tested without a site.
 */
final class RegionCatalog
{
    private const DOCUMENT_CLASS = 'Themeco\\Cornerstone\\Documents\\Document';

    /**
     * Region names in an element tree or flat element map.
     *
     * @param  array<int|string, mixed> $elements
     * @return string[]
     */
    public static function regionsFromElements(array $elements): array
    {
        $regions = [];

        $visit = static function (mixed $element) use (&$visit, &$regions): void {
            if (! is_array($element)) {
                return;
            }

            if (($element['_type'] ?? null) === 'region' && is_string($element['_region'] ?? null) && $element['_region'] !== '') {
                $regions[] = $element['_region'];
            }

            foreach ((array) ($element['_modules'] ?? []) as $child) {
                if (is_array($child)) {
                    $visit($child);
                }
            }
        };

        foreach ($elements as $element) {
            $visit($element);
        }

        return array_values(array_unique($regions));
    }

    /**
     * Region-related settings a document type declares (the header's
     * multi_region), with the builder's own description.
     *
     * @return array<string, string>
     */
    private static function settingNotes(object $document): array
    {
        if (! method_exists($document, 'getGeneralControls')) {
            return [];
        }

        $notes = [];

        foreach ((array) $document->getGeneralControls() as $control) {
            $key = is_array($control) ? ($control['key'] ?? null) : null;

            if (is_string($key) && str_contains($key, 'region') && is_string($control['description'] ?? null)) {
                $notes[$key] = $control['description'];
            }
        }

        return $notes;
    }

    /**
     * Doc type => regions, or an "unavailable" reason per type.
     *
     * @param  array<string, string> $types Pro Extended type => Cornerstone doc type.
     * @return array<int, array<string, mixed>>
     */
    public function read(array $types): array
    {
        $rows = [];
        $available = function_exists('cornerstone') && class_exists(self::DOCUMENT_CLASS) && method_exists(self::DOCUMENT_CLASS, 'create');

        foreach ($types as $type => $docType) {
            $row = ['type' => $type, 'doc_type' => $docType];

            if (! $available) {
                $rows[] = $row + ['regions' => null, 'unavailable' => 'Cornerstone\'s Document class is not loaded.'];
                continue;
            }

            $regions = BuilderContext::read(function () use ($docType): ?array {
                $class = '\\' . self::DOCUMENT_CLASS;
                $document = $class::create($docType);

                if (! is_object($document)) {
                    return null;
                }

                if (method_exists($document, 'getRegions')) {
                    $declared = apply_filters('cs_layout_type_required_regions', $document->getRegions(), $document);

                    return is_array($declared) ? ['regions' => array_values(array_map('strval', $declared)), 'notes' => self::settingNotes($document)] : null;
                }

                if (method_exists($document, 'getInitialElements')) {
                    $initial = $document->getInitialElements();

                    return is_array($initial) ? ['regions' => self::regionsFromElements($initial), 'notes' => []] : null;
                }

                return null;
            }, null);

            if (! is_array($regions) || $regions['regions'] === []) {
                $rows[] = $row + ['regions' => null, 'unavailable' => 'Cornerstone did not report regions for this document type.'];
                continue;
            }

            $row['regions'] = $regions['regions'];

            if ($regions['notes'] !== []) {
                $row['settings'] = $regions['notes'];
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
