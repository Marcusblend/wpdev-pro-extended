<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * What ElementLint needs to know about the site. Built from the live site by
 * ElementContext, or by hand in tests.
 */
final class LintContext
{
    /**
     * @param array<string, int>                 $migrationVersions Element type => latest migration version.
     * @param string                             $breakpointTag     The site's breakpoint tag, for example "4_4".
     * @param string[]                           $deprecatedTypes   Types the registry marks as deprecated.
     * @param array<string, bool>                $features          Switches that decide whether some loopers work:
     *                                                              csv, external_api, woocommerce. A missing key is not checked.
     * @param (\Closure(string): ?bool)|null     $conditionExists   Whether a condition rule name resolves; null when unknown.
     * @param (\Closure(string): ?bool)|null     $looperExists      Whether a looper provider type is registered; null when unknown.
     * @param (\Closure(string): array<string, string>)|null $cssProperties CSS property => the element key that sets it.
     * @param (\Closure(string): array<string, string>)|null $styleKeys     Element key => the CSS property it sets.
     * @param bool|null                          $twigEnabled       Whether Twig is on (cs_twig_enabled); null when unknown.
     * @param (\Closure(string): ?string)|null   $twigParser        Parses (never renders) a Twig string with the site's
     *                                                              environment: Twig's message when it fails, null when
     *                                                              it parses. Null when there is no environment.
     */
    public function __construct(
        public readonly array $migrationVersions = [],
        public readonly string $breakpointTag = '4_4',
        public readonly array $deprecatedTypes = [],
        public readonly array $features = [],
        public readonly ?\Closure $conditionExists = null,
        public readonly ?\Closure $looperExists = null,
        public readonly ?\Closure $cssProperties = null,
        public readonly ?\Closure $styleKeys = null,
        public readonly ?bool $twigEnabled = null,
        public readonly ?\Closure $twigParser = null,
    ) {}
}
