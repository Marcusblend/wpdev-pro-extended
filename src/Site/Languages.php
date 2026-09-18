<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * WPML, where it is active.
 *
 * A multilingual site keeps one document per language, joined by a translation
 * group, and Cornerstone's own queries hide the ones outside the current
 * language unless a query asks for all of them. Anything that lists or writes
 * documents on such a site has to say which language it is looking at, or it
 * quietly works on one language and appears to have lost the others.
 *
 * Every method is inert when SitePress is not loaded, so callers need no
 * special case for a single-language site.
 */
final class Languages
{
    public static function active(): bool
    {
        return class_exists('SitePress') && function_exists('icl_get_languages');
    }

    /**
     * The site's languages, with the default and the current one marked.
     *
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        if (! self::active()) {
            return ['active' => false];
        }

        $languages = [];

        try {
            foreach ((array) icl_get_languages('skip_missing=0') as $code => $language) {
                if (! is_array($language)) {
                    continue;
                }

                $languages[] = [
                    'code'    => (string) $code,
                    'name'    => (string) ($language['native_name'] ?? $language['translated_name'] ?? $code),
                    'default' => ! empty($language['default_locale']) && self::defaultCode() === (string) $code,
                    'active'  => ! empty($language['active']),
                ];
            }
        } catch (\Throwable) {
            return ['active' => true, 'languages' => [], 'note' => 'WPML is active but its language list could not be read.'];
        }

        return [
            'active'   => true,
            'version'  => defined('ICL_SITEPRESS_VERSION') ? (string) constant('ICL_SITEPRESS_VERSION') : null,
            'default'  => self::defaultCode(),
            'current'  => self::currentCode(),
            'languages' => $languages,
        ];
    }

    public static function defaultCode(): ?string
    {
        if (! self::active()) {
            return null;
        }

        $code = apply_filters('wpml_default_language', null);

        return is_string($code) && $code !== '' ? $code : null;
    }

    public static function currentCode(): ?string
    {
        if (! self::active()) {
            return null;
        }

        $code = apply_filters('wpml_current_language', null);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * The language a post is in, and the posts it is joined to.
     *
     * @return array<string, mixed>|null Null when WPML is not active.
     */
    public static function forPost(int $postId, string $postType): ?array
    {
        if (! self::active() || $postId <= 0) {
            return null;
        }

        $details = apply_filters('wpml_post_language_details', null, $postId);
        $code = is_array($details) && is_string($details['language_code'] ?? null) ? $details['language_code'] : null;

        $trid = apply_filters('wpml_element_trid', null, $postId, 'post_' . $postType);
        $translations = [];

        if ($trid) {
            foreach ((array) apply_filters('wpml_get_element_translations', [], $trid, 'post_' . $postType) as $language => $translation) {
                if (! is_object($translation) || (int) ($translation->element_id ?? 0) === $postId) {
                    continue;
                }

                $translations[] = [
                    'language' => (string) $language,
                    'post_id'  => (int) $translation->element_id,
                ];
            }
        }

        return [
            'language'     => $code,
            'translations' => $translations,
        ];
    }

    /**
     * Ask a WP_Query for every language, the way Cornerstone's own document
     * queries do, so a listing does not silently show one language's documents.
     *
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function allLanguages(array $args): array
    {
        if (self::active()) {
            $args['cs_all_wpml'] = true;
            $args['suppress_filters'] = false;
        }

        return $args;
    }
}
