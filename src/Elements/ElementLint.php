<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Finds element data that Cornerstone loads without complaint but renders
 * differently from what the author meant.
 *
 * Every finding is a warning with a stable code (see CODES); nothing here
 * makes a layout invalid. Paths use update_layout's dot notation: "0._modules.1"
 * for pages, "regions.top.0" for layouts, "elements.e3" for components.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class ElementLint
{
    /**
     * Stable warning codes and what each means.
     */
    public const CODES = [
        'missing-migration-marker'  => 'The element\'s type has base migrations but the element has no _m marker, so Cornerstone treats it as legacy content and fills unset values with old defaults.',
        'missing-breakpoint-base'   => 'The element has no _bp_base, so Cornerstone runs its pre-6.0 breakpoint migration (which rewrites grid, row and cell layouts).',
        'breakpoint-base-mismatch'  => 'The element\'s _bp_base is not the site\'s breakpoint tag (or not a tag at all); Cornerstone converts its responsive data when it loads.',
        'breakpoint-data-key'       => 'A _bp_data key does not match the element\'s breakpoint tag, so Cornerstone ignores it.',
        'breakpoint-data-length'    => 'A responsive value list does not have one entry per breakpoint.',
        'breakpoint-data-base-slot' => 'A responsive value list sets the base breakpoint\'s slot, which Cornerstone ignores (the top-level key is the base value).',
        'classic-element'           => 'A classic (Cornerstone 1.x) element; new content should use current elements.',
        'deprecated-element'        => 'A deprecated element; new content should use its replacement.',
        'legacy-element'            => 'The v2 row or column; new content should use layout-row and layout-column.',
        'internal-element'          => 'An internal type (root, region or undefined) inside an element tree.',
        'condition-shape'           => 'show_condition is not a list of rules with condition, value and boolean group/toggle; Cornerstone hides elements whose conditions it cannot read.',
        'condition-unknown'         => 'A condition rule this site does not know; it evaluates false and hides the element.',
        'condition-post-id'         => 'A post ID in a condition is a string; Cornerstone compares IDs strictly, so the rule never matches.',
        'looper-shape'              => 'A looper key has the wrong type, or the provider type is not registered.',
        'looper-feature-off'        => 'The looper provider needs a feature that is off on this site (CSV, External API or WooCommerce).',
        'token-syntax'              => 'A Dynamic Content token is unclosed, spans lines or has an unquoted argument, so it does not expand.',
        'token-untrusted'           => 'A Dynamic Content token reads outside input (URL, cookie, form, API or meta); its result is expanded again for tokens, shortcodes and Twig.',
        'custom-atts-type'          => 'A custom_atts value is not a JSON object string; Cornerstone skips invalid JSON silently.',
        'parameters-type'           => '_p_json must be a JSON object string and _p_data an object; invalid _p_json silently yields no parameters.',
        'table-cell-text'           => 'A table cell with child elements still has text_content, which prints after the children ("Cell Content" by default).',
        'table-cell-span'           => 'A table cell colspan or rowspan is not a whole number from 1 to 12.',
        'table-cell-tag'            => 'A table cell tag is not td or th.',
        'table-section-tag'         => 'A table section tag is not thead, tbody or tfoot (the key is the unprefixed "tag").',
        'link-without-href'         => 'A container is set to render as a link but has no href, so it renders as a div.',
        'nested-link'               => 'A container link inside another link renders as a span; browsers do not allow nested links.',
        'background-layers-off'     => 'Background layers are set but the element\'s advanced background switch is off, so they do not render.',
    ];

    /** What a missing _m changes, by type. */
    private const LEGACY_EFFECTS = [
        'bar'           => 'height 6em and a 16px base font instead of 100px and 1em',
        'nav-collapsed' => 'renders as a toggle and off-canvas menu in header top/bottom bars and footers',
        'nav-layered'   => 'renders as a toggle and off-canvas menu in header top/bottom bars and footers',
        'section'       => '45px vertical padding instead of 65px, z-index 1',
        'layout-row'    => '1rem gaps instead of 20px',
        'layout-grid'   => '1rem gaps instead of 20px',
        'text'          => 'line height 1.4 instead of inherit',
        'container'     => 'z-index 1 instead of auto',
        'row'           => 'z-index 1 instead of auto',
        'column'        => 'z-index 1 instead of auto',
    ];

    /** Types whose missing _m changes nothing visible (only "off" placeholder values). */
    private const NO_VISIBLE_MIGRATION = ['layout-column', 'layout-div', 'layout-cell'];

    /** Types whose layout Cornerstone's pre-6.0 migration rewrites. */
    private const LAYOUT_MIGRATION_TYPES = ['layout-grid', 'layout-row', 'layout-cell'];

    private const INTERNAL_TYPES = ['root', 'region', 'undefined'];

    private const LEGACY_V2_TYPES = ['row', 'column'];

    /** Element types that can render as a link, with their key prefix. */
    private const LINK_PREFIXES = [
        'section'       => 'section',
        'layout-row'    => 'layout_row',
        'layout-column' => 'layout_column',
        'layout-div'    => 'layout_div',
        'layout-grid'   => 'layout_grid',
        'layout-cell'   => 'layout_cell',
        'layout-slide'  => 'layout_slide',
    ];

    /** Looper providers Cornerstone 7.9.4 registers itself. */
    private const CORE_LOOPERS = [
        'query-recent', 'query-builder', 'query-string', 'taxonomy', 'terms', 'page-children',
        'json', 'string', 'dc', 'key-array', 'range', 'user', 'menu', 'breadcrumbs',
        'csv', 'api', 'apiglobal', 'wc-related-products', 'wc-upsell', 'wc-crosssells', 'custom',
    ];

    private const LOOPER_FEATURES = [
        'csv'                 => 'csv',
        'api'                 => 'external_api',
        'apiglobal'           => 'external_api',
        'wc-related-products' => 'woocommerce',
        'wc-upsell'           => 'woocommerce',
        'wc-crosssells'       => 'woocommerce',
    ];

    private const FEATURE_LABELS = [
        'csv'          => 'the CSV looper (cs_csv_enabled)',
        'external_api' => 'the External API (cs_api_extension_enabled)',
        'woocommerce'  => 'WooCommerce',
    ];

    /** Token groups whose values come from outside the site. */
    private const UNTRUSTED_GROUPS = ['url', 'cookie', 'api', 'form'];

    /** Token groups whose "meta" field reads stored meta. */
    private const META_GROUPS = ['post', 'term', 'user', 'author', 'archive'];

    /** Keys that never hold tokens. */
    private const NO_TOKEN_KEYS = ['_type', '_id', '_region', '_c_id', '_bp_base', '_parent'];

    public function __construct(
        private readonly LintContext $context,
    ) {}

    /**
     * Lint a list of elements and their children.
     *
     * @param  array<int|string, mixed> $elements
     * @param  string                   $base     Path of the list itself ("" for a page, "regions.top" for a region).
     * @return array<int, array{code: string, path: string, type: string, message: string}>
     */
    public function tree(array $elements, string $base = ''): array
    {
        $issues = [];
        $this->walk($elements, $base, false, $issues);

        return $issues;
    }

    /**
     * Lint a component document's flat element map.
     *
     * @param  array<string, mixed> $map
     * @return array<int, array{code: string, path: string, type: string, message: string}>
     */
    public function flat(array $map, string $base = 'elements'): array
    {
        $issues = [];

        foreach ($map as $id => $element) {
            if (! is_array($element) || in_array($element['_type'] ?? null, ['root', 'region'], true)) {
                continue;
            }

            $path = ($base === '' ? '' : $base . '.') . $id;
            $this->check($element, $path, $this->flatInsideLink($map, $element), $issues);
        }

        return $issues;
    }

    /**
     * Group issues into one readable warning per code and element type.
     *
     * @param  array<int, array{code: string, path: string, type: string, message: string}> $issues
     * @return string[]
     */
    public static function summarize(array $issues, int $examples = 3): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            $key = $issue['code'] . '|' . $issue['type'] . '|' . $issue['message'];
            $groups[$key] ??= ['issue' => $issue, 'paths' => []];
            $groups[$key]['paths'][] = $issue['path'];
        }

        $lines = [];

        foreach ($groups as $group) {
            $issue = $group['issue'];
            $count = count($group['paths']);
            $shown = array_slice($group['paths'], 0, $examples);
            $where = $count === 1
                ? 'at ' . $shown[0]
                : sprintf('%d elements, at %s%s', $count, implode(', ', $shown), $count > $examples ? ', ...' : '');

            $lines[] = sprintf('[%s] "%s" (%s): %s', $issue['code'], $issue['type'], $where, $issue['message']);
        }

        return $lines;
    }

    // ─── Walking ─────────────────────────────────────────────────────────────

    /**
     * @param array<int|string, mixed> $elements
     * @param array<int, array<string, string>> $issues
     */
    private function walk(array $elements, string $base, bool $insideLink, array &$issues): void
    {
        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                continue;
            }

            $path = ($base === '' ? '' : $base . '.') . $index;
            $this->check($element, $path, $insideLink, $issues);

            if (isset($element['_modules']) && is_array($element['_modules'])) {
                $this->walk($element['_modules'], $path . '._modules', $insideLink || $this->isLink($element), $issues);
            }
        }
    }

    /**
     * @param array<string, mixed> $map
     * @param array<string, mixed> $element
     */
    private function flatInsideLink(array $map, array $element): bool
    {
        $seen = [];
        $parent = $element['_parent'] ?? null;

        while (is_string($parent) && isset($map[$parent]) && is_array($map[$parent]) && ! isset($seen[$parent])) {
            $seen[$parent] = true;

            if ($this->isLink($map[$parent])) {
                return true;
            }

            $parent = $map[$parent]['_parent'] ?? null;
        }

        return false;
    }

    // ─── Checks ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>              $element
     * @param array<int, array<string, string>> $issues
     */
    private function check(array $element, string $path, bool $insideLink, array &$issues): void
    {
        $type = $element['_type'] ?? null;

        if (! is_string($type) || $type === '') {
            return; // The validator reports this as an error.
        }

        $add = static function (string $code, string $message) use (&$issues, $path, $type): void {
            $issues[] = ['code' => $code, 'path' => $path, 'type' => $type, 'message' => $message];
        };

        $this->checkType($type, $add);
        $this->checkMarkers($element, $type, $add);
        $this->checkConditions($element, $add);
        $this->checkLooper($element, $add);
        $this->checkTokens($element, $add);
        $this->checkAttributes($element, $add);
        $this->checkTable($element, $type, $add);
        $this->checkLink($element, $type, $insideLink, $add);
        $this->checkLayers($element, $type, $add);
    }

    private function checkType(string $type, \Closure $add): void
    {
        if (str_starts_with($type, 'classic:')) {
            $add('classic-element', 'Classic elements only keep old content rendering; use the current element instead.');
        } elseif (in_array($type, $this->context->deprecatedTypes, true)) {
            $add('deprecated-element', 'This element type is deprecated in Cornerstone; use its replacement.');
        } elseif (in_array($type, self::LEGACY_V2_TYPES, true)) {
            $add('legacy-element', 'The v2 row and column only keep old content rendering; use layout-row and layout-column.');
        } elseif (in_array($type, self::INTERNAL_TYPES, true)) {
            $add('internal-element', 'Internal types do not belong in an element tree.');
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkMarkers(array $element, string $type, \Closure $add): void
    {
        $version = (int) ($this->context->migrationVersions[$type] ?? 0);
        $marker = $element['_m'] ?? null;

        if ($version > 0 && ! in_array($type, self::NO_VISIBLE_MIGRATION, true) && (! is_array($marker) || ! array_key_exists('e', $marker))) {
            $effect = self::LEGACY_EFFECTS[$type] ?? 'unset values get older defaults';
            $add('missing-migration-marker', sprintf(
                'No _m marker, so Cornerstone treats it as legacy content (%s). New elements need "_m": {"e": %d}; the create tools add it.',
                $effect,
                $version
            ));
        }

        $siteTag = $this->context->breakpointTag;
        $tag = $siteTag;

        if (! array_key_exists('_bp_base', $element)) {
            $add('missing-breakpoint-base', in_array($type, self::LAYOUT_MIGRATION_TYPES, true)
                ? sprintf('No _bp_base, so Cornerstone\'s pre-6.0 migration overwrites its layout (a grid gets forced 4/2/1 columns). Add "_bp_base": "%s".', $siteTag)
                : sprintf('No _bp_base, so Cornerstone runs its pre-6.0 breakpoint migration on it. Add "_bp_base": "%s".', $siteTag));
            $tag = ElementStamper::dataTag($element) ?? '4_4';
        } elseif (! is_string($element['_bp_base']) || ! preg_match('/^\d+_\d+$/', $element['_bp_base'])) {
            $add('breakpoint-base-mismatch', sprintf('_bp_base is not a breakpoint tag such as "%s".', $siteTag));
        } else {
            $tag = $element['_bp_base'];

            if ($tag !== $siteTag) {
                $add('breakpoint-base-mismatch', sprintf('_bp_base is "%s" but this site uses "%s"; Cornerstone converts the responsive data when it loads.', $tag, $siteTag));
            }
        }

        foreach ($element as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, '_bp_data')) {
                continue;
            }

            if (! preg_match('/^_bp_data(\d+)_(\d+)$/', $key, $match)) {
                $add('breakpoint-data-key', sprintf('"%s" is not a responsive data key; Cornerstone reads "_bp_data%s".', $key, $tag));
                continue;
            }

            if ($match[1] . '_' . $match[2] !== $tag) {
                $add('breakpoint-data-key', sprintf('"%s" does not match the element\'s breakpoint tag, so Cornerstone ignores it; it reads "_bp_data%s".', $key, $tag));
                continue;
            }

            if (! is_array($value)) {
                continue; // The validator reports this as an error.
            }

            $slots = (int) $match[2] + 1;
            $baseSlot = (int) $match[1];

            foreach ($value as $property => $values) {
                if (! is_array($values) || ! array_is_list($values)) {
                    continue; // Sparse data is a validator error.
                }

                if (count($values) !== $slots) {
                    $add('breakpoint-data-length', sprintf('%s.%s has %d values; this breakpoint set needs %d.', $key, (string) $property, count($values), $slots));
                } elseif (array_key_exists($baseSlot, $values) && $values[$baseSlot] !== null) {
                    $add('breakpoint-data-base-slot', sprintf('%s.%s sets slot %d, the base breakpoint, which Cornerstone ignores; set the top-level "%s" instead and leave the slot null.', $key, (string) $property, $baseSlot, (string) $property));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkConditions(array $element, \Closure $add): void
    {
        if (! array_key_exists('show_condition', $element)) {
            return;
        }

        $rules = $element['show_condition'];

        if ($rules === '' || $rules === [] || $rules === null || $rules === false) {
            return; // No conditions.
        }

        if (is_string($rules)) {
            if (str_contains($rules, '{{dc:')) {
                return; // Resolved at render time.
            }

            $decoded = json_decode($rules, true);

            if (! is_array($decoded)) {
                $add('condition-shape', 'show_condition is a string that is not a JSON list of rules.');

                return;
            }

            $rules = $decoded;
        }

        if (! is_array($rules) || ! array_is_list($rules)) {
            $add('condition-shape', 'show_condition must be a list of rule objects.');

            return;
        }

        foreach ($rules as $n => $rule) {
            if (! is_array($rule) || array_is_list($rule)) {
                $add('condition-shape', sprintf('show_condition[%d] is not a rule object.', $n));
                continue;
            }

            $condition = $rule['condition'] ?? null;

            if (! is_string($condition) || trim($condition) === '') {
                $add('condition-shape', sprintf('show_condition[%d] has no "condition".', $n));
                continue;
            }

            if (! array_key_exists('value', $rule)) {
                $add('condition-shape', sprintf('show_condition[%d] (%s) has no "value" key (use "" when the rule takes none).', $n, $condition));
            }

            foreach (['group', 'toggle'] as $flag) {
                if (array_key_exists($flag, $rule) && ! is_bool($rule[$flag])) {
                    $add('condition-shape', sprintf('show_condition[%d].%s must be true or false, not %s.', $n, $flag, is_string($rule[$flag]) ? '"' . $rule[$flag] . '"' : gettype($rule[$flag])));
                }
            }

            $operator = isset($rule['operator']) && is_string($rule['operator']) && $rule['operator'] !== '' ? $rule['operator'] : null;
            $handler = explode('|', $condition)[0];
            $ruleName = str_replace([':', '-'], '_', $handler) . ($operator !== null ? '_' . str_replace('-', '_', $operator) : '');

            if ($this->context->conditionExists !== null && ($this->context->conditionExists)($ruleName) === false) {
                $add('condition-unknown', sprintf('show_condition[%d] uses "%s"%s, which this site does not know; it hides the element.', $n, $condition, $operator !== null ? ' with operator "' . $operator . '"' : ''));
            }

            if (preg_match('/:(specific-post-of-type|parent|ancestor)$/', $handler) && is_string($rule['value'] ?? null) && preg_match('/^\d+$/', $rule['value'])) {
                $add('condition-post-id', sprintf('show_condition[%d] (%s) has the post ID "%s" as a string; use the number %s.', $n, $condition, $rule['value'], $rule['value']));
            }
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkLooper(array $element, \Closure $add): void
    {
        foreach (['looper_provider', 'looper_consumer', 'looper_consumer_rewind'] as $flag) {
            if (array_key_exists($flag, $element) && ! is_bool($element[$flag])) {
                $add('looper-shape', sprintf('%s must be true or false.', $flag));
            }
        }

        if (array_key_exists('looper_consumer_repeat', $element)) {
            $repeat = $element['looper_consumer_repeat'];

            if (! (is_int($repeat) || (is_string($repeat) && preg_match('/^-?\d*$/', $repeat)))) {
                $add('looper-shape', 'looper_consumer_repeat must be a whole number as a string ("-1" for all items, "" for one).');
            }
        }

        if (($element['looper_provider'] ?? false) !== true || ! array_key_exists('looper_provider_type', $element)) {
            return;
        }

        $type = $element['looper_provider_type'];

        if (! is_string($type) || $type === '') {
            $add('looper-shape', 'looper_provider_type must name a provider.');

            return;
        }

        if (! in_array($type, self::CORE_LOOPERS, true) && $this->context->looperExists !== null && ($this->context->looperExists)($type) === false) {
            $add('looper-shape', sprintf('Looper provider "%s" is not registered on this site, so nothing is looped.', $type));
        }

        $feature = self::LOOPER_FEATURES[$type] ?? null;

        if ($feature !== null && ($this->context->features[$feature] ?? null) === false) {
            $add('looper-feature-off', sprintf('The "%s" provider needs %s, which is off on this site.', $type, self::FEATURE_LABELS[$feature]));
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkTokens(array $element, \Closure $add): void
    {
        foreach ($this->strings($element) as $key => $value) {
            if (! str_contains($value, '{{dc:')) {
                continue;
            }

            foreach (self::tokenProblems($value) as $problem) {
                $add('token-syntax', sprintf('%s: %s', $key, $problem));
            }

            foreach (self::untrustedTokens($value) as $token) {
                $add('token-untrusted', sprintf('%s: {{dc:%s}} reads outside input; only use it where that input is safe to show.', $key, $token));
            }
        }
    }

    /**
     * Syntax problems in the Dynamic Content tokens of a string.
     *
     * @return string[]
     */
    public static function tokenProblems(string $value): array
    {
        $problems = [];
        $length = strlen($value);
        $depth = 0;
        $start = 0;

        for ($i = 0; $i < $length; $i++) {
            if (substr_compare($value, '{{dc:', $i, 5) === 0) {
                if ($depth === 0) {
                    $start = $i;
                }

                $depth++;
                $i += 4;
                continue;
            }

            if ($depth > 0 && substr_compare($value, '}}', $i, 2) === 0) {
                $depth--;
                $i++;

                if ($depth === 0) {
                    $token = substr($value, $start, $i - $start + 1);

                    if (preg_match('/[\r\n]/', $token)) {
                        $problems[] = sprintf('the token "%s" spans lines; tokens must sit on one line.', self::clip($token));
                    } elseif (preg_match('/\s[\w-]+=(?![\'"]|\\\\"|&quot;)/', self::withoutNested($token))) {
                        $problems[] = sprintf('the token "%s" has an unquoted argument value; quote it (single quotes are easiest inside JSON).', self::clip($token));
                    }
                }
            }
        }

        if ($depth > 0) {
            $problems[] = sprintf('the token starting "%s" is never closed with "}}".', self::clip(substr($value, $start)));
        }

        return $problems;
    }

    /**
     * Tokens in a string that read outside input, as "group:field".
     *
     * @return string[]
     */
    public static function untrustedTokens(string $value): array
    {
        $found = [];

        if (preg_match_all('/\{\{dc:([\w.-]*):?([\w.-]*)/', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $group = strtolower($match[1]);
                $field = strtolower($match[2]);

                if (in_array($group, self::UNTRUSTED_GROUPS, true)
                    || (in_array($group, self::META_GROUPS, true) && $field === 'meta')
                    || ($group === 'query' && str_contains($field, 'search'))
                ) {
                    $found[] = $group . ($field !== '' ? ':' . $field : '');
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkAttributes(array $element, \Closure $add): void
    {
        foreach ($element as $key => $value) {
            if (! is_string($key) || ($key !== 'custom_atts' && ! str_ends_with($key, '_custom_atts'))) {
                continue;
            }

            if (is_array($value)) {
                $add('custom-atts-type', sprintf('%s is an object; store it as a JSON string, as the builder does.', $key));
            } elseif (! is_string($value)) {
                $add('custom-atts-type', sprintf('%s must be a JSON object string.', $key));
            } elseif (trim($value) !== '' && ! str_contains($value, '{{dc:')) {
                $decoded = json_decode($value, true);

                if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                    $add('custom-atts-type', sprintf('%s is not a JSON object, so Cornerstone ignores it.', $key));
                }
            }
        }

        if (array_key_exists('_p_json', $element)) {
            $json = $element['_p_json'];

            if (! is_string($json)) {
                $add('parameters-type', '_p_json must be a JSON string, not ' . gettype($json) . '.');
            } elseif (trim($json) !== '') {
                $decoded = json_decode($json, true);

                if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                    $add('parameters-type', '_p_json is not a JSON object, so the element declares no parameters.');
                }
            }
        }

        if (array_key_exists('_p_data', $element) && ! is_array($element['_p_data'])) {
            $add('parameters-type', '_p_data must be an object, not ' . gettype($element['_p_data']) . '.');
        } elseif (isset($element['_p_data']) && is_array($element['_p_data']) && $element['_p_data'] !== [] && array_is_list($element['_p_data'])) {
            $add('parameters-type', '_p_data must be an object keyed by parameter name, not a list.');
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkTable(array $element, string $type, \Closure $add): void
    {
        if ($type === 'layout-table-cell') {
            $children = isset($element['_modules']) && is_array($element['_modules']) ? count($element['_modules']) : 0;

            if ($children > 0 && ($element['text_content'] ?? 'Cell Content') !== '') {
                $add('table-cell-text', 'The cell has child elements but its text_content is not "", so that text prints after them.');
            }

            foreach (['layout_table_cell_colspan', 'layout_table_cell_rowspan'] as $key) {
                if (! array_key_exists($key, $element)) {
                    continue;
                }

                $span = $element[$key];
                $number = is_int($span) ? $span : (is_string($span) && preg_match('/^\d+$/', $span) ? (int) $span : null);

                if ($number === null || $number < 1 || $number > 12) {
                    $add('table-cell-span', sprintf('%s must be a whole number from 1 to 12.', $key));
                }
            }

            if (array_key_exists('layout_table_cell_tag', $element) && ! in_array($element['layout_table_cell_tag'], ['td', 'th'], true)) {
                $add('table-cell-tag', 'layout_table_cell_tag must be "td" or "th".');
            }
        }

        if ($type === 'layout-table-section') {
            if (array_key_exists('layout_table_section_tag', $element)) {
                $add('table-section-tag', 'Table sections use the unprefixed key "tag"; layout_table_section_tag is ignored.');
            }

            if (array_key_exists('tag', $element) && ! in_array($element['tag'], ['thead', 'tbody', 'tfoot'], true)) {
                $add('table-section-tag', 'tag must be "thead", "tbody" or "tfoot".');
            }
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkLink(array $element, string $type, bool $insideLink, \Closure $add): void
    {
        $prefix = self::LINK_PREFIXES[$type] ?? null;

        if ($prefix === null || ($element[$prefix . '_tag'] ?? null) !== 'a') {
            return;
        }

        $href = $element[$prefix . '_href'] ?? '';

        if (! is_string($href) || trim($href) === '') {
            $add('link-without-href', sprintf('%s_tag is "a" but %s_href is empty, so it renders as a div.', $prefix, $prefix));
        } elseif ($insideLink) {
            $add('nested-link', 'This link sits inside another link, so it renders as a span.');
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function checkLayers(array $element, string $type, \Closure $add): void
    {
        $active = [];

        foreach (['bg_lower', 'bg_upper'] as $layer) {
            $layerType = $element[$layer . '_type'] ?? 'none';

            if (is_string($layerType) && $layerType !== '' && $layerType !== 'none') {
                $active[] = $layer;
            }
        }

        if ($active === []) {
            return;
        }

        $switch = str_replace('-', '_', $type) . '_bg_advanced';

        if (($element[$switch] ?? false) !== true) {
            $add('background-layers-off', sprintf('%s %s set, but %s is not true, so they do not render.', implode(' and ', $active), count($active) === 1 ? 'is' : 'are', $switch));
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * An element with a container link (tag "a" and an href).
     *
     * @param array<string, mixed> $element
     */
    private function isLink(array $element): bool
    {
        $prefix = self::LINK_PREFIXES[(string) ($element['_type'] ?? '')] ?? null;

        if ($prefix === null || ($element[$prefix . '_tag'] ?? null) !== 'a') {
            return false;
        }

        $href = $element[$prefix . '_href'] ?? '';

        return is_string($href) && trim($href) !== '';
    }

    /**
     * Every string value of an element (children excluded), keyed by path.
     *
     * @param  array<string, mixed> $element
     * @return array<string, string>
     */
    private function strings(array $element): array
    {
        $out = [];

        foreach ($element as $key => $value) {
            if ($key === '_modules' || in_array($key, self::NO_TOKEN_KEYS, true)) {
                continue;
            }

            $this->collectStrings((string) $key, $value, $out);
        }

        return $out;
    }

    /**
     * @param array<string, string> $out
     */
    private function collectStrings(string $path, mixed $value, array &$out): void
    {
        if (is_string($value)) {
            $out[$path] = $value;
        } elseif (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->collectStrings($path . '.' . $key, $child, $out);
            }
        }
    }

    /**
     * A token with its nested tokens replaced by a placeholder.
     */
    private static function withoutNested(string $token): string
    {
        $inner = substr($token, 5, -2);
        $previous = null;

        while ($previous !== $inner) {
            $previous = $inner;
            $inner = (string) preg_replace('/\{\{dc:[^{}]*\}\}/', 'X', $inner);
        }

        return '{{dc:' . $inner . '}}';
    }

    private static function clip(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return strlen($text) > 60 ? substr($text, 0, 57) . '...' : $text;
    }
}
