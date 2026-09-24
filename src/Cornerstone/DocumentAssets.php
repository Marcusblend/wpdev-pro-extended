<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Cornerstone's Custom Document Assets (7.9): external scripts and
 * stylesheets loaded site-wide or with one document.
 *
 * Storage, from integration/DocumentAssets/DocumentAssets.php:
 * - Site-wide: the theme options `cs_custom_scripts` and `cs_custom_styles`
 *   (registered with cs_stack_register_options, so their own options).
 * - Per document: the settings `customScripts` and `customStyles`, which
 *   Cornerstone keeps in the post meta `_cs_document_scripts` and
 *   `_cs_document_styles` (written on cs_save_document, read back over the
 *   document's settings on load). A component's assets load with every
 *   document that uses it.
 * - Each list holds items shaped like the builder's list control:
 *   scripts {src, id, type, deps, ver, async, defer, nomodule, in_footer},
 *   styles {src, id, rel, media}. They are enqueued with wp_enqueue_script /
 *   wp_enqueue_style, the id becoming the handle.
 * - Managing them takes the Cornerstone permission `global.document_assets`;
 *   without it Cornerstone leaves the stored assets alone on save.
 *
 * Pro Extended accepts https URLs only. The validation is pure, so it is
 * unit-tested without a site.
 */
final class DocumentAssets
{
    public const PERMISSION = 'global.document_assets';

    public const META_SCRIPTS = '_cs_document_scripts';
    public const META_STYLES = '_cs_document_styles';

    public const OPTION_SCRIPTS = 'cs_custom_scripts';
    public const OPTION_STYLES = 'cs_custom_styles';

    public const SETTING_SCRIPTS = 'customScripts';
    public const SETTING_STYLES = 'customStyles';

    public const MAX_ITEMS = 50;

    /** A new item, as the builder's list control starts one (scriptListControl). */
    public const SCRIPT_DEFAULTS = [
        'src'       => '',
        'id'        => '',
        'type'      => '',
        'deps'      => '',
        'ver'       => '',
        'async'     => false,
        'defer'     => false,
        'nomodule'  => false,
        'in_footer' => true,
    ];

    /** A new item, as the builder's list control starts one (styleListControl). */
    public const STYLE_DEFAULTS = [
        'src'   => '',
        'id'    => '',
        'rel'   => 'stylesheet',
        'media' => '',
    ];

    public const SCRIPT_TYPES = ['', 'module'];

    public const STYLE_RELS = ['stylesheet', 'preload', 'prefetch', 'modulepreload', 'alternate'];

    /** Backup entry key LayoutService stores the asset meta under. */
    private const BACKUP_KEY = 'document_assets';

    // ─── Validation ──────────────────────────────────────────────────────────

    /**
     * Check and complete a script list.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \InvalidArgumentException With every problem found.
     */
    public static function scripts(mixed $value, string $label = self::SETTING_SCRIPTS): array
    {
        return self::normalize($value, $label, 'script');
    }

    /**
     * Check and complete a stylesheet list.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \InvalidArgumentException With every problem found.
     */
    public static function styles(mixed $value, string $label = self::SETTING_STYLES): array
    {
        return self::normalize($value, $label, 'style');
    }

    /**
     * Problems with a site-wide asset option value, for update_theme_options.
     *
     * @return string[]
     */
    public static function optionErrors(string $option, mixed $value): array
    {
        try {
            $option === self::OPTION_SCRIPTS ? self::scripts($value, $option) : self::styles($value, $option);
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    /**
     * Whether a URL is one Pro Extended stores: absolute https, a host, no
     * credentials, nothing that could break out of an attribute.
     */
    public static function isHttpsUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s<>"\'`\\\\]/', $url)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (string) ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    private static function normalize(mixed $value, string $label, string $kind): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a list of %s objects.', $label, $kind === 'script' ? '{src, id, type, deps, ver, async, defer, nomodule, in_footer}' : '{src, id, rel, media}'));
        }

        if (count($value) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf('"%s" holds at most %d items.', $label, self::MAX_ITEMS));
        }

        $defaults = $kind === 'script' ? self::SCRIPT_DEFAULTS : self::STYLE_DEFAULTS;
        $errors = [];
        $clean = [];
        $handles = [];

        foreach ($value as $i => $item) {
            $where = sprintf('%s[%d]', $label, $i);

            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[] = sprintf('%s must be an object.', $where);
                continue;
            }

            foreach (array_keys($item) as $key) {
                if (! array_key_exists((string) $key, $defaults)) {
                    $errors[] = sprintf('%s: unknown key "%s" (allowed: %s).', $where, (string) $key, implode(', ', array_keys($defaults)));
                }
            }

            $row = array_merge($defaults, array_intersect_key($item, $defaults));
            $src = $row['src'];

            if (! is_string($src) || ! self::isHttpsUrl(trim($src))) {
                $errors[] = sprintf('%s.src must be an absolute https:// URL (no credentials, spaces or quotes).', $where);
            } else {
                $row['src'] = trim($src);
                $extension = strtolower(pathinfo((string) parse_url($row['src'], PHP_URL_PATH), PATHINFO_EXTENSION));
                $allowed = $kind === 'script' ? ['', 'js', 'mjs'] : ['', 'css'];

                // Cornerstone applies the same rule before it downloads an asset.
                if (! in_array($extension, $allowed, true)) {
                    $errors[] = sprintf('%s.src ends in ".%s", which is not a %s file.', $where, $extension, $kind === 'script' ? 'JavaScript' : 'CSS');
                }
            }

            if (! is_string($row['id']) || ! preg_match('/^[A-Za-z0-9_\-]{0,100}$/', $row['id'])) {
                $errors[] = sprintf('%s.id must be a handle of letters, digits, "_" or "-" (or "").', $where);
            } elseif ($row['id'] !== '') {
                $handle = strtolower($row['id']);

                if (isset($handles[$handle])) {
                    $errors[] = sprintf('%s.id "%s" is used twice; WordPress would load only the first.', $where, $row['id']);
                }

                $handles[$handle] = true;
            }

            if ($kind === 'script') {
                if (! in_array($row['type'], self::SCRIPT_TYPES, true)) {
                    $errors[] = sprintf('%s.type must be "" or "module".', $where);
                }

                if (! is_string($row['deps']) || ! preg_match('/^[A-Za-z0-9_\-,\s]{0,500}$/', $row['deps'])) {
                    $errors[] = sprintf('%s.deps must be a comma-separated list of script handles.', $where);
                }

                if (! is_string($row['ver']) || ! preg_match('/^[A-Za-z0-9._\-]{0,50}$/', $row['ver'])) {
                    $errors[] = sprintf('%s.ver must be a short version string (letters, digits, ".", "_", "-").', $where);
                }

                foreach (['async', 'defer', 'nomodule', 'in_footer'] as $flag) {
                    if (! is_bool($row[$flag])) {
                        $errors[] = sprintf('%s.%s must be true or false.', $where, $flag);
                    }
                }
            } else {
                if (! in_array($row['rel'], self::STYLE_RELS, true)) {
                    $errors[] = sprintf('%s.rel must be one of %s.', $where, implode(', ', self::STYLE_RELS));
                }

                if (! is_string($row['media']) || strlen($row['media']) > 200 || preg_match('/[<>"\'`\r\n{};]/', $row['media'])) {
                    $errors[] = sprintf('%s.media must be a media query such as "screen and (max-width: 600px)" (or "").', $where);
                }
            }

            $clean[] = $row;
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return $clean;
    }

    // ─── Stored values ───────────────────────────────────────────────────────

    /**
     * A document's assets as Cornerstone loads them: the meta when it is
     * set, otherwise what the stored settings hold.
     *
     * @param  array<string, mixed> $settings The document's stored settings.
     * @return array{scripts: array<int, mixed>, styles: array<int, mixed>}
     */
    public static function forDocument(int $postId, array $settings = []): array
    {
        $scripts = get_post_meta($postId, self::META_SCRIPTS, true);
        $styles = get_post_meta($postId, self::META_STYLES, true);

        return [
            'scripts' => is_array($scripts) ? array_values($scripts) : (is_array($settings[self::SETTING_SCRIPTS] ?? null) ? array_values($settings[self::SETTING_SCRIPTS]) : []),
            'styles'  => is_array($styles) ? array_values($styles) : (is_array($settings[self::SETTING_STYLES] ?? null) ? array_values($settings[self::SETTING_STYLES]) : []),
        ];
    }

    /**
     * Write a document's asset meta the way DocumentAssets::saveSettings()
     * does. update_post_meta() unslashes, so the lists are slashed first.
     *
     * @param array<int, array<string, mixed>>|null $scripts Null leaves the scripts alone.
     * @param array<int, array<string, mixed>>|null $styles  Null leaves the stylesheets alone.
     */
    public static function write(int $postId, ?array $scripts, ?array $styles): void
    {
        if ($scripts !== null) {
            update_post_meta($postId, self::META_SCRIPTS, wp_slash($scripts));
        }

        if ($styles !== null) {
            update_post_meta($postId, self::META_STYLES, wp_slash($styles));
        }
    }

    /**
     * Add a document's asset meta to a layout backup entry, so restore_layout
     * puts the assets back with the settings.
     *
     * @param  array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function addToBackup(int $postId, array $entry): array
    {
        $state = [];

        foreach ([self::META_SCRIPTS, self::META_STYLES] as $key) {
            $exists = metadata_exists('post', $postId, $key);
            $state[$key] = ['exists' => $exists, 'value' => $exists ? get_post_meta($postId, $key, true) : null];
        }

        $entry[self::BACKUP_KEY] = $state;

        return $entry;
    }

    /**
     * Put a document's asset meta back from a layout backup. Backups taken
     * before assets were recorded carry nothing and change nothing.
     *
     * @param array<string, mixed> $backup
     */
    public static function restoreFromBackup(int $postId, array $backup): void
    {
        $state = $backup[self::BACKUP_KEY] ?? null;

        if (! is_array($state)) {
            return;
        }

        foreach ([self::META_SCRIPTS, self::META_STYLES] as $key) {
            $item = $state[$key] ?? null;

            if (! is_array($item)) {
                continue;
            }

            if (empty($item['exists'])) {
                delete_post_meta($postId, $key);
            } else {
                update_post_meta($postId, $key, wp_slash($item['value']));
            }
        }
    }
}
