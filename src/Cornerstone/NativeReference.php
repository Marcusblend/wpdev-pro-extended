<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Reads the native-feature registries get_native_reference reports, each
 * cached until Cornerstone (or anything else that could change it) changes.
 *
 * A section that cannot be read says so ("unavailable" with a reason) and is
 * never cached, so the next call tries again. Nothing here falls back to a
 * hand-kept list.
 */
final class NativeReference
{
    public const SECTIONS = ['dynamic_content', 'twig', 'conditions', 'loopers', 'parameter_types', 'regions'];

    private const CACHE_PREFIX = 'pe_native_ref_';
    private const CACHE_TTL = 604800; // A week; the version key is what normally retires an entry.

    public function __construct(
        private readonly DynamicContentCatalog $dynamicContent = new DynamicContentCatalog(),
    ) {}

    /**
     * The raw data for a section, from the cache when it is current.
     *
     * @return array{data: array<string, mixed>, cache: string}
     */
    public function section(string $section, bool $refresh = false): array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown section "%s". Sections: %s.', $section, implode(', ', self::SECTIONS)));
        }

        $version = $this->version($section);
        $key = self::CACHE_PREFIX . $section;

        if (! $refresh) {
            $cached = get_transient($key);

            if (is_array($cached) && ($cached['version'] ?? null) === $version && is_array($cached['data'] ?? null)) {
                return ['data' => $cached['data'], 'cache' => 'hit'];
            }
        }

        $data = $this->build($section);

        if (! isset($data['unavailable'])) {
            try {
                set_transient($key, ['version' => $version, 'data' => $data, 'cached_at' => gmdate('c')], self::CACHE_TTL);
            } catch (\Throwable) {
                // An unserializable value only costs the cache.
            }
        }

        return ['data' => $data, 'cache' => $refresh ? 'refreshed' : 'miss'];
    }

    /**
     * Forget every cached section.
     */
    public static function clearCache(): void
    {
        foreach (self::SECTIONS as $section) {
            delete_transient(self::CACHE_PREFIX . $section);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $section): array
    {
        return match ($section) {
            'dynamic_content' => $this->dynamicContent->all(),
            'twig'            => (new TwigCatalog())->read($this->dynamicContent),
            'conditions'      => $this->conditions(),
            'loopers'         => (new LooperCatalog())->read($this->dynamicContent)
                                  ?? ['unavailable' => 'Cornerstone\'s looper-provider control partial could not be read (cs_partial_controls).'],
            'parameter_types' => $this->parameterTypes(),
            'regions'         => $this->regions(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function conditions(): array
    {
        $read = (new ConditionCatalog())->read();

        if ($read['show_conditions'] === null && $read['assignments'] === null) {
            return ['unavailable' => 'Cornerstone\'s Conditionals service could not be read.'];
        }

        return [
            'show_conditions' => $read['show_conditions'] === null
                ? ['unavailable' => 'Conditionals::get_condition_contexts() could not be read.']
                : ['stored_as' => ConditionCatalog::SHOW_CONDITION_SHAPE] + $read['show_conditions'],
            'assignments'     => $read['assignments'] === null
                ? ['unavailable' => 'Conditionals::get_assignment_contexts() could not be read.']
                : ['stored_as' => ConditionCatalog::ASSIGNMENT_SHAPE] + $read['assignments'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterTypes(): array
    {
        $managed = (new ParameterTypeCatalog())->managed();

        if ($managed === null) {
            return ['unavailable' => 'Cornerstone\'s ManagedParameters registry could not be read.'];
        }

        return ParameterTypeCatalog::shape($managed);
    }

    /**
     * @return array<string, mixed>
     */
    private function regions(): array
    {
        $types = DocumentGateway::DOC_TYPES + ['page' => 'content'];
        $rows = (new RegionCatalog())->read($types);

        if (array_filter($rows, static fn (array $row): bool => is_array($row['regions'] ?? null)) === []) {
            return ['unavailable' => 'No document type reported its regions.', 'types' => $rows];
        }

        return [
            'types' => $rows,
            'notes' => [
                'create_document and update_layout take regions by these names; a region the type does not render is never shown.',
                'Pages (and other content) keep a plain element list; their single region is "content".',
            ],
        ];
    }

    /**
     * What a cached section depends on.
     */
    private function version(string $section): string
    {
        $parts = [
            defined('CS_VERSION') ? (string) constant('CS_VERSION') : '',
            defined('PE_VERSION') ? (string) constant('PE_VERSION') : '',
            function_exists('wp_get_theme') ? (string) wp_get_theme()->get('Version') : '',
            function_exists('get_option') ? get_option('active_plugins', []) : [],
            function_exists('get_locale') ? get_locale() : '',
        ];

        switch ($section) {
            case 'dynamic_content':
                // ACF field groups and similar registries are posts.
                $parts[] = function_exists('wp_cache_get_last_changed') ? wp_cache_get_last_changed('posts') : '';
                break;

            case 'twig':
                foreach (['cs_twig_enabled', 'cs_twig_extension_wordpress', 'cs_twig_extension_html_extra', 'cs_twig_extension_string_extra', 'cs_twig_extension_directory_loader', 'cs_twig_extension_advanced', 'cs_twig_extension_debug', 'cs_twig_autoescape', 'cs_twig_cache', 'cs_twig_templates'] as $option) {
                    $parts[] = function_exists('get_option') ? get_option($option, null) : null;
                }
                $parts[] = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
                $parts[] = function_exists('wp_cache_get_last_changed') ? wp_cache_get_last_changed('posts') : '';
                break;

            case 'conditions':
            case 'loopers':
                $parts[] = function_exists('get_post_types') ? array_keys(get_post_types()) : [];
                $parts[] = function_exists('get_taxonomies') ? array_keys(get_taxonomies()) : [];
                $parts[] = function_exists('wp_roles') ? array_keys(wp_roles()->roles) : [];
                $parts[] = function_exists('get_option') ? [get_option('show_on_front'), get_option('page_on_front'), get_option('page_for_posts')] : [];
                break;
        }

        return md5((string) json_encode($parts));
    }
}
