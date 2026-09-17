<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * Reads Pro's Theme Options the way the builder sees them: the registered
 * keys with their defaults and current values, the Theme Options panel
 * section each key sits in, and the responsive values Cornerstone keeps in
 * `cs_option_data`.
 *
 * Read only. Values that could hold secrets are never returned, and code or
 * very large values are reported by size.
 */
final class ThemeOptionsReader
{
    /** Key names that may hold credentials. */
    public const SECRET_PATTERN = '/(^|_)(key|keys|token|secret|password|passwd|license|licence|credentials?|validation|api_endpoints)($|_)/i';

    /** Keys whose values are code, reported by size only. */
    public const CODE_KEYS = ['x_custom_styles', 'x_custom_scripts', 'cs_v1_custom_css', 'cs_v1_custom_js'];

    /** Longest value returned in full (JSON bytes). */
    public const MAX_VALUE_BYTES = 4000;

    public const OTHER_SECTION = 'other';

    private const SECTIONS_CACHE = 'pe_theme_option_sections';
    private const CONTROLS_FILE = '/framework/legacy/functions/theme-options-controls.php';

    /**
     * The Theme Options service, or null when Cornerstone is not loaded.
     */
    public function service(): ?object
    {
        if (! function_exists('cornerstone')) {
            return null;
        }

        try {
            $service = cornerstone('ThemeOptions');
        } catch (\Throwable) {
            return null;
        }

        return is_object($service) && method_exists($service, 'getKeys') && method_exists($service, 'getValues') ? $service : null;
    }

    /**
     * Registered keys, values (responsive data included), defaults and
     * designations.
     *
     * @return array{keys: string[], values: array<string, mixed>, defaults: array<string, mixed>, designations: array<string, mixed>}
     *
     * @throws \RuntimeException When the Theme Options service is unavailable.
     */
    public function snapshot(): array
    {
        $service = $this->service();

        if ($service === null) {
            throw new \RuntimeException('Cornerstone\'s Theme Options service is not available on this site.');
        }

        $keys = array_values(array_map('strval', (array) $service->getKeys()));
        $values = $service->getValues();

        return [
            'keys'         => $keys,
            'values'       => is_array($values[0] ?? null) ? $values[0] : [],
            'defaults'     => is_array($values[1] ?? null) ? $values[1] : [],
            'designations' => is_array($values[2] ?? null) ? $values[2] : [],
        ];
    }

    /**
     * Section tag => {label, keys: {key => control label}}, from the Theme
     * Options panel. Cached until Pro, Cornerstone, Pro Extended or the
     * active plugins change.
     *
     * @param  string[] $registered
     * @return array<string, array{label: string, keys: array<string, string|null>}>
     */
    public function sections(array $registered): array
    {
        $version = md5((string) wp_json_encode([
            PE_VERSION,
            defined('CS_VERSION') ? CS_VERSION : '',
            wp_get_theme()->get('Version'),
            get_option('active_plugins', []),
            count($registered),
        ]));

        $cached = get_transient(self::SECTIONS_CACHE);

        if (is_array($cached) && ($cached['version'] ?? null) === $version && is_array($cached['sections'] ?? null)) {
            return $cached['sections'];
        }

        $controls = $this->controls();
        $sections = $controls === null ? [] : self::parseSections($controls, $registered);

        if ($controls !== null) {
            set_transient(self::SECTIONS_CACHE, ['version' => $version, 'sections' => $sections], DAY_IN_SECONDS);
        }

        return $sections;
    }

    /**
     * Map the panel's controls to sections. Only registered keys are kept
     * (list controls also name the keys of their items).
     *
     * @param  array<int, mixed> $controls What ThemeOptions::get_controls() returns.
     * @param  string[]          $registered
     * @return array<string, array{label: string, keys: array<string, string|null>}>
     */
    public static function parseSections(array $controls, array $registered): array
    {
        $known = array_flip($registered);
        $sections = [];

        foreach ($controls as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ((array) ($group['controls'] ?? []) as $module) {
                if (! is_array($module)) {
                    continue;
                }

                $label = is_string($module['label'] ?? null) ? $module['label'] : '';
                $tag = is_string($module['options']['tag'] ?? null) && $module['options']['tag'] !== ''
                    ? $module['options']['tag']
                    : self::slug($label);

                if ($tag === '') {
                    continue;
                }

                $keys = [];
                self::collectKeys((array) ($module['controls'] ?? []), $known, $keys);

                if (! isset($sections[$tag])) {
                    $sections[$tag] = ['label' => $label !== '' ? $label : $tag, 'keys' => []];
                }

                $sections[$tag]['keys'] += $keys;
            }
        }

        return $sections;
    }

    /**
     * Describe one option for output, redacting secrets and summarizing code
     * and large values.
     *
     * @return array<string, mixed>
     */
    public static function describe(string $key, mixed $value, mixed $default, mixed $designation): array
    {
        $row = [
            'key'         => $key,
            'designation' => is_string($designation) ? $designation : null,
            'changed'     => self::normalize($value) !== self::normalize($default),
        ];

        if (preg_match(self::SECRET_PATTERN, $key)) {
            return $row + [
                'value'    => null,
                'default'  => null,
                'redacted' => true,
                'set'      => ! self::isEmpty($value),
            ];
        }

        if (in_array($key, self::CODE_KEYS, true)) {
            return $row + [
                'value'   => null,
                'default' => null,
                'bytes'   => is_string($value) ? strlen($value) : self::jsonBytes($value),
                'note'    => 'Code is not returned here; read Global CSS with get_global_css.',
            ];
        }

        $bytes = self::jsonBytes($value);

        if ($bytes > self::MAX_VALUE_BYTES) {
            return $row + [
                'value'     => null,
                'default'   => self::small($default),
                'bytes'     => $bytes,
                'truncated' => true,
            ];
        }

        return $row + ['value' => $value, 'default' => self::small($default)];
    }

    /**
     * A value in the form ThemeOptions::update_value() stores it, for
     * comparing a stored value with its default.
     */
    public static function normalize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The panel controls, loading Pro's control map when the builder has not
     * (it only loads during builder requests) and silencing Cornerstone's
     * "outside of builder context" notices while it runs.
     *
     * @return array<int, mixed>|null
     */
    private function controls(): ?array
    {
        $service = $this->service();

        if ($service === null || ! method_exists($service, 'get_controls')) {
            return null;
        }

        if (! function_exists('x_theme_options_register')) {
            $file = get_template_directory() . self::CONTROLS_FILE;

            if (! is_readable($file)) {
                return null;
            }

            require_once $file;
        }

        set_error_handler(
            static fn(int $errno, string $message): bool => str_starts_with($message, 'cs_remember/cs_recall used outside of builder context'),
            E_USER_WARNING
        );

        try {
            $controls = $service->get_controls();
        } catch (\Throwable) {
            $controls = null;
        } finally {
            restore_error_handler();
        }

        return is_array($controls) ? $controls : null;
    }

    /**
     * @param array<int|string, mixed>     $controls
     * @param array<string, int>           $known
     * @param array<string, string|null>   $keys
     */
    private static function collectKeys(array $controls, array $known, array &$keys): void
    {
        foreach ($controls as $control) {
            if (! is_array($control)) {
                continue;
            }

            $label = is_string($control['label'] ?? null) ? $control['label'] : null;
            $names = [];

            if (isset($control['key']) && is_string($control['key'])) {
                $names[] = $control['key'];
            }

            if (isset($control['keys']) && is_array($control['keys'])) {
                foreach ($control['keys'] as $name) {
                    if (is_string($name)) {
                        $names[] = $name;
                    }
                }
            }

            foreach ($names as $name) {
                if (isset($known[$name]) && ! array_key_exists($name, $keys)) {
                    $keys[$name] = $label;
                }
            }

            if (isset($control['controls']) && is_array($control['controls'])) {
                self::collectKeys($control['controls'], $known, $keys);
            }
        }
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }

    private static function small(mixed $value): mixed
    {
        return self::jsonBytes($value) > self::MAX_VALUE_BYTES ? null : $value;
    }

    private static function jsonBytes(mixed $value): int
    {
        return strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private static function slug(string $label): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
    }
}
