<?php

declare(strict_types=1);

namespace ProExtended\Site;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Layouts\LayoutService;
use ProExtended\Menus\MenuGateway;
use ProExtended\Menus\MenuItems;
use ProExtended\Settings\GlobalParameters;
use ProExtended\Settings\ThemeOptionsReader;
use ProExtended\Settings\VariableItems;

/**
 * Everything that makes a site look like itself, in one object.
 *
 * Across a hundred sites the two recurring needs are insurance — a record of
 * what the globals were before someone changed them — and reuse: taking a
 * starter setup from one build into the next. Both want the same thing: the
 * palette, the fonts, the Global CSS, the Theme Options that differ from their
 * defaults, the variables, the global parameters, and the menus.
 *
 * What a snapshot deliberately does not carry is the documents' element data.
 * A site's layouts run to megabytes, they are what backups and .tco archives
 * already handle well, and putting them in the same object as the settings
 * would make the common case — "what were the globals last week" — unusable.
 * The document set is recorded as an inventory instead, so a restore can say
 * what is missing rather than pretend to recreate it.
 */
final class SiteSnapshot
{
    public const PARTS = ['colors', 'fonts', 'global_css', 'theme_options', 'variables', 'global_parameters', 'menus', 'documents'];

    /** Parts a restore can actually write back. */
    public const RESTORABLE = ['colors', 'fonts', 'global_css', 'theme_options', 'variables', 'global_parameters'];

    public function __construct(
        private readonly DocumentGateway $gateway,
        private readonly LayoutService $layouts,
        private readonly ThemeOptionsReader $reader,
        private readonly MenuGateway $menus,
    ) {}

    /**
     * Take a snapshot.
     *
     * @param  string[] $parts
     * @return array<string, mixed>
     */
    public function capture(array $parts): array
    {
        $snapshot = [
            'meta' => [
                'taken_at'            => gmdate('c'),
                'site_url'            => get_site_url(),
                'plugin_version'      => defined('PE_VERSION') ? (string) constant('PE_VERSION') : null,
                'cornerstone_version' => defined('CS_VERSION') ? (string) constant('CS_VERSION') : null,
                'parts'               => array_values($parts),
            ],
        ];

        foreach ($parts as $part) {
            $snapshot[$part] = match ($part) {
                'colors'            => $this->option('cornerstone_color_items'),
                'fonts'             => [
                    'items'  => $this->option('cornerstone_font_items'),
                    'config' => $this->option('cornerstone_font_config'),
                ],
                'global_css'        => $this->globalCss(),
                'theme_options'     => $this->themeOptions(),
                'variables'         => $this->option(VariableItems::OPTION),
                'global_parameters' => [
                    'json' => $this->gateway->getThemeOption(GlobalParameters::JSON_OPTION),
                    'data' => $this->gateway->getThemeOption(GlobalParameters::DATA_OPTION),
                ],
                'menus'             => $this->menus(),
                'documents'         => $this->documents(),
                default             => null,
            };
        }

        return $snapshot;
    }

    /**
     * What restoring a snapshot would change, and optionally do it.
     *
     * @param  array<string, mixed> $snapshot
     * @param  string[]             $parts
     * @return array{changes: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, written: string[]}
     */
    public function restore(array $snapshot, array $parts, bool $dryRun, \Closure $backup): array
    {
        $changes = [];
        $skipped = [];
        $written = [];

        foreach ($parts as $part) {
            if (! in_array($part, self::RESTORABLE, true)) {
                $skipped[] = ['part' => $part, 'reason' => sprintf('"%s" is recorded for reference but not written back; %s.', $part, $part === 'documents' ? 'use import_tco or deploy_layout for documents' : 'menus are rebuilt with create_menu and update_menu')];
                continue;
            }

            if (! array_key_exists($part, $snapshot)) {
                $skipped[] = ['part' => $part, 'reason' => 'The snapshot does not carry this part.'];
                continue;
            }

            foreach ($this->optionsFor($part, $snapshot[$part]) as $option => $value) {
                $current = get_option($option, null);

                if ($this->same($current, $value)) {
                    continue;
                }

                $changes[] = [
                    'part'   => $part,
                    'option' => $option,
                    'from'   => $this->describe($current),
                    'to'     => $this->describe($value),
                ];

                if ($dryRun) {
                    continue;
                }

                $backup($option);
                update_option($option, $value);
                $written[] = $option;
            }
        }

        if (! $dryRun && $written !== []) {
            $this->gateway->purgeGenerated();
        }

        return ['changes' => $changes, 'skipped' => $skipped, 'written' => $written];
    }

    /**
     * The options one part of a snapshot holds.
     *
     * @return array<string, mixed>
     */
    private function optionsFor(string $part, mixed $value): array
    {
        return match ($part) {
            'colors'     => ['cornerstone_color_items' => $value],
            'fonts'      => is_array($value)
                ? array_filter([
                    'cornerstone_font_items'  => $value['items'] ?? null,
                    'cornerstone_font_config' => $value['config'] ?? null,
                ], static fn (mixed $v): bool => $v !== null)
                : [],
            'global_css' => [$this->gateway->globalCssKey() => is_array($value) ? ($value['css'] ?? '') : $value],
            'variables'  => [VariableItems::OPTION => $value],
            'global_parameters' => is_array($value)
                ? [
                    GlobalParameters::JSON_OPTION => $value['json'] ?? '',
                    GlobalParameters::DATA_OPTION => $value['data'] ?? [],
                ]
                : [],
            'theme_options' => is_array($value) ? $value : [],
            default => [],
        };
    }

    private function option(string $name): mixed
    {
        return get_option($name, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function globalCss(): array
    {
        $key = $this->gateway->globalCssKey();
        $css = $this->gateway->getThemeOption($key);
        $css = is_string($css) ? $css : '';

        return ['key' => $key, 'bytes' => strlen($css), 'css' => $css];
    }

    /**
     * Only the Theme Options this site has actually changed.
     *
     * All 300-odd keys would bury the handful that were deliberate, and
     * restoring a default over a default changes nothing anyway.
     *
     * @return array<string, mixed>
     */
    private function themeOptions(): array
    {
        try {
            $snapshot = $this->reader->snapshot();
        } catch (\Throwable) {
            return [];
        }

        $changed = [];

        // Keys another part of the snapshot already owns are left out, or the
        // two would restore over each other: the variables and the global
        // parameters are theme options too, and so is the Global CSS.
        $owned = array_merge(
            ThemeOptionsReader::CODE_KEYS,
            [VariableItems::OPTION, GlobalParameters::JSON_OPTION, GlobalParameters::DATA_OPTION, $this->gateway->globalCssKey()]
        );

        foreach ($snapshot['values'] as $key => $value) {
            // Only keys Cornerstone actually registers: the values also carry
            // the responsive bookkeeping (_bp_base and the like), which is not
            // an option anyone can write on its own.
            if (! is_string($key) || str_starts_with($key, '_') || ! in_array($key, $snapshot['keys'], true)) {
                continue;
            }

            if (in_array($key, $owned, true)) {
                continue;
            }

            $default = $snapshot['defaults'][$key] ?? null;

            if ($value !== $default) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function menus(): array
    {
        $rows = [];

        foreach (wp_get_nav_menus() as $menu) {
            if (! $menu instanceof \WP_Term) {
                continue;
            }

            $rows[] = [
                'id'        => (int) $menu->term_id,
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'locations' => $this->menus->locations((int) $menu->term_id),
                'items'     => MenuItems::tree($this->menus->items((int) $menu->term_id)),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(): array
    {
        try {
            $layouts = $this->layouts->listAll();
        } catch (\Throwable) {
            return ['count' => 0, 'documents' => []];
        }

        $rows = [];

        foreach ($layouts as $layout) {
            $rows[] = [
                'id'       => $layout['id'] ?? null,
                'title'    => $layout['title'] ?? null,
                'doc_type' => $layout['doc_type'] ?? null,
                'status'   => $layout['status'] ?? null,
            ];
        }

        return ['count' => count($rows), 'documents' => $rows];
    }

    private function same(mixed $a, mixed $b): bool
    {
        // An option that has never been set reads as null; a snapshot of it
        // reads as "" or []. Restoring one over the other changes nothing, and
        // reporting it as a change makes every diff look dirty.
        if ($this->isEmpty($a) && $this->isEmpty($b)) {
            return true;
        }

        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }

    private function describe(mixed $value): mixed
    {
        if (is_array($value)) {
            return ['type' => 'array', 'count' => count($value)];
        }

        if (is_string($value) && strlen($value) > 120) {
            return ['type' => 'string', 'bytes' => strlen($value)];
        }

        return $value;
    }
}
