<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * What an element's style template emits, for get_element_schema's "emits".
 *
 * The selector facts come from how Cornerstone's TSS runtime writes CSS
 * (ModuleReducer: every declaration group gets one generated class, ".$m";
 * Tss::getSelectorPrefix() may wrap it in :where(), which adds nothing). The
 * properties come from the element's TSS source, where it can be read: a
 * custom style template (Definition::get_style_template(), used by extension
 * elements such as Cornerstone Forms) or a "@module" block in Cornerstone's
 * TSS assets. When neither is readable the properties are reported as not
 * derived rather than guessed.
 *
 * Cached per element type until Cornerstone's version changes.
 */
final class ElementStyleFacts
{
    private const CACHE_PREFIX = 'pe_element_emits_';
    private const CACHE_TTL = 604800;

    /** Most TSS files read while looking for module sources. */
    private const MAX_FILES = 400;
    private const MAX_BYTES = 8388608;

    /** @var array<string, string>|null Module name => body, for this request. */
    private static ?array $modules = null;

    /**
     * @return array<string, mixed>
     */
    public function emits(string $type): array
    {
        $version = defined('CS_VERSION') ? (string) constant('CS_VERSION') : '';
        $key = self::CACHE_PREFIX . md5($type);
        $cached = get_transient($key);

        if (is_array($cached) && ($cached['version'] ?? null) === $version && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }

        $data = $this->build($type);

        if (! isset($data['unavailable'])) {
            set_transient($key, ['version' => $version, 'data' => $data], self::CACHE_TTL);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $type): array
    {
        $element = self::element($type);

        if ($element === null) {
            return ['unavailable' => 'Cornerstone\'s element registry is not available, so the style template could not be read.'];
        }

        $result = [
            'selector' => [
                'element'     => 'one generated class per declaration group (".m<id>-<n>"), written by Cornerstone\'s TSS runtime',
                'specificity' => '0,1,0',
                'prefix'      => self::selectorPrefix(),
                'css_key'     => 'the element\'s css key targets $el, its style id class: also 0,1,0',
                'note'        => 'A Global CSS rule with the same single-class specificity wins or loses on load order alone; setting the element\'s own key avoids the contest.',
            ],
        ];

        $modules = self::moduleNames($element);

        if ($modules !== []) {
            $result['style_modules'] = $modules;
        }

        $template = '';

        try {
            $template = method_exists($element, 'get_style_template') ? (string) $element->get_style_template() : '';
        } catch (\Throwable) {
            $template = '';
        }

        $sources = [];
        $missing = [];

        if (trim($template) !== '') {
            $sources['style template'] = $template;
        }

        foreach ($modules as $module) {
            $body = self::moduleSource($module);

            if ($body === null) {
                $missing[] = $module;
            } else {
                $sources['module ' . $module] = $body;
            }
        }

        if ($sources === []) {
            $result['properties'] = null;
            $result['note'] = 'This element\'s TSS source could not be read on this site, so the properties it always emits are not derived. Check its output with render_preview.';

            return $result;
        }

        $always = [];
        $conditional = [];
        $mixins = [];
        $nested = [];

        foreach ($sources as $body) {
            $facts = StyleTemplateFacts::analyze($body);
            $always = array_merge($always, $facts['always']);
            $conditional = array_merge($conditional, $facts['conditional']);
            $mixins = array_merge($mixins, $facts['mixins']);
            $nested = array_merge($nested, $facts['nested']);
        }

        $result['properties'] = [
            'always'      => array_values(array_unique($always)),
            'mixins'      => array_values(array_unique($mixins)),
            'conditional' => array_values(array_diff(array_unique($conditional), $always)),
            'nested'      => array_slice($nested, 0, 30),
        ];
        $result['derived_from'] = array_keys($sources);

        if ($missing !== []) {
            $result['not_derived'] = $missing;
        }

        $result['note'] = '"always" lists literal declarations outside any @if/@each/@media; a declaration whose value is empty is still left out, and mixins (listed by name) add their own properties.';

        return $result;
    }

    private static function element(string $type): ?object
    {
        if (! function_exists('cornerstone')) {
            return null;
        }

        try {
            $element = cornerstone('Elements')->get_element($type);
        } catch (\Throwable) {
            return null;
        }

        return is_object($element) ? $element : null;
    }

    /**
     * @return string[]
     */
    private static function moduleNames(object $element): array
    {
        try {
            $config = method_exists($element, 'get_tss_config') ? $element->get_tss_config() : null;
            $modules = is_object($config) && method_exists($config, 'modules') ? $config->modules() : [];
        } catch (\Throwable) {
            return [];
        }

        $names = [];

        foreach (is_array($modules) ? $modules : [] as $module) {
            $name = is_array($module) ? ($module['module'] ?? null) : $module;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private static function selectorPrefix(): string
    {
        if (! function_exists('cornerstone')) {
            return '';
        }

        try {
            $tss = cornerstone('Tss');
            $prefix = is_object($tss) && method_exists($tss, 'getSelectorPrefix') ? $tss->getSelectorPrefix() : '';
        } catch (\Throwable) {
            return '';
        }

        return is_string($prefix) ? trim($prefix) : '';
    }

    /**
     * The body of a TSS module, read (never included) from Cornerstone's TSS
     * assets.
     */
    private static function moduleSource(string $name): ?string
    {
        if (self::$modules === null) {
            self::$modules = self::scanModules();
        }

        return self::$modules[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function scanModules(): array
    {
        if (! defined('CS_ROOT_PATH')) {
            return [];
        }

        $directory = rtrim((string) constant('CS_ROOT_PATH'), '/') . '/assets/tss';

        if (! is_dir($directory)) {
            return [];
        }

        $modules = [];
        $files = 0;
        $bytes = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        } catch (\Throwable) {
            return [];
        }

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || ! in_array($file->getExtension(), ['tss', 'php'], true)) {
                continue;
            }

            if (++$files > self::MAX_FILES || ($bytes += $file->getSize()) > self::MAX_BYTES) {
                break;
            }

            $source = @file_get_contents($file->getPathname());

            if (! is_string($source) || ! str_contains($source, '@module')) {
                continue;
            }

            if (preg_match_all('/@module\s+([\w-]+)/', $source, $matches)) {
                foreach ($matches[1] as $module) {
                    $body = StyleTemplateFacts::moduleBody($source, $module);

                    if ($body !== null) {
                        $modules[$module] ??= $body;
                    }
                }
            }
        }

        return $modules;
    }
}
