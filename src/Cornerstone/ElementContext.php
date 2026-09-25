<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

use ProExtended\Elements\ControlSurface;
use ProExtended\Elements\ElementStamper;
use ProExtended\Elements\LintContext;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Elements\ShortcodeOrigin;
use ProExtended\Settings\FontReferences;
use ProExtended\Site\Features;
use ProExtended\Support\Json;

/**
 * What this site's Cornerstone says about elements: each type's migration
 * version and the breakpoint tag new elements are stamped with.
 */
final class ElementContext
{
    /**
     * Base migration counts in Cornerstone 7.9.4, used when the element
     * registry cannot be read.
     */
    public const KNOWN_MIGRATIONS = [
        'nav-collapsed' => 1,
        'nav-layered'   => 1,
        'bar'           => 1,
        'section'       => 2,
        'layout-row'    => 2,
        'layout-grid'   => 1,
        'text'          => 1,
        'container'     => 2,
        'layout-column' => 2,
        'layout-div'    => 1,
        'layout-cell'   => 1,
        'row'           => 1,
        'column'        => 1,
    ];

    /** Pro's default breakpoints: base 4 of ranges 480, 767, 979, 1200. */
    public const DEFAULT_BREAKPOINT_TAG = '4_4';

    /** @var array<string, int>|null */
    private ?array $versions = null;

    private ?string $tag = null;

    private ?LintContext $lint = null;

    public function __construct(
        private readonly SchemaExtractor $schema,
    ) {}

    /**
     * Element type => latest migration version, for types that have
     * migrations.
     *
     * @return array<string, int>
     */
    public function migrationVersions(): array
    {
        if ($this->versions !== null) {
            return $this->versions;
        }

        $versions = [];
        $readable = false;

        try {
            foreach ($this->schema->getAllDefinitions() as $definition) {
                if (! is_array($definition) || ! isset($definition['id']) || ! array_key_exists('version', $definition)) {
                    continue;
                }

                $readable = true;
                $version = (int) $definition['version'];

                if ($version > 0) {
                    $versions[(string) $definition['id']] = $version;
                }
            }
        } catch (\Throwable) {
            $readable = false;
        }

        return $this->versions = $readable ? $versions : self::KNOWN_MIGRATIONS;
    }

    /**
     * The site's breakpoint tag ("<base>_<range count>"), as Cornerstone's
     * migrations compute it.
     */
    public function breakpointTag(): string
    {
        if ($this->tag !== null) {
            return $this->tag;
        }

        if (function_exists('cornerstone')) {
            try {
                $breakpoints = cornerstone('Breakpoints');

                if (is_object($breakpoints) && method_exists($breakpoints, 'breakpointConfig')) {
                    $config = $breakpoints->breakpointConfig();

                    if (is_array($config) && isset($config[0], $config[2]) && is_numeric($config[0]) && is_numeric($config[2])) {
                        return $this->tag = (int) $config[0] . '_' . (int) $config[2];
                    }
                }
            } catch (\Throwable) {
                // Read the theme options below.
            }
        }

        $ranges = get_option('x_breakpoint_ranges', null);
        $base = get_option('x_breakpoint_base', null);

        if (is_array($ranges) && $ranges !== [] && is_numeric($base)) {
            return $this->tag = (int) $base . '_' . count($ranges);
        }

        return $this->tag = self::DEFAULT_BREAKPOINT_TAG;
    }

    /**
     * How many values a `_bp_data` array holds on this site.
     */
    public function breakpointSlots(): int
    {
        $parts = explode('_', $this->breakpointTag());

        return (int) ($parts[1] ?? 4) + 1;
    }

    public function stamper(bool $dryRun = false): ElementStamper
    {
        return new ElementStamper($this->migrationVersions(), $this->breakpointTag(), $dryRun);
    }

    /**
     * What each of an element type's keys is, as its definition declares it:
     * "style", "style:color", "markup", "markup:html" and so on. Read from the
     * element's registered values, not its controls, so it needs no builder
     * context. Empty when the registry cannot be read.
     *
     * @return array<string, string>
     */
    public function designations(string $type): array
    {
        return $this->schema->getDesignations($type);
    }

    /**
     * What ElementLint checks element data against on this site.
     */
    public function lintContext(): LintContext
    {
        return $this->lint ??= new LintContext(
            $this->migrationVersions(),
            $this->breakpointTag(),
            $this->deprecatedTypes(),
            Features::lintSwitches(),
            $this->conditionChecker(),
            $this->looperChecker(),
            $this->cssPropertyChecker(),
            $this->styleKeyReader(),
            ...$this->twigLintContext(),
            shortcodeSource: $this->shortcodeSource(),
            fontIds: self::fontIdReader(),
        );
    }

    /**
     * The Font Manager's font ids, for the font lints: a value equal to one
     * is a reference, not a literal stack.
     *
     * Read from cornerstone_font_items when a lint asks (the way list_fonts
     * reads it), not when the context is built, so a font set_fonts adds
     * earlier in the request counts. Null when the option cannot be read.
     *
     * @return \Closure(): ?array<int, string>
     */
    private static function fontIdReader(): \Closure
    {
        return static function (): ?array {
            try {
                $items = Json::decodeStored(get_option('cornerstone_font_items', '[]'));
            } catch (\Throwable) {
                return null;
            }

            return $items === null ? null : FontReferences::ids($items);
        };
    }

    /**
     * Twig's switch and a parse-only checker for the twig-off and
     * twig-syntax lints. The environment is only built when Twig is on; if
     * Cornerstone cannot build it, twig-syntax is skipped rather than failing
     * the validation.
     *
     * @return array{twigEnabled: bool|null, twigParser: (\Closure(string): ?string)|null}
     */
    private function twigLintContext(): array
    {
        try {
            $enabled = \ProExtended\Site\Features::twigEnabled();
        } catch (\Throwable) {
            return ['twigEnabled' => null, 'twigParser' => null];
        }

        try {
            $parser = $enabled ? TwigCatalog::parser() : null;
        } catch (\Throwable) {
            $parser = null;
        }

        return ['twigEnabled' => $enabled, 'twigParser' => $parser];
    }

    /**
     * Which CSS properties an element type already has a style setting for.
     *
     * Reads the Inspector control surface once per type and remembers it, so a
     * page full of headlines costs one lookup.
     */
    /**
     * What each of an element type's style keys sets, for the token lints.
     *
     * Stored surfaces only, for the same reason as cssPropertyChecker(): this
     * runs inside the validation every write tool performs, and building a
     * surface there would enter builder context mid-save.
     */
    private function styleKeyReader(): \Closure
    {
        $cache = [];
        $schema = $this->schema;

        return static function (string $type) use (&$cache, $schema): array {
            if (array_key_exists($type, $cache)) {
                return $cache[$type];
            }

            try {
                $surface = $schema->getCachedSurface($type);

                if ($surface === null) {
                    return []; // Not remembered: a surface stored later in this request still counts.
                }

                $cache[$type] = ControlSurface::styleProperties($surface);
            } catch (\Throwable) {
                return [];
            }

            return $cache[$type];
        };
    }

    private function cssPropertyChecker(): \Closure
    {
        $cache = [];
        $schema = $this->schema;

        return static function (string $type) use (&$cache, $schema): array {
            if (array_key_exists($type, $cache)) {
                return $cache[$type];
            }

            try {
                // Stored surfaces only: this closure runs inside validation,
                // which write tools run before saving, and building a surface
                // there would fire cs_before_late_data mid-save. A type with no
                // stored surface yields no lint until get_element_schema,
                // clear_cache (elements) or `wp pe warm` stores one; from then
                // on the answer holds until Cornerstone or Pro Extended is
                // updated, so it does not change from run to run.
                $surface = $schema->getCachedSurface($type);

                if ($surface === null) {
                    return []; // Not remembered: a surface stored later in this request still counts.
                }

                $cache[$type] = ControlSurface::cssProperties($surface);
            } catch (\Throwable) {
                return [];
            }

            return $cache[$type];
        };
    }

    /**
     * Where each registered shortcode is defined, for the custom-shortcode lint.
     *
     * Reads $shortcode_tags when asked, so a tag registered after the context
     * was built still resolves, and reflects each callback once.
     *
     * @return (\Closure(string): ?array{origin: string, file: string})|null
     */
    private function shortcodeSource(): ?\Closure
    {
        if (! function_exists('shortcode_exists')) {
            return null;
        }

        $roots = self::shortcodeRoots();
        $cache = [];

        return static function (string $tag) use (&$cache, $roots): ?array {
            if (array_key_exists($tag, $cache)) {
                return $cache[$tag];
            }

            global $shortcode_tags;

            if (! is_array($shortcode_tags) || ! isset($shortcode_tags[$tag])) {
                return $cache[$tag] = null;
            }

            $file = ShortcodeOrigin::definingFile($shortcode_tags[$tag]);

            if ($file === null) {
                return $cache[$tag] = null;
            }

            return $cache[$tag] = [
                'origin' => ShortcodeOrigin::classify($file, $roots),
                'file'   => ShortcodeOrigin::label($file, $roots),
            ];
        };
    }

    /**
     * The directories ShortcodeOrigin classifies a file by, on this install.
     *
     * Cornerstone is Themeco's wherever it lives: bundled in Pro or X, where
     * the theme around it is Themeco's too, or as its own plugin. Max products
     * are Themeco plugins as well; their folders come from x_max_plugins.
     *
     * @return array<string, string[]>
     */
    private static function shortcodeRoots(): array
    {
        $paths = static function (string ...$paths): array {
            $out = [];

            foreach ($paths as $path) {
                if ($path === '') {
                    continue;
                }

                $out[] = $path;
                $real = realpath($path);

                if (is_string($real) && $real !== $path) {
                    $out[] = $real;
                }
            }

            return $out;
        };

        $abspath = defined('ABSPATH') ? (string) ABSPATH : '';
        $themeco = [];

        if (defined('CS_ROOT_PATH')) {
            $cornerstone = (string) constant('CS_ROOT_PATH');
            $themeco[] = $cornerstone;

            if (function_exists('get_template_directory')) {
                $template = (string) get_template_directory();

                // Pro and X bundle Cornerstone inside the theme.
                if ($template !== '' && str_starts_with(rtrim(str_replace('\\', '/', $cornerstone), '/') . '/', rtrim(str_replace('\\', '/', $template), '/') . '/')) {
                    $themeco[] = $template;
                }
            }
        }

        $maxFolders = [];

        foreach ((array) get_option('x_max_plugins', []) as $entry) {
            $plugin = is_array($entry) ? ($entry['plugin'] ?? null) : null;

            if (is_string($plugin) && str_contains($plugin, '/')) {
                $maxFolders[] = explode('/', $plugin, 2)[0];
            }
        }

        return [
            'abspath'         => $paths($abspath),
            'core'            => $abspath === '' ? [] : $paths($abspath . 'wp-includes', $abspath . 'wp-admin'),
            'themeco'         => $paths(...$themeco),
            'mu_plugins'      => defined('WPMU_PLUGIN_DIR') ? $paths((string) WPMU_PLUGIN_DIR) : [],
            'plugins'         => defined('WP_PLUGIN_DIR') ? $paths((string) WP_PLUGIN_DIR) : [],
            'themes'          => function_exists('get_theme_root') ? $paths((string) get_theme_root()) : [],
            'themeco_plugins' => array_values(array_unique($maxFolders)),
        ];
    }

    /**
     * Types the registry files under "deprecated".
     *
     * @return string[]
     */
    private function deprecatedTypes(): array
    {
        $types = [];

        try {
            foreach ($this->schema->getAllDefinitions() as $definition) {
                if (is_array($definition) && ($definition['group'] ?? null) === 'deprecated' && isset($definition['id'])) {
                    $types[] = (string) $definition['id'];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $types;
    }

    /**
     * Resolves a condition rule name the way RuleMatching::evaluate() does:
     * a ConditionRules method or a cs_condition_rule_<name> filter.
     *
     * @return (\Closure(string): ?bool)|null
     */
    private function conditionChecker(): ?\Closure
    {
        $class = 'Themeco\\Cornerstone\\Util\\ConditionRules';

        if (! class_exists($class)) {
            return null;
        }

        return static fn(string $rule): ?bool => is_callable([$class, $rule]) || has_filter('cs_condition_rule_' . $rule);
    }

    /**
     * @return (\Closure(string): ?bool)|null
     */
    private function looperChecker(): ?\Closure
    {
        $class = 'Themeco\\Cornerstone\\Services\\LooperProviders';

        if (! class_exists($class) || ! method_exists($class, 'isValid')) {
            return null;
        }

        return static fn(string $type): ?bool => (bool) $class::isValid($type);
    }
}
