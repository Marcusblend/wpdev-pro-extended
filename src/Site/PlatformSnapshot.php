<?php

declare(strict_types=1);

namespace ProExtended\Site;

use ProExtended\Cornerstone\ConditionCatalog;
use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Cornerstone\ElementContext;
use ProExtended\Cornerstone\LooperCatalog;
use ProExtended\Cornerstone\ParameterTypeCatalog;
use ProExtended\Cornerstone\TwigCatalog;
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
            ...$this->nativeFingerprints(),
            'theme_option_keys'      => $this->themeOptionKeys(),
            'dynamic_content_groups' => $this->dynamicContentGroups(),
            'looper_types'           => $this->looperTypes(),
            'permissions'            => array_keys(Features::permissions()),
        ];
    }

    /**
     * The native registries get_native_reference reads, reduced to names so a
     * Themeco release that adds or drops a Twig function or filter, a
     * condition rule, a looper provider or a parameter type shows as drift.
     * A registry that cannot be read is null (Twig is null while it is off),
     * and PlatformBaseline does not compare a null side.
     *
     * @return array<string, string[]|null>
     */
    public function nativeFingerprints(): array
    {
        $twig = null;
        $rules = null;
        $loopers = null;
        $parameters = null;

        try {
            $twig = TwigCatalog::fingerprint();
        } catch (\Throwable) {
            $twig = null;
        }

        try {
            $conditions = (new ConditionCatalog())->read();
            $all = array_merge($conditions['show_conditions']['rules'] ?? [], $conditions['assignments']['rules'] ?? []);
            $rules = $all === [] ? null : ConditionCatalog::fingerprint($all);
        } catch (\Throwable) {
            $rules = null;
        }

        try {
            $loopers = (new LooperCatalog())->providerIds();
        } catch (\Throwable) {
            $loopers = null;
        }

        try {
            $managed = (new ParameterTypeCatalog())->managed();
            $parameters = $managed === null ? null : ParameterTypeCatalog::fingerprint($managed);
        } catch (\Throwable) {
            $parameters = null;
        }

        return [
            'twig_functions'   => $twig['functions'] ?? null,
            'twig_filters'     => $twig['filters'] ?? null,
            'twig_tests'       => $twig['tests'] ?? null,
            'condition_rules'  => $rules,
            'looper_providers' => $loopers,
            'parameter_types'  => $parameters,
        ];
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
     * What the extensions on this site contribute.
     *
     * @return array<string, array<string, mixed>>
     */
    public function extensions(): array
    {
        $classes = [];
        $constants = [];

        foreach (Extensions::KNOWN as $extension) {
            foreach ($extension['classes'] as $class) {
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }

            foreach ($extension['constants'] as $constant) {
                if (defined($constant)) {
                    $value = constant($constant);
                    $constants[$constant] = is_scalar($value) ? (string) $value : '';
                }
            }
        }

        return Extensions::describe([
            'active_plugins' => $this->activePlugins(),
            'classes'        => $classes,
            'constants'      => $constants,
            'elements'       => $this->elementTypes(),
            'dc_groups'      => $this->dynamicContentGroups(),
            'loopers'        => $this->looperTypes(),
            'post_types'     => array_values(get_post_types([], 'names')),
        ]);
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
