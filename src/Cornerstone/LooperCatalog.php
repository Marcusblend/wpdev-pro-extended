<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * The looper providers this site offers, the element settings each one reads,
 * and the Dynamic Content fields a consumer uses to print the current item.
 *
 * Read from what the builder's Looper Provider control shows: the
 * "looper-provider" control partial (its provider choices and the controls
 * each provider type conditions on), the providers registered through
 * cs_looper_provider_register() (LooperProviders, whose settings are
 * "looper_provider_<type>_<key>"), and the "looper-consumer" partial. The
 * {{dc:looper:*}} fields come from the Dynamic Content registry.
 *
 * shape() is pure, so it is unit-tested from a fixture of the registry.
 */
final class LooperCatalog
{
    private const TYPE_KEY = 'looper_provider_type';
    private const PREFIX = 'looper_provider_';

    /** Looper provider base classes and what the current item is while they loop. */
    private const ITEM_KINDS = [
        'Cornerstone_Looper_Provider_Wp_Query' => 'post',
        'Cornerstone_Looper_Provider_Taxonomy' => 'term',
        'Cornerstone_Looper_Provider_Terms'    => 'term',
        'Cornerstone_Looper_Provider_Generic_Array' => 'array-or-post',
        'Cornerstone_Looper_Provider_Array'    => 'array',
    ];

    private const ITEM_NOTES = [
        'post'  => 'Each item is a post and becomes the current post, so {{dc:post:*}} tokens (and Twig post.*) read the looped post.',
        'term'  => 'Each item is a term; {{dc:term:*}} tokens read the looped term.',
        'array' => 'Each item is an array or value; read it with {{dc:looper:item}} or {{dc:looper:field key="<path>"}} (Twig looper.item.<path>).',
        'array-or-post' => 'Each item is whatever the source returns. A post item becomes the current post ({{dc:post:*}} reads it); anything else is read with {{dc:looper:field key="<path>"}} (Twig looper.item.<path>).',
    ];

    /**
     * @param  array<int, mixed>          $choices    Provider choices: [{value, label}].
     * @param  array<int, mixed>          $controls   The partial's controls.
     * @param  array<string, mixed>       $registered LooperProviders' registry: type => config.
     * @param  array<string, string|null> $items      Provider type => "post", "term", "array" or null.
     * @param  array<int, mixed>          $fields     {{dc:looper:*}} fields as DynamicContentCatalog lists them.
     * @param  array<int, mixed>          $consumer   The consumer partial's controls.
     * @return array<string, mixed>
     */
    public static function shape(array $choices, array $controls, array $registered, array $items, array $fields, array $consumer = []): array
    {
        $byType = [];
        $shared = [];

        self::collect($controls, [], $byType, $shared);

        $providers = [];

        foreach ($choices as $choice) {
            if (! is_array($choice) || ! is_scalar($choice['value'] ?? null) || (string) $choice['value'] === '') {
                continue;
            }

            $type = (string) $choice['value'];
            $config = is_array($registered[$type] ?? null) ? $registered[$type] : null;
            $keys = $byType[$type] ?? [];

            $row = [
                'provider' => $type,
                'label'    => is_scalar($choice['label'] ?? null) ? (string) $choice['label'] : $type,
                'source'   => $config !== null ? 'registered' : 'builtin',
            ];

            if ($config !== null) {
                $defaults = [];

                foreach ((array) ($config['values'] ?? []) as $key => $value) {
                    $full = self::PREFIX . $type . '_' . $key;
                    $keys[$full] = true;
                    $defaults[$full] = is_array($value) && array_key_exists(0, $value) ? $value[0] : $value;
                }

                if ($defaults !== []) {
                    $row['defaults'] = $defaults;
                }

                if (! empty($config['loop_keys'])) {
                    $row['loop_keys'] = true;
                }
            }

            $row['setting_keys'] = array_values(array_unique(array_merge([self::TYPE_KEY], array_keys($keys))));

            $item = $items[$type] ?? null;

            if (is_string($item) && isset(self::ITEM_NOTES[$item])) {
                $row['item'] = $item;
                $row['item_note'] = self::ITEM_NOTES[$item];
            } else {
                $row['item'] = 'unknown';
            }

            $providers[] = $row;
        }

        $looperFields = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['group'] ?? null) !== 'looper') {
                continue;
            }

            $entry = [
                'token' => (string) ($field['token'] ?? ''),
                'twig'  => 'looper.' . (string) ($field['field'] ?? ''),
                'label' => (string) ($field['label'] ?? ''),
            ];

            if (! empty($field['arguments'])) {
                $entry['arguments'] = array_values(array_column((array) $field['arguments'], 'name'));
            }

            $looperFields[] = $entry;
        }

        $consumerKeys = [];
        $unused = [];
        self::collect($consumer, [], $unused, $consumerKeys);

        return [
            'element_keys' => [
                'provider' => ['looper_provider' => 'true makes the element a provider', self::TYPE_KEY => 'one of the provider keys below'],
                'shared'   => array_keys($shared),
                'consumer' => array_keys($consumerKeys),
            ],
            'providers'     => $providers,
            'looper_fields' => $looperFields,
        ];
    }

    /**
     * Provider keys, for the platform baseline.
     *
     * @param  array<int, mixed> $choices
     * @return string[]
     */
    public static function fingerprint(array $choices): array
    {
        $ids = [];

        foreach ($choices as $choice) {
            if (is_array($choice) && is_scalar($choice['value'] ?? null) && (string) $choice['value'] !== '') {
                $ids[] = (string) $choice['value'];
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Walk controls, attributing each key to the provider types its (or its
     * parent's) conditions name. Keys no condition narrows go to $shared.
     *
     * @param array<int, mixed>                 $controls
     * @param string[]|null                     $types    Inherited provider types ([] = no restriction).
     * @param array<string, array<string, bool>> $byType
     * @param array<string, bool>               $shared
     */
    private static function collect(array $controls, ?array $types, array &$byType, array &$shared): void
    {
        foreach ($controls as $control) {
            if (! is_array($control)) {
                continue;
            }

            $own = self::conditionTypes(is_array($control['conditions'] ?? null) ? $control['conditions'] : []);
            $effective = $own ?? $types ?? [];

            if ($own !== null && $types !== null && $types !== []) {
                $effective = array_values(array_intersect($own, $types)) ?: $own;
            }

            $keys = [];

            if (is_string($control['key'] ?? null) && $control['key'] !== '') {
                $keys[] = $control['key'];
            }

            if (is_array($control['keys'] ?? null)) {
                foreach ($control['keys'] as $key) {
                    if (is_string($key) && $key !== '') {
                        $keys[] = $key;
                    }
                }
            }

            foreach ($keys as $key) {
                if ($key === self::TYPE_KEY || $key === 'looper_provider') {
                    continue;
                }

                if ($effective === []) {
                    $shared[$key] = true;
                    continue;
                }

                foreach ($effective as $type) {
                    $byType[$type][$key] = true;
                }
            }

            if (is_array($control['controls'] ?? null) && ($control['type'] ?? null) !== 'list') {
                self::collect($control['controls'], $effective, $byType, $shared);
            }
        }
    }

    /**
     * The provider types a condition list limits a control to, or null when
     * it does not mention the provider type.
     *
     * @param  array<int|string, mixed> $conditions
     * @return string[]|null
     */
    private static function conditionTypes(array $conditions): ?array
    {
        $types = null;

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (array_key_exists(self::TYPE_KEY, $condition) && is_scalar($condition[self::TYPE_KEY])) {
                $types = array_merge($types ?? [], [(string) $condition[self::TYPE_KEY]]);
                continue;
            }

            if (($condition['key'] ?? null) !== self::TYPE_KEY) {
                continue;
            }

            $op = strtoupper((string) ($condition['op'] ?? '=='));
            $value = $condition['value'] ?? null;

            if (in_array($op, ['IN'], true) && is_array($value)) {
                $types = array_merge($types ?? [], array_map('strval', array_filter($value, 'is_scalar')));
            } elseif (in_array($op, ['==', '='], true) && is_scalar($value)) {
                $types = array_merge($types ?? [], [(string) $value]);
            }
        }

        return $types === null ? null : array_values(array_unique($types));
    }

    // ─── Live registry ───────────────────────────────────────────────────────

    /**
     * The live catalog, or null when the looper registry cannot be read.
     *
     * @return array<string, mixed>|null
     */
    public function read(DynamicContentCatalog $dynamicContent): ?array
    {
        $partials = $this->partials();

        if ($partials === null) {
            return null;
        }

        [$provider, $consumer] = $partials;

        $choices = is_array($provider['options']['toggle']['choices'] ?? null) ? $provider['options']['toggle']['choices'] : [];
        $controls = is_array($provider['controls'] ?? null) ? $provider['controls'] : [];

        if ($choices === []) {
            return null;
        }

        $registered = self::registered();
        $items = [];

        foreach (self::fingerprint($choices) as $type) {
            $items[$type] = self::itemKind($type, $registered[$type] ?? null);
        }

        $fields = $dynamicContent->all()['fields'];

        return self::shape($choices, $controls, $registered, $items, $fields, is_array($consumer) ? [$consumer] : []);
    }

    /**
     * Provider keys only (for the platform baseline), or null when unreadable.
     *
     * @return string[]|null
     */
    public function providerIds(): ?array
    {
        $partials = $this->partials();

        if ($partials === null) {
            return null;
        }

        $choices = $partials[0]['options']['toggle']['choices'] ?? null;

        return is_array($choices) && $choices !== [] ? self::fingerprint($choices) : null;
    }

    /**
     * The looper-provider and looper-consumer control partials, as the
     * builder builds them.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}|null
     */
    private function partials(): ?array
    {
        $read = BuilderContext::read(static function (): ?array {
            if (! function_exists('cs_partial_controls')) {
                return null;
            }

            $provider = cs_partial_controls('looper-provider');
            $consumer = cs_partial_controls('looper-consumer');

            return is_array($provider) && $provider !== [] ? [$provider, is_array($consumer) && $consumer !== [] ? $consumer : null] : null;
        }, null);

        return is_array($read) ? $read : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function registered(): array
    {
        $class = 'Themeco\\Cornerstone\\Services\\LooperProviders';

        if (! class_exists($class)) {
            return [];
        }

        try {
            $property = new \ReflectionProperty($class, 'providers');
            $property->setAccessible(true);
            $providers = $property->getValue();
        } catch (\Throwable) {
            return [];
        }

        return is_array($providers) ? array_filter($providers, 'is_array') : [];
    }

    /**
     * What the current item is while a provider loops, from the class
     * Cornerstone would build for it. Built-in types are built through
     * Cornerstone_Looper_Provider::looper_factory(), whose constructors only
     * store their config; a registered provider is judged by its declared
     * class without building it.
     *
     * @param array<string, mixed>|null $config
     */
    private static function itemKind(string $type, ?array $config): ?string
    {
        try {
            if ($config !== null) {
                $class = is_string($config['class'] ?? null) ? $config['class'] : 'Cornerstone_Looper_Provider_Custom';

                return class_exists($class) ? self::kindOf($class) : null;
            }

            if (! class_exists('Cornerstone_Looper_Provider') || ! method_exists('Cornerstone_Looper_Provider', 'looper_factory')) {
                return null;
            }

            $instance = \Cornerstone_Looper_Provider::looper_factory($type, []);

            return is_object($instance) ? self::kindOf(get_class($instance)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function kindOf(string $class): ?string
    {
        foreach (self::ITEM_KINDS as $base => $kind) {
            if ($class === $base || is_subclass_of($class, $base)) {
                return $kind;
            }
        }

        return null;
    }
}
