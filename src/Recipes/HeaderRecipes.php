<?php

declare(strict_types=1);

namespace ProExtended\Recipes;

/**
 * Element trees for Pro headers, including mega menus.
 *
 * These build the same structures the builder does: a header region holds a
 * `bar`, a bar holds a `container`, and a container holds the logo, the
 * navigation and any toggles. A mega menu is a `layout-dropdown` whose panel
 * holds ordinary elements — columns of links, headings, a feature block —
 * which is how Themeco's own mega menu prefabs are built.
 *
 * Every element here is written without migration markers or breakpoint tags:
 * the caller stamps them with ElementStamper, so a collapsed navigation does
 * not fall back to Cornerstone's legacy off-canvas behaviour.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class HeaderRecipes
{
    public const NAMES = ['header.simple', 'header.mega', 'mega_menu_panel'];

    /**
     * Build a recipe.
     *
     * @param  array<string, mixed> $options
     * @return array{data: array<string, mixed>, warnings: string[]}
     */
    public static function build(string $name, array $options): array
    {
        return match ($name) {
            'header.simple'   => self::header($options, false),
            'header.mega'     => self::header($options, true),
            'mega_menu_panel' => ['data' => self::panel($options), 'warnings' => []],
            default           => throw new \InvalidArgumentException(sprintf('Unknown preset "%s". Known presets: %s.', $name, implode(', ', self::NAMES))),
        };
    }

    /**
     * A header document: settings plus regions, the shape create_document and
     * deploy_layout take.
     *
     * @param  array<string, mixed> $options
     * @return array{data: array<string, mixed>, warnings: string[]}
     */
    private static function header(array $options, bool $withMega): array
    {
        $warnings = [];
        $menu = self::menuRef($options['menu'] ?? null, $warnings);
        $logo = $options['logo'] ?? null;
        $bar = [
            '_type'    => 'bar',
            '_region'  => 'top',
            '_label'   => 'Header Bar',
            '_modules' => [],
        ];

        $container = [
            '_type'                   => 'container',
            '_region'                 => 'top',
            '_label'                  => 'Header Container',
            'container_flex_justify'  => 'space-between',
            'container_flex_align'    => 'center',
            '_modules'                => [],
        ];

        if (is_string($logo) && $logo !== '') {
            $container['_modules'][] = [
                '_type'       => 'image',
                '_region'     => 'top',
                '_label'      => 'Logo',
                'image_src'   => $logo,
                'image_alt'   => is_string($options['logo_alt'] ?? null) ? $options['logo_alt'] : '',
                'image_link'  => true,
                'image_href'  => '{{dc:global:home_url}}',
            ];
        }

        $nav = [
            '_type'   => 'nav-inline',
            '_region' => 'top',
            '_label'  => 'Primary Navigation',
            'menu'    => $menu,
        ];

        $navGroup = [
            '_type'    => 'layout-div',
            '_region'  => 'top',
            '_label'   => 'Navigation Group',
            'layout_div_flexbox'        => true,
            'layout_div_flex_direction' => 'row',
            'layout_div_flex_align'     => 'center',
            'layout_div_flex_gap'       => '1.5em',
            '_modules' => [$nav],
        ];

        if ($withMega) {
            $navGroup['_modules'][] = self::panel($options);
        }

        if (($options['collapsed'] ?? true) !== false) {
            $navGroup['_modules'][] = [
                '_type'     => 'nav-collapsed',
                '_region'   => 'top',
                '_label'    => 'Mobile Navigation',
                'menu'      => $menu,
                'hide_bp'   => 'lg xl',
            ];
            $nav['hide_bp'] = 'xs sm md';
            $navGroup['_modules'][0] = $nav;
        }

        $container['_modules'][] = $navGroup;
        $bar['_modules'][] = $container;

        $data = [
            'settings' => [
                'customCSS'           => '',
                'customJS'            => '',
                'assignments'         => [],
                'assignment_priority' => 10,
                'multi_region'        => false,
            ],
            'regions' => [
                'top'    => [$bar],
                'right'  => [],
                'bottom' => [],
                'left'   => [],
            ],
        ];

        return ['data' => $data, 'warnings' => $warnings];
    }

    /**
     * A mega menu: a dropdown whose panel holds a row of link columns.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function panel(array $options): array
    {
        $label = is_string($options['trigger'] ?? null) && $options['trigger'] !== '' ? $options['trigger'] : 'Explore';
        $columns = is_array($options['columns'] ?? null) ? $options['columns'] : [];
        $region = is_string($options['region'] ?? null) ? $options['region'] : 'top';
        $cells = [];

        foreach ($columns as $index => $column) {
            if (! is_array($column)) {
                continue;
            }

            $items = [];
            $heading = $column['heading'] ?? null;

            if (is_string($heading) && $heading !== '') {
                $items[] = [
                    '_type'                       => 'headline',
                    '_region'                     => $region,
                    '_label'                      => 'Column Heading',
                    'text_content'                => $heading,
                    'text_tag'                    => 'h3',
                    'text_font_size'              => '1em',
                    'text_text_transform'         => 'uppercase',
                    'text_letter_spacing'         => '0.05em',
                    'text_margin'                 => '0em 0em 0.5em 0em',
                ];
            }

            foreach ((array) ($column['links'] ?? []) as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $items[] = [
                    '_type'                        => 'button',
                    '_region'                      => $region,
                    '_label'                       => 'Mega Menu Link',
                    'anchor_text_primary_content'  => (string) ($link['label'] ?? 'Link'),
                    'anchor_href'                  => (string) ($link['href'] ?? '#'),
                    'anchor_text_primary_font_size' => '0.9em',
                ];
            }

            $cells[] = [
                '_type'   => 'layout-cell',
                '_region' => $region,
                '_label'  => 'Mega Menu Column ' . ($index + 1),
                'layout_cell_flexbox'        => true,
                'layout_cell_flex_direction' => 'column',
                'layout_cell_flex_gap'       => '0.5em',
                '_modules' => $items,
            ];
        }

        if ($cells === []) {
            $cells[] = [
                '_type'    => 'layout-cell',
                '_region'  => $region,
                '_label'   => 'Mega Menu Column 1',
                '_modules' => [],
            ];
        }

        $gridColumns = implode(' ', array_fill(0, count($cells), '1fr'));

        return [
            '_type'   => 'layout-dropdown',
            '_region' => $region,
            '_label'  => 'Mega Menu',
            'dropdown_anchor_text_primary_content' => $label,
            'dropdown_width'      => '60em',
            'dropdown_max_width'  => '90vw',
            'dropdown_padding'    => '2em',
            '_modules' => [[
                '_type'   => 'layout-grid',
                '_region' => $region,
                '_label'  => 'Mega Menu Columns',
                'layout_grid_template_columns' => $gridColumns,
                'layout_grid_gap_column'       => '2em',
                'layout_grid_gap_row'          => '2em',
                '_modules' => $cells,
            ]],
        ];
    }

    /**
     * Normalise a menu reference: a term ID, "menu:<id>", or "location:<slug>".
     *
     * @param  string[] $warnings
     */
    private static function menuRef(mixed $menu, array &$warnings): string
    {
        if (is_int($menu) && $menu > 0) {
            return 'menu:' . $menu;
        }

        if (is_string($menu) && $menu !== '') {
            if (str_starts_with($menu, 'menu:') || str_starts_with($menu, 'location:') || str_starts_with($menu, 'sample:')) {
                return $menu;
            }

            if (ctype_digit($menu)) {
                return 'menu:' . $menu;
            }

            return 'location:' . $menu;
        }

        $warnings[] = 'No menu was given, so the navigation uses Cornerstone\'s sample menu. Pass menu as a term ID, "menu:<id>" or "location:<slug>".';

        return 'sample:default';
    }
}
