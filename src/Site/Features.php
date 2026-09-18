<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * Cornerstone feature switches and plugin integrations that change how a
 * site renders or what its elements can do.
 *
 * Everything is read with get_option() (never cs_get_option(), which creates
 * a missing option when it reads it) and nothing here returns a secret.
 */
final class Features
{
    /**
     * Whether pages store rendered HTML (true) or [cs_content] shortcodes.
     * Cornerstone defaults to HTML, but switched sites upgraded from older
     * versions to shortcodes.
     */
    public static function buildsHtml(): bool
    {
        return (bool) get_option('cs_document_build_as_html', true);
    }

    public static function csvEnabled(): bool
    {
        return (bool) get_option('cs_csv_enabled', true);
    }

    /**
     * Whether the External API can run: the switch is on and PHP has cURL
     * (Cornerstone loads the integration only then).
     */
    public static function externalApiEnabled(): bool
    {
        return function_exists('curl_init') && (bool) get_option('cs_api_extension_enabled', false);
    }

    public static function woocommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Cornerstone permission keys reported for the calling user.
     */
    public const PERMISSION_KEYS = [
        'content.page',
        'content.post',
        'layout',
        'component',
        'global',
        'global.colors',
        'global.fonts',
        'global.theme_options',
        'global.variables',
        'global.edit_custom_css',
        'global.edit_custom_js',
        'template',
        'template.manage_library',
        'element-library',
        'element-library.classic',
    ];

    /**
     * Everything get_site_info reports about features. Never includes an
     * allowlist entry, endpoint, header, key or package URL.
     *
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        return [
            'content_storage' => [
                'mode'   => self::buildsHtml() ? 'html' : 'shortcodes',
                'stored' => self::optionExists('cs_document_build_as_html'),
            ],
            'twig'            => [
                'available' => version_compare(PHP_VERSION, '8.1', '>='),
                'enabled'   => self::twigEnabled(),
            ],
            'external_api'    => self::externalApi(),
            'csv'             => ['enabled' => self::csvEnabled()],
            'wpml'            => Languages::report(),
            'woocommerce'     => [
                'active'  => self::woocommerceActive(),
                'version' => defined('WC_VERSION') ? (string) constant('WC_VERSION') : null,
            ],
            'acf'             => [
                'active'  => class_exists('ACF'),
                'pro'     => class_exists('acf_pro'),
                'version' => defined('ACF_VERSION') ? (string) constant('ACF_VERSION') : null,
            ],
            'max'             => self::max(),
            'permissions'     => self::permissions(),
        ];
    }

    public static function twigEnabled(): bool
    {
        if (function_exists('cs_stack_get_value')) {
            try {
                return (bool) cs_stack_get_value('cs_twig_enabled');
            } catch (\Throwable) {
                // Read the option below.
            }
        }

        return (bool) get_option('cs_twig_enabled', false);
    }

    /**
     * The External API switch and a description of its allowlist (never its
     * entries or the global endpoints themselves).
     *
     * @return array<string, mixed>
     */
    public static function externalApi(): array
    {
        $raw = get_option('cs_api_extension_allowlist', '');
        $lines = is_string($raw) ? explode("\n", $raw) : [];
        $entries = array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== ''));
        $untrimmed = count(array_filter($entries, static fn(string $line): bool => $line !== trim($line)));
        $noSlash = count(array_filter($entries, static fn(string $line): bool => ! str_ends_with(trim($line), '/')));

        $endpoints = get_option('cs_api_endpoints', []);

        if (is_string($endpoints)) {
            $decoded = json_decode($endpoints, true);
            $endpoints = is_array($decoded) ? $decoded : [];
        }

        return [
            'available'                   => function_exists('curl_init'),
            'enabled'                     => self::externalApiEnabled(),
            'allowlist_empty'             => $entries === [],
            'allowlist_entries'           => count($entries),
            'entries_with_extra_spaces'   => $untrimmed,
            'entries_without_final_slash' => $noSlash,
            'global_endpoints'            => is_array($endpoints) ? count($endpoints) : 0,
        ];
    }

    /**
     * Themeco Max products this site knows about, without package URLs.
     *
     * @return array{count: int, packages: array<int, array<string, mixed>>}
     */
    public static function max(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = array_keys(get_plugins());
        $active = (array) get_option('active_plugins', []);
        $packages = MaxPackages::summarize(get_option('x_max_plugins', []), $installed, array_map('strval', $active));

        return ['count' => count($packages), 'packages' => $packages];
    }

    /**
     * The calling user's Cornerstone permissions for the keys Pro Extended's
     * tools touch, as Cornerstone's own permission check answers them.
     *
     * @return array{available: bool, user_id: int, allowed: string[], denied: string[]}
     */
    public static function permissions(): array
    {
        $result = ['available' => false, 'user_id' => get_current_user_id(), 'allowed' => [], 'denied' => []];

        if (! function_exists('cornerstone')) {
            return $result;
        }

        try {
            $service = cornerstone('Permissions');

            if (! is_object($service) || ! method_exists($service, 'userCan')) {
                return $result;
            }

            foreach (self::PERMISSION_KEYS as $key) {
                if ($service->userCan($key)) {
                    $result['allowed'][] = $key;
                } else {
                    $result['denied'][] = $key;
                }
            }

            $result['available'] = true;
        } catch (\Throwable) {
            return ['available' => false, 'user_id' => get_current_user_id(), 'allowed' => [], 'denied' => []];
        }

        return $result;
    }

    /**
     * Report lines for `wp pe doctor`.
     *
     * @param  array<string, mixed> $features
     * @return array<int, array{status: string, check: string, detail: string}>
     */
    public static function checks(array $features): array
    {
        $lines = [];
        $add = static function (string $status, string $check, string $detail) use (&$lines): void {
            $lines[] = ['status' => $status, 'check' => $check, 'detail' => $detail];
        };

        $storage = (array) ($features['content_storage'] ?? []);
        $add('info', 'Content storage', ($storage['mode'] ?? '?') === 'html' ? 'rendered HTML (cs_document_build_as_html on)' : '[cs_content] shortcodes (cs_document_build_as_html off)');

        $twig = (array) ($features['twig'] ?? []);
        $add('info', 'Twig', ! empty($twig['enabled']) ? 'on: every {{ }} string is parsed as Twig' : 'off');

        $api = (array) ($features['external_api'] ?? []);

        if (! empty($api['enabled']) && ! empty($api['allowlist_empty'])) {
            $add('warn', 'External API', 'on with an empty allowlist: requests may go to any URL');
        } elseif (! empty($api['enabled'])) {
            $detail = sprintf('on, %d allowlist entries, %d global endpoints', (int) ($api['allowlist_entries'] ?? 0), (int) ($api['global_endpoints'] ?? 0));

            if ((int) ($api['entries_with_extra_spaces'] ?? 0) > 0) {
                $add('warn', 'External API', $detail . '; some entries have spaces or line endings Cornerstone does not trim, so they never match');
            } else {
                $add('info', 'External API', $detail);
            }
        } else {
            $add('info', 'External API', 'off');
        }

        $add('info', 'CSV looper', ! empty($features['csv']['enabled']) ? 'on' : 'off');

        $integrations = [];

        foreach (['wpml' => 'WPML', 'woocommerce' => 'WooCommerce', 'acf' => 'ACF'] as $key => $label) {
            if (! empty($features[$key]['active'])) {
                $integrations[] = $label;
            }
        }

        $add('info', 'Integrations', $integrations === [] ? 'none of WPML, WooCommerce, ACF' : implode(', ', $integrations));

        $max = (array) ($features['max'] ?? []);
        $active = array_column(array_filter((array) ($max['packages'] ?? []), static fn($p): bool => ! empty($p['active'])), 'title');
        $add('info', 'Max products', sprintf('%d known, active: %s', (int) ($max['count'] ?? 0), $active === [] ? 'none' : implode(', ', $active)));

        $permissions = (array) ($features['permissions'] ?? []);

        if (empty($permissions['available'])) {
            $add('warn', 'Cornerstone permissions', 'could not be read');
        } elseif (($permissions['denied'] ?? []) !== []) {
            $add('info', 'Cornerstone permissions', 'user ' . ($permissions['user_id'] ?? '?') . ' lacks: ' . implode(', ', (array) $permissions['denied']));
        } else {
            $add('pass', 'Cornerstone permissions', 'user ' . ($permissions['user_id'] ?? '?') . ' has every key Pro Extended uses');
        }

        return $lines;
    }

    private static function optionExists(string $option): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
            $option
        )) > 0;
    }

    /**
     * The switches ElementLint checks loopers against.
     *
     * @return array{csv: bool, external_api: bool, woocommerce: bool}
     */
    public static function lintSwitches(): array
    {
        return [
            'csv'          => self::csvEnabled(),
            'external_api' => self::externalApiEnabled(),
            'woocommerce'  => self::woocommerceActive(),
        ];
    }
}
