<?php

declare(strict_types=1);

namespace ProExtended\Settings;

use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Json;

/**
 * Finds where a site uses global palette colors or global fonts.
 *
 * References look like "global-color:<id>" (optionally with ":<alpha>"),
 * "global-ff:<id>", "global-fw:<id>|fw-bold", Dynamic Content tokens such
 * as {{dc:global:color id="<id>"}}, the Twig forms global.color({id: '<id>'})
 * and '<id>'|cs_font_family, and, for fonts, the bare ID stored in a
 * "*_font_family*" key. Cornerstone renders a missing color as transparent
 * and a missing font as the browser default, so a removal is refused while
 * any of these remain (unless forced).
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
     */
    public static function countInData(mixed $data, string $kind, string $id, bool $fontKey = false): int
    {
        if (is_string($data)) {
            return ($fontKey && $kind === self::KIND_FONT && $data === $id ? 1 : 0) + self::countInText($data, $kind, $id);
        }

        if (! is_array($data)) {
            return 0;
        }

        $count = 0;

        foreach ($data as $key => $value) {
            $isFontKey = $fontKey || (is_string($key) && str_contains($key, 'font_family'));
            $count += self::countInData($value, $kind, $id, $isFontKey);
        }

        return $count;
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
            '/global-f[fw]:' . $q . '(?![\w-])/',
            '/\{\{dc:(?:global|site):font(?:-family|-weight)?\b[^}]*?\bid=' . $quote . $q . $quote . '/i',
            '/' . $quote . $q . '(?:\|[\w-]+)?' . $quote . '\s*\|\s*cs_font_(?:family|weight)/',
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

        foreach ($this->optionValues() as $option => $value) {
            foreach ($ids as $id) {
                $count = self::countInData([$option => $value], $kind, $id);

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
