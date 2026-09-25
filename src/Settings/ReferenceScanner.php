<?php

declare(strict_types=1);

namespace ProExtended\Settings;

use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Json;

/**
 * Finds where a site uses global palette colors or global fonts.
 *
 * Colours are referenced as "global-color:<id>" (optionally with
 * ":<alpha>"), Dynamic Content tokens such as {{dc:global:color id="<id>"}}
 * and the Twig form global.color({id: '<id>'}).
 *
 * A font is referenced by its bare `_id`, which is the form Cornerstone
 * resolves: an element's *_font_family key (and its _alt and _bp_data
 * values), a theme option's *_font_family_selection, a typography
 * parameter's fontFamily, a parameter whose schema (_p_json, or
 * cs_global_parameter_json for Global Parameters) gives it the type
 * "font-family", and that schema's own initial value ("font-family|<id>" or
 * {"type": "font-family", "initial": "<id>"}). The legacy "global-ff:<id>"
 * and "global-fw:<id>|fw-bold" forms are still found, since stored content
 * may hold them, as are the {{dc:global:font id="<id>"}} tokens and the
 * '<id>'|cs_font_family Twig filter. Cornerstone renders a missing color as
 * transparent and a missing font as the browser default, so a removal is
 * refused while any of these remain (unless forced).
 *
 * The matching is plain PHP (unit-tested); scan() reads the site.
 */
final class ReferenceScanner
{
    public const KIND_COLOR = 'color';
    public const KIND_FONT = 'font';

    /** Locations listed per ID (the count is always complete). */
    public const MAX_LOCATIONS = 25;

    /** Post meta that holds Cornerstone element data or settings. */
    private const META_KEYS = ['_cornerstone_data', '_cornerstone_settings', '_cs_template_data', 'cs_template_elements'];

    /** Statuses whose posts are not live documents. */
    private const SKIP_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /** Options that hold references outside the registered theme options. */
    private const EXTRA_OPTIONS = ['cs_option_data', 'cs_theme_variables', 'cs_global_parameter_json', 'cs_global_parameter_data', 'cs_twig_templates'];

    public function __construct(
        private readonly ?ThemeOptionsReader $themeOptions = null,
    ) {}

    // ─── Matching ────────────────────────────────────────────────────────────

    /**
     * References to an ID in a string (JSON text included).
     */
    public static function countInText(string $text, string $kind, string $id): int
    {
        if ($id === '' || ! str_contains($text, $id)) {
            return 0;
        }

        $count = 0;

        foreach (self::patterns($kind, $id) as $pattern) {
            $count += (int) preg_match_all($pattern, $text);
        }

        return $count;
    }

    /**
     * References to an ID in decoded data: every string, plus (for fonts)
     * bare IDs stored under font family keys.
     *
     * A string holding a JSON object or list (a _p_json schema, say) is
     * decoded and read the same way, so the keys inside it count too.
     *
     * @param string[] $fontNames Keys at this level that hold a font family
     *                            (the font-family parameters a schema declares).
     */
    public static function countInData(mixed $data, string $kind, string $id, bool $fontKey = false, array $fontNames = []): int
    {
        if (is_string($data)) {
            $isId = $fontKey && $kind === self::KIND_FONT && $data === $id ? 1 : 0;
            $nested = self::nestedJson($data, $id);

            return $isId + ($nested !== null ? self::countInData($nested, $kind, $id) : self::countInText($data, $kind, $id));
        }

        if (! is_array($data)) {
            return 0;
        }

        $count = 0;

        // A parameter schema entry in full form: {"type": "font-family", "initial": "<id>"}.
        if ($kind === self::KIND_FONT && ($data['type'] ?? null) === 'font-family' && ($data['initial'] ?? null) === $id) {
            $count++;
        }

        // An element that declares its parameters and their values together.
        $parameterFonts = $kind === self::KIND_FONT && isset($data['_p_json'], $data['_p_data']) ? self::fontParameters($data['_p_json']) : [];

        foreach ($data as $key => $value) {
            $key = (string) $key;
            $isFontKey = $fontKey || self::isFontFamilyKey($key) || in_array($key, $fontNames, true);
            $names = match (true) {
                $key === '_p_data'                  => $parameterFonts,
                str_starts_with($key, '_bp_data')   => $fontNames,
                default                             => [],
            };

            $count += self::countInData($value, $kind, $id, $isFontKey, $names);
        }

        return $count;
    }

    /**
     * Whether a key holds a font family: an element's *_font_family, a theme
     * option's *_font_family_selection, a typography parameter's fontFamily.
     */
    public static function isFontFamilyKey(string $key): bool
    {
        return preg_match('/font[_-]?family/i', $key) === 1;
    }

    /**
     * The parameters a schema (_p_json or cs_global_parameter_json, as a
     * JSON string or decoded) gives the type "font-family".
     *
     * @return string[]
     */
    public static function fontParameters(mixed $schema): array
    {
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            $schema = is_array($decoded) ? $decoded : json_decode(stripslashes($schema), true);
        }

        if (! is_array($schema)) {
            return [];
        }

        $names = [];

        foreach ($schema as $name => $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : (is_string($entry) ? explode('|', $entry, 2)[0] : null);

            if ($type === 'font-family' && is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * A string that holds a JSON object or list mentioning the ID, decoded.
     *
     * @return array<mixed>|null
     */
    private static function nestedJson(string $text, string $id): ?array
    {
        $trimmed = ltrim($text);

        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[') || ! str_contains($text, $id)) {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return string[]
     */
    private static function patterns(string $kind, string $id): array
    {
        $q = preg_quote($id, '/');
        $quote = '(?:\\\\?["\']|&quot;)';

        if ($kind === self::KIND_COLOR) {
            return [
                '/global-color:' . $q . '(?![\w-])/',
                '/\{\{dc:(?:global|site):color\b[^}]*?\bid=' . $quote . $q . $quote . '/i',
                '/global\.color\(\s*\{\s*id\s*:\s*' . $quote . $q . $quote . '/',
            ];
        }

        return [
            // Legacy forms that stored content may still hold.
            '/global-f[fw]:' . $q . '(?![\w-])/',
            '/\{\{dc:(?:global|site):font(?:-family|-weight)?\b[^}]*?\bid=' . $quote . $q . $quote . '/i',
            '/' . $quote . $q . '(?:\|[\w-]+)?' . $quote . '\s*\|\s*cs_font_(?:family|weight)/',
            // A parameter schema's shorthand initial value: "font-family|<id>".
            '/(?<![\w-])font-family\|' . $q . '(?![\w-])/',
            // A font family key holding the bare ID, in JSON text that could
            // not be decoded: "text_font_family":"<id>".
            '/' . $quote . '[\w-]*font[_-]?family[\w-]*' . $quote . '\s*:\s*' . $quote . $q . $quote . '/i',
        ];
    }

    // ─── Site scan ───────────────────────────────────────────────────────────

    /**
     * Where each ID is used on the site.
     *
     * @param  string[]          $ids
     * @param  array<int, mixed> $otherItems Palette entries that stay (checked for cross-references).
     * @return array<string, array{count: int, locations: array<int, array<string, mixed>>, more: int}>
     */
    public function scan(string $kind, array $ids, array $otherItems = []): array
    {
        $uses = [];

        foreach ($ids as $id) {
            $uses[$id] = ['count' => 0, 'locations' => [], 'more' => 0];
        }

        if ($ids === []) {
            return $uses;
        }

        foreach ($this->candidateRows($ids) as $row) {
            $decoded = Json::decodeStored($row['value']);
            $source = $decoded ?? wp_unslash((string) $row['value']);

            foreach ($ids as $id) {
                if (! str_contains((string) $row['value'], $id)) {
                    continue;
                }

                $count = self::countInData($source, $kind, $id);

                if ($count > 0) {
                    $this->add($uses[$id], [
                        'type'      => 'post',
                        'post_id'   => (int) $row['post_id'],
                        'post_type' => (string) $row['post_type'],
                        'title'     => (string) $row['post_title'],
                        'field'     => (string) $row['field'],
                        'count'     => $count,
                    ]);
                }
            }
        }

        $options = $this->optionValues();

        // Global Parameters keep their schema and values in two options; a
        // value is a font reference when the schema types it font-family.
        $globalFonts = $kind === self::KIND_FONT ? self::fontParameters(Json::decodeStored($options[GlobalParameters::JSON_OPTION] ?? null) ?? ($options[GlobalParameters::JSON_OPTION] ?? null)) : [];

        foreach ($options as $option => $value) {
            if ($option === GlobalParameters::DATA_OPTION && $globalFonts !== []) {
                $value = Json::decodeStored($value) ?? $value;
            }

            foreach ($ids as $id) {
                $count = $option === GlobalParameters::DATA_OPTION && $globalFonts !== [] && is_array($value)
                    ? self::countInData($value, $kind, $id, false, $globalFonts)
                    : self::countInData([$option => $value], $kind, $id);

                if ($count > 0) {
                    $this->add($uses[$id], ['type' => 'option', 'option' => $option, 'count' => $count]);
                }
            }
        }

        if ($kind === self::KIND_COLOR) {
            foreach ($otherItems as $item) {
                if (! is_array($item) || ! isset($item['_id']) || array_key_exists('children', $item)) {
                    continue;
                }

                foreach ($ids as $id) {
                    $count = self::countInData($item['value'] ?? null, $kind, $id);

                    if ($count > 0) {
                        $this->add($uses[$id], ['type' => 'palette', 'item' => (string) $item['_id'], 'count' => $count]);
                    }
                }
            }
        }

        return $uses;
    }

    /**
     * A short description of the uses, for error messages.
     *
     * @param array<string, array{count: int, locations: array<int, array<string, mixed>>, more: int}> $uses
     */
    public static function describe(array $uses): string
    {
        $parts = [];

        foreach ($uses as $id => $use) {
            if ($use['count'] === 0) {
                continue;
            }

            $where = array_map(static function (array $location): string {
                return match ($location['type']) {
                    'post'    => sprintf('%s #%d', $location['post_type'], $location['post_id']),
                    'option'  => 'option ' . $location['option'],
                    default   => 'palette entry ' . $location['item'],
                };
            }, array_slice($use['locations'], 0, 5));

            $more = count($use['locations']) + $use['more'] - count($where);
            $parts[] = sprintf('"%s" is used %d times (%s%s)', $id, $use['count'], implode(', ', $where), $more > 0 ? sprintf(' and %d more', $more) : '');
        }

        return implode('; ', $parts);
    }

    /**
     * @param array{count: int, locations: array<int, array<string, mixed>>, more: int} $use
     * @param array<string, mixed>                                                     $location
     */
    private function add(array &$use, array $location): void
    {
        $use['count'] += (int) $location['count'];

        if (count($use['locations']) < self::MAX_LOCATIONS) {
            $use['locations'][] = $location;
        } else {
            $use['more']++;
        }
    }

    /**
     * Stored documents and element data that mention any of the IDs.
     *
     * @param  string[] $ids
     * @return \Generator<array{post_id: int, post_type: string, post_title: string, field: string, value: string}>
     */
    private function candidateRows(array $ids): \Generator
    {
        global $wpdb;

        $statuses = implode(', ', array_fill(0, count(self::SKIP_STATUSES), '%s'));

        foreach (array_chunk($ids, 20) as $chunk) {
            $likes = implode(' OR ', array_fill(0, count($chunk), 'm.meta_value LIKE %s'));
            $keys = implode(', ', array_fill(0, count(self::META_KEYS), '%s'));
            $args = array_merge(
                self::META_KEYS,
                self::SKIP_STATUSES,
                array_map(static fn(string $id): string => '%' . $wpdb->esc_like($id) . '%', $chunk)
            );

            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID AS post_id, p.post_type, p.post_title, m.meta_key AS field, m.meta_value AS value
                 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key IN ({$keys}) AND p.post_type <> 'revision' AND p.post_status NOT IN ({$statuses}) AND ({$likes})",
                ...$args
            ), ARRAY_A);

            foreach ((array) $rows as $row) {
                yield $row;
            }

            $types = LayoutService::LAYOUT_POST_TYPES;
            $typeHolders = implode(', ', array_fill(0, count($types), '%s'));
            $likes = implode(' OR ', array_fill(0, count($chunk), 'post_content LIKE %s'));
            $args = array_merge(
                $types,
                self::SKIP_STATUSES,
                array_map(static fn(string $id): string => '%' . $wpdb->esc_like($id) . '%', $chunk)
            );

            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID AS post_id, post_type, post_title, 'post_content' AS field, post_content AS value
                 FROM {$wpdb->posts}
                 WHERE post_type IN ({$typeHolders}) AND post_status NOT IN ({$statuses}) AND ({$likes})",
                ...$args
            ), ARRAY_A);

            foreach ((array) $rows as $row) {
                yield $row;
            }
        }
    }

    /**
     * Theme option values (registered keys, as Cornerstone reads them) and
     * the other options that can hold references.
     *
     * @return array<string, mixed>
     */
    private function optionValues(): array
    {
        $values = [];

        if ($this->themeOptions !== null) {
            try {
                $snapshot = $this->themeOptions->snapshot();

                foreach ($snapshot['keys'] as $key) {
                    $values[$key] = $snapshot['values'][$key] ?? null;
                }
            } catch (\Throwable) {
                $values = [];
            }
        }

        foreach (self::EXTRA_OPTIONS as $option) {
            if (! array_key_exists($option, $values)) {
                $values[$option] = get_option($option, null);
            }
        }

        return $values;
    }
}
