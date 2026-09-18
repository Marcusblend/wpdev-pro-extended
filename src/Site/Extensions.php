<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * What the Cornerstone extensions on a site add to it.
 *
 * Each extension brings its own elements, dynamic content, looper providers
 * and sometimes post types, and anything authored against them has to know
 * which are actually there. This reports presence and contributions only: it
 * writes nothing and never reports a package URL, licence or key.
 *
 * Contributions are declared rather than discovered. An extension's elements
 * cannot be told apart from Cornerstone's own at runtime — the registry keeps
 * no record of who registered what — so each extension names the types it
 * brings and this reports which of them the site's registry actually has. A
 * type listed here that is missing means the extension is present but that
 * element is not registered, which is worth knowing on its own.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class Extensions
{
    /**
     * The extensions these builds use, and what each contributes.
     *
     * @var array<string, array<string, mixed>>
     */
    public const KNOWN = [
        'data_tables' => [
            'label'      => 'Cornerstone Data Tables',
            'classes'    => ['Cornerstone_Data_Tables', 'CS_Data_Tables'],
            'constants'  => ['CS_DATA_TABLES_VERSION', 'CORNERSTONE_DATA_TABLES_VERSION'],
            'plugins'    => ['cornerstone-data-tables/cornerstone-data-tables.php'],
            'elements'   => ['data-table', 'cs-data-table'],
            'dc_groups'  => ['datatable'],
            'loopers'    => ['data_table', 'datatable'],
            'post_types' => ['cs_data_table'],
        ],
        'forms' => [
            'label'      => 'Cornerstone Forms',
            'classes'    => ['Cornerstone_Forms', 'CS_Forms'],
            'constants'  => ['CS_FORMS_VERSION', 'CORNERSTONE_FORMS_VERSION'],
            'plugins'    => ['cornerstone-forms/cornerstone-forms.php'],
            'elements'   => ['form', 'form-text', 'form-email', 'form-textarea', 'form-select', 'form-checkbox', 'form-radio', 'form-submit'],
            'dc_groups'  => ['form'],
            'loopers'    => ['form_entries'],
            'post_types' => ['cs_form', 'cs_form_entry'],
        ],
        'charts' => [
            'label'      => 'Cornerstone Charts',
            'classes'    => ['Cornerstone_Charts', 'CS_Charts'],
            'constants'  => ['CS_CHARTS_VERSION', 'CORNERSTONE_CHARTS_VERSION'],
            'plugins'    => ['cornerstone-charts/cornerstone-charts.php'],
            'elements'   => ['chart', 'chart-bar', 'chart-line', 'chart-pie'],
            'dc_groups'  => ['chart'],
            'loopers'    => [],
            'post_types' => [],
        ],
        'sitedrive' => [
            'label'      => 'SiteDrive',
            'classes'    => ['SiteDrive', 'Cornerstone_SiteDrive'],
            'constants'  => ['SITEDRIVE_VERSION', 'CS_SITEDRIVE_VERSION'],
            'plugins'    => ['sitedrive/sitedrive.php', 'cornerstone-sitedrive/cornerstone-sitedrive.php'],
            'elements'   => [],
            'dc_groups'  => ['sitedrive'],
            'loopers'    => ['sitedrive'],
            'post_types' => [],
        ],
        'acf_pro' => [
            'label'      => 'ACF Pro',
            'classes'    => ['acf_pro', 'ACF'],
            'constants'  => ['ACF_PRO', 'ACF_VERSION'],
            'plugins'    => ['advanced-custom-fields-pro/acf.php'],
            'elements'   => [],
            'dc_groups'  => ['acf'],
            'loopers'    => ['acf_repeater', 'acf_relationship', 'acf_gallery'],
            'post_types' => ['acf-field-group', 'acf-field'],
        ],
        'events_calendar' => [
            'label'      => 'The Events Calendar',
            'classes'    => ['Tribe__Events__Main'],
            'constants'  => ['TRIBE_EVENTS_FILE'],
            'plugins'    => ['the-events-calendar/the-events-calendar.php'],
            'elements'   => [],
            'dc_groups'  => ['tribe', 'events'],
            'loopers'    => [],
            'post_types' => ['tribe_events', 'tribe_venue', 'tribe_organizer'],
        ],
    ];

    /**
     * Describe every known extension against what this site actually has.
     *
     * @param  array<string, mixed> $present    What the site reports: active plugin files,
     *                                          defined classes and constants, registered
     *                                          element types, dynamic content groups,
     *                                          looper types and post types.
     * @return array<string, array<string, mixed>>
     */
    public static function describe(array $present): array
    {
        $plugins = self::strings($present['active_plugins'] ?? []);
        $classes = self::strings($present['classes'] ?? []);
        $constants = self::constants($present['constants'] ?? []);
        $elements = self::strings($present['elements'] ?? []);
        $groups = self::strings($present['dc_groups'] ?? []);
        $loopers = self::strings($present['loopers'] ?? []);
        $postTypes = self::strings($present['post_types'] ?? []);

        $report = [];

        foreach (self::KNOWN as $key => $extension) {
            $byPlugin = array_values(array_intersect($extension['plugins'], $plugins));
            $byClass = array_values(array_intersect($extension['classes'], $classes));
            $active = $byPlugin !== [] || $byClass !== [];

            $version = null;

            foreach ($extension['constants'] as $constant) {
                if (isset($constants[$constant]) && is_string($constants[$constant]) && $constants[$constant] !== '') {
                    $version = $constants[$constant];
                    break;
                }
            }

            $row = [
                'label'   => $extension['label'],
                'active'  => $active,
                'version' => $version,
            ];

            if (! $active) {
                $report[$key] = $row;
                continue;
            }

            $row['detected_by'] = $byPlugin !== [] ? 'plugin' : 'class';
            $row['elements'] = self::split($extension['elements'], $elements);
            $row['dynamic_content'] = self::split($extension['dc_groups'], $groups);
            $row['loopers'] = self::split($extension['loopers'], $loopers);
            $row['post_types'] = self::split($extension['post_types'], $postTypes);

            $report[$key] = $row;
        }

        return $report;
    }

    /**
     * Which of the things an extension brings this site actually registered.
     *
     * @param  string[] $expected
     * @param  string[] $actual
     * @return array{present: string[], missing: string[]}
     */
    private static function split(array $expected, array $actual): array
    {
        $present = array_values(array_intersect($expected, $actual));

        return [
            'present' => $present,
            'missing' => array_values(array_diff($expected, $present)),
        ];
    }

    /**
     * @param  mixed $value
     * @return string[]
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  mixed $value
     * @return array<string, string>
     */
    private static function constants(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];

        foreach ($value as $name => $constant) {
            if (is_string($name) && is_scalar($constant)) {
                $clean[$name] = (string) $constant;
            }
        }

        return $clean;
    }
}
