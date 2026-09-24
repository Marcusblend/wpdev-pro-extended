<?php

declare(strict_types=1);

namespace ProExtended\Site;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Cornerstone\ElementContext;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Settings\ThemeOptionsReader;

/**
 * Takes the fingerprint PlatformBaseline compares.
 *
 * Everything here is a read. Where a Cornerstone service keeps its registry
 * private, it is read by reflection rather than guessed at, and a service that
 * is missing leaves its list empty instead of failing the snapshot — a partial
 * fingerprint still catches the changes it can see.
 */
final class PlatformSnapshot
{
    /** Element definition keys that hold the element's own code, in the order they say most about who wrote it. */
    private const ELEMENT_CALLBACKS = ['render', 'builder', 'style', 'preprocess_css_data', 'children', 'tss'];

    /** The class whose closures wrap another element's controls (Definition::update()). */
    private const DEFINITION_CLASS = 'Themeco\\Cornerstone\\Elements\\Definition';

    public function __construct(
        private readonly SchemaExtractor $schema,
        private readonly DocumentGateway $gateway,
        private readonly ElementContext $elements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function take(): array
    {
        return [
            'taken_at'               => gmdate('c'),
            'cornerstone_version'    => defined('CS_VERSION') ? (string) constant('CS_VERSION') : null,
            'pro_version'            => $this->themeVersion(),
            'wordpress_version'      => get_bloginfo('version'),
            'php_version'            => PHP_VERSION,
            'breakpoint_tag'         => $this->elements->breakpointTag(),
            'element_types'          => $this->elementTypes(),
            'migrations'             => $this->elements->migrationVersions(),
            'document_types'         => $this->documentTypes(),
            'theme_option_keys'      => $this->themeOptionKeys(),
            'dynamic_content_groups' => $this->dynamicContentGroups(),
            'looper_types'           => $this->looperTypes(),
            'permissions'            => self::permissionNames(Features::permissions()),
        ];
    }

    /**
     * The permission names Cornerstone answered for, allowed and denied alike.
     *
     * Only the names go into the fingerprint, not which way each one went:
     * the answers belong to whoever is calling, so storing them would make a
     * baseline saved by an administrator drift the moment an editor compared
     * against it. A name that appears or disappears is what the baseline is
     * for. When Cornerstone's permission service cannot be read the list is
     * empty, which the diff reports as every name removed.
     *
     * @param  array<string, mixed> $permissions Features::permissions()
     * @return string[]
     */
    public static function permissionNames(array $permissions): array
    {
        $names = [];

        foreach (['allowed', 'denied'] as $list) {
            foreach ((array) ($permissions[$list] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return string[]
     */
    public function elementTypes(): array
    {
        $types = [];

        foreach ($this->schema->getElementList() as $element) {
            if (is_string($element['type'] ?? null) && $element['type'] !== '') {
                $types[] = $element['type'];
            }
        }

        sort($types);

        return $types;
    }

    /**
     * @return string[]
     */
    public function dynamicContentGroups(): array
    {
        if (! function_exists('cornerstone')) {
            return [];
        }

        try {
            $service = cornerstone('DynamicContent');

            if (! is_object($service) || ! method_exists($service, 'get_dynamic_fields')) {
                return [];
            }

            $data = $service->get_dynamic_fields();
        } catch (\Throwable) {
            return [];
        }

        $groups = is_array($data) && is_array($data['groups'] ?? null) ? array_keys($data['groups']) : [];
        $groups = array_values(array_filter(array_map('strval', $groups)));
        sort($groups);

        return $groups;
    }

    /**
     * @return string[]
     */
    public function looperTypes(): array
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

        $types = is_array($providers) ? array_keys($providers) : [];
        $types = array_values(array_filter(array_map('strval', $types)));
        sort($types);

        return $types;
    }

    /**
     * @return string[]
     */
    public function documentTypes(): array
    {
        $types = $this->gateway->availableTypes();
        $types = array_values(array_filter(array_map('strval', is_array($types) ? $types : [])));
        sort($types);

        return $types;
    }

    /**
     * @return string[]
     */
    public function themeOptionKeys(): array
    {
        try {
            $keys = (new ThemeOptionsReader())->snapshot()['keys'];
        } catch (\Throwable) {
            return [];
        }

        sort($keys);

        return $keys;
    }

    /**
     * What the element, Dynamic Content and looper registries hold, grouped by
     * the plugin, theme or Cornerstone whose code registered each entry, next
     * to the Max products and ACF.
     *
     * Read from the live registries and attributed by reflecting each entry's
     * own callbacks; nothing is matched against a list of known extensions. A
     * registry that cannot be read is named under "unreadable".
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        if (! function_exists('get_plugins') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $roots = [
            'cornerstone' => defined('CS_ROOT_PATH') ? (string) constant('CS_ROOT_PATH') : null,
            'plugins'     => defined('WP_PLUGIN_DIR') ? (string) constant('WP_PLUGIN_DIR') : null,
            'mu_plugins'  => defined('WPMU_PLUGIN_DIR') ? (string) constant('WPMU_PLUGIN_DIR') : null,
            'template'    => get_template_directory(),
            'stylesheet'  => get_stylesheet_directory(),
        ];

        $report = Extensions::describe([
            'elements'        => $this->elementFiles($roots),
            'dynamic_content' => $this->dynamicContentFiles($roots),
            'loopers'         => $this->looperFiles($roots),
        ], $roots, function_exists('get_plugins') ? (array) get_plugins() : []);

        $report['active_plugins'] = count($this->activePlugins());
        $report['max'] = Features::max();
        $report['acf'] = Features::acf();

        return $report;
    }

    /**
     * Element type => the file its code lives in.
     *
     * @param  array<string, mixed> $roots
     * @return array<string, string|null>|null Null when the registry cannot be read.
     */
    private function elementFiles(array $roots): ?array
    {
        if (! function_exists('cornerstone')) {
            return null;
        }

        try {
            $service = cornerstone('Elements');
            $definitions = is_object($service) && method_exists($service, 'get_all_elements') ? $service->get_all_elements() : null;
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($definitions) || $definitions === []) {
            return null;
        }

        $files = [];

        foreach ($definitions as $type => $definition) {
            $def = is_object($definition) && isset($definition->def) && is_array($definition->def) ? $definition->def : [];
            $candidates = [];

            foreach (self::ELEMENT_CALLBACKS as $key) {
                $candidates[] = Extensions::callableFile($def[$key] ?? null, self::DEFINITION_CLASS);
            }

            $files[(string) $type] = Extensions::ownerFile($candidates, $roots, false);
        }

        return $files;
    }

    /**
     * Dynamic Content group => the file its values come from: the callbacks
     * on `cs_dynamic_content_<group>` and on its fields' own filters.
     *
     * @param  array<string, mixed> $roots
     * @return array<string, string|null>|null
     */
    private function dynamicContentFiles(array $roots): ?array
    {
        if (! function_exists('cornerstone')) {
            return null;
        }

        try {
            $service = cornerstone('DynamicContent');
            $data = is_object($service) && method_exists($service, 'get_dynamic_fields') ? $service->get_dynamic_fields() : null;
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || ! is_array($data['groups'] ?? null)) {
            return null;
        }

        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $files = [];

        foreach (array_keys($data['groups']) as $group) {
            $group = (string) $group;
            $candidates = $this->hookFiles('cs_dynamic_content_' . $group);

            foreach ($fields as $field) {
                if (! is_array($field) || (string) ($field['group'] ?? '') !== $group) {
                    continue;
                }

                $candidates[] = Extensions::callableFile($field['filter'] ?? null);
                array_push($candidates, ...$this->hookFiles('cs_dynamic_content_' . $group . '_' . (string) ($field['name'] ?? '')));
            }

            // A plugin that adds to one of Cornerstone's groups does not make
            // the group the plugin's.
            $files[$group] = Extensions::ownerFile($candidates, $roots, true);
        }

        return $files;
    }

    /**
     * Looper provider => the file its class or filter lives in.
     *
     * @param  array<string, mixed> $roots
     * @return array<string, string|null>|null
     */
    private function looperFiles(array $roots): ?array
    {
        $class = 'Themeco\\Cornerstone\\Services\\LooperProviders';

        if (! class_exists($class)) {
            return null;
        }

        try {
            $property = new \ReflectionProperty($class, 'providers');
            $property->setAccessible(true);
            $providers = $property->getValue();
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($providers)) {
            return null;
        }

        $files = [];

        foreach ($providers as $type => $provider) {
            $provider = is_array($provider) ? $provider : [];

            $files[(string) $type] = Extensions::ownerFile([
                Extensions::classFile($provider['class'] ?? null),
                Extensions::callableFile($provider['filter'] ?? null),
                Extensions::callableFile($provider['controls'] ?? null),
            ], $roots, false);
        }

        return $files;
    }

    /**
     * The files of every callback on a hook, by priority.
     *
     * @return array<int, string|null>
     */
    private function hookFiles(string $hook): array
    {
        global $wp_filter;

        $registered = $wp_filter[$hook] ?? null;
        $callbacks = is_object($registered) && isset($registered->callbacks) && is_array($registered->callbacks) ? $registered->callbacks : [];
        ksort($callbacks);

        $files = [];

        foreach ($callbacks as $byPriority) {
            foreach ((array) $byPriority as $callback) {
                $files[] = Extensions::callableFile(is_array($callback) ? ($callback['function'] ?? null) : null);
            }
        }

        return $files;
    }

    /**
     * @return string[]
     */
    private function activePlugins(): array
    {
        $active = get_option('active_plugins', []);
        $active = is_array($active) ? array_map('strval', $active) : [];

        if (is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', []);

            if (is_array($network)) {
                $active = array_merge($active, array_keys($network));
            }
        }

        return array_values(array_unique($active));
    }

    private function themeVersion(): ?string
    {
        $theme = wp_get_theme();

        // Pro sites almost always run a child theme, and wp_get_theme() returns
        // the active one — so reading it here reported the child's version and
        // the baseline never noticed a Themeco release. Ask the parent when
        // there is one; that is the Pro version the rest of the snapshot means.
        $parent = $theme->parent();

        if ($parent instanceof \WP_Theme) {
            $theme = $parent;
        }

        $version = $theme->get('Version');

        return is_string($version) && $version !== '' ? $version : null;
    }
}
