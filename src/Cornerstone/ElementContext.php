<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

use ProExtended\Elements\ControlSurface;
use ProExtended\Elements\ElementStamper;
use ProExtended\Elements\LintContext;
use ProExtended\Elements\SchemaExtractor;
use ProExtended\Site\Features;

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
        );
    }

    /**
     * Which CSS properties an element type already has a style setting for.
     *
     * Reads the Inspector control surface once per type and remembers it, so a
     * page full of headlines costs one lookup.
     */
    private function cssPropertyChecker(): \Closure
    {
        $cache = [];
        $schema = $this->schema;

        return static function (string $type) use (&$cache, $schema): array {
            if (array_key_exists($type, $cache)) {
                return $cache[$type];
            }

            try {
                // Cached only: this closure runs inside validation, which write
                // tools run before saving, and building a surface there would
                // fire cs_before_late_data mid-save. An uncached type simply
                // yields no lint until a read path (get_element_schema) has
                // warmed it.
                $surface = $schema->getCachedSurface($type);

                $cache[$type] = $surface === null ? [] : ControlSurface::cssProperties($surface);
            } catch (\Throwable) {
                $cache[$type] = [];
            }

            return $cache[$type];
        };
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
