<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * Validation and completion of Cornerstone global font entries and the font
 * config, following GlobalFonts' own rules for names, stacks and weights.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class FontItems
{
    public const ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{2,63}$/';
    public const SOURCES = ['google', 'typekit', 'custom', 'system'];
    public const FONT_KEYS = ['_id', 'title', 'family', 'stack', 'source', 'weightNormal', 'weightBold', 'weightSelection', 'name', 'fallback'];
    public const CONFIG_KEYS = ['googleSubsets', 'typekitKitID', 'googleDisabled', 'googleFontsURL', 'fontDisplay', 'customFontItems', 'customFontFaceCSS'];
    public const FONT_DISPLAY = ['auto', 'block', 'swap', 'fallback', 'optional'];

    /**
     * The keys Cornerstone 7.9.4 reads from a custom font item
     * (GlobalFonts::resolveFontDefinition(), make_custom_font_css(),
     * locateCustomItem()); stored items may carry others, which a merge keeps.
     */
    public const CUSTOM_ITEM_KEYS = ['_id', 'family', 'stack', 'fallback', 'files'];

    /** CSS generic families, which an @font-face rule cannot declare. */
    private const GENERIC_FAMILIES = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong', 'inherit', 'initial', 'unset'];

    // Cornerstone's own system font data uses "400italic" as well as "400i".
    private const WEIGHT_SELECTION_PATTERN = '/^[1-9]00(?:i|italic)?$/';
    private const WEIGHT_PATTERN = '/^[1-9]00$/';
    private const CSS_VALUE_FORBIDDEN = '/[;{}<>\r\n]/';

    /**
     * Check the shape of a font entry as given (before merging).
     *
     * @param  string[] $errors
     * @return array<string, mixed>|null
     */
    public static function validateShape(mixed $font, string $label, array &$errors): ?array
    {
        if (! is_array($font) || ($font !== [] && array_is_list($font))) {
            $errors[] = sprintf('%s must be an object.', $label);
            return null;
        }

        $unknown = array_diff(array_map('strval', array_keys($font)), self::FONT_KEYS);

        if ($unknown !== []) {
            $errors[] = sprintf('%s has unknown keys: %s (allowed: %s).', $label, implode(', ', $unknown), implode(', ', self::FONT_KEYS));
            return null;
        }

        if (! isset($font['_id']) || ! is_string($font['_id']) || ! preg_match(self::ID_PATTERN, $font['_id'])) {
            $errors[] = sprintf('%s._id must be 3-64 characters: a letter, then letters, digits, "_" or "-".', $label);
            return null;
        }

        $before = count($errors);

        foreach (['title', 'family', 'name'] as $key) {
            if (array_key_exists($key, $font) && (! is_string($font[$key]) || trim($font[$key]) === '' || strlen($font[$key]) > 200 || preg_match('/[\r\n]/', $font[$key]))) {
                $errors[] = sprintf('%s.%s must be a non-empty single-line string.', $label, $key);
            }
        }

        foreach (['stack', 'fallback'] as $key) {
            if (array_key_exists($key, $font) && (! is_string($font[$key]) || trim($font[$key]) === '' || strlen($font[$key]) > 300 || preg_match(self::CSS_VALUE_FORBIDDEN, $font[$key]))) {
                $errors[] = sprintf('%s.%s must be a CSS font list without ; { } < > or line breaks.', $label, $key);
            }
        }

        if (array_key_exists('source', $font) && ! in_array($font['source'], self::SOURCES, true)) {
            $errors[] = sprintf('%s.source must be one of: %s.', $label, implode(', ', self::SOURCES));
        }

        foreach (['weightNormal', 'weightBold'] as $key) {
            if (array_key_exists($key, $font) && (! is_string($font[$key]) || ! preg_match(self::WEIGHT_PATTERN, $font[$key]))) {
                $errors[] = sprintf('%s.%s must be a weight such as "400".', $label, $key);
            }
        }

        if (array_key_exists('weightSelection', $font)) {
            $selection = $font['weightSelection'];

            if (! is_array($selection) || ! array_is_list($selection) || $selection === []) {
                $errors[] = sprintf('%s.weightSelection must be a non-empty list such as ["400", "400i", "700"].', $label);
            } else {
                foreach ($selection as $weight) {
                    if (! is_string($weight) || ! preg_match(self::WEIGHT_SELECTION_PATTERN, $weight)) {
                        $errors[] = sprintf('%s.weightSelection has an invalid weight %s (use "400", "400i" or "400italic").', $label, is_string($weight) ? '"' . $weight . '"' : gettype($weight));
                        break;
                    }
                }
            }
        }

        return count($errors) === $before ? $font : null;
    }

    /**
     * Whether an update leaves the font's family exactly as stored.
     *
     * @param array<string, mixed>      $font
     * @param array<string, mixed>|null $stored
     */
    private static function familyUnchanged(array $font, ?array $stored, bool $isNew): bool
    {
        if ($isNew || $stored === null) {
            return false;
        }

        $was = $stored['family'] ?? null;
        $now = $font['family'] ?? null;

        return is_string($was) && $was !== '' && $was === $now;
    }

    /**
     * Fill in name, stack and weights the way Cornerstone derives them, and
     * check the result.
     *
     * @param  array<string, mixed>                     $font     Effective entry (after merging).
     * @param  array<string, mixed>                     $config   Font config after this call's changes.
     * @param  array<string, array<string, mixed>>|null $catalog  Cornerstone's system/Google font list.
     * @param  string[]                                 $errors
     * @param  array<string, mixed>|null                $stored   The entry as it stands on the site, for an update.
     * @return array<string, mixed>
     */
    public static function complete(array $font, array $config, ?array $catalog, bool $isNew, array &$errors, ?array $stored = null): array
    {
        $id = (string) ($font['_id'] ?? '?');
        $source = $font['source'] ?? null;
        $family = $font['family'] ?? null;

        if ($isNew && (! isset($font['title']) || ! is_string($font['title']))) {
            $errors[] = sprintf('New font "%s" needs a title.', $id);
        }

        if (! in_array($source, self::SOURCES, true)) {
            $errors[] = sprintf('Font "%s" needs a source (%s).', $id, implode(', ', self::SOURCES));
            return $font;
        }

        if (! is_string($family) || $family === '') {
            $errors[] = sprintf('Font "%s" needs a family.', $id);
            return $font;
        }

        $defined = [];

        switch ($source) {
            case 'google':
            case 'system':
                if ($catalog === null) {
                    $errors[] = sprintf('Font "%s": Cornerstone\'s font list is not available, so %s fonts cannot be checked.', $id, $source);
                    return $font;
                }

                $name = null;

                foreach ($catalog as $key => $definition) {
                    if (is_array($definition) && ($definition['source'] ?? null) === $source && ($definition['family'] ?? null) === $family) {
                        $name = (string) $key;
                        break;
                    }
                }

                if ($name === null && self::familyUnchanged($font, $stored, $isNew)) {
                    // Cornerstone drops Google families from its font list when
                    // Google Fonts are off, which made every edit to an existing
                    // Google font fail — including a title-only change. Nothing
                    // about the family is changing here, so keep what is stored.
                    $font['name'] ??= $stored['name'] ?? null;
                    $font['stack'] ??= $stored['stack'] ?? '"' . $family . '"';

                    if ($font['name'] === null) {
                        unset($font['name']);
                    }

                    break;
                }

                if ($name === null) {
                    $disabled = $source === 'google' && ! empty($config['googleDisabled']);
                    $errors[] = sprintf(
                        'Font "%s": %s family "%s" is not in Cornerstone\'s font list%s.',
                        $id,
                        $source === 'google' ? 'Google' : 'system',
                        $family,
                        $disabled ? ' (Google Fonts are disabled on this site)' : ''
                    );
                    return $font;
                }

                if (isset($font['name']) && $font['name'] !== $name) {
                    $errors[] = sprintf('Font "%s": name must be "%s" (Cornerstone derives it from the family).', $id, $name);
                }

                $font['name'] = $name;
                $font['stack'] ??= (string) ($catalog[$name]['stack'] ?? '"' . $family . '"');
                $defined = (array) ($catalog[$name]['weights'] ?? []);
                break;

            case 'typekit':
                $kit = self::findBy((array) ($config['typekitItems'] ?? []), 'family', $family);

                if (isset($font['name']) && $font['name'] !== $family) {
                    $errors[] = sprintf('Font "%s": name must equal the family for Adobe Fonts (typekit).', $id);
                }

                $font['name'] = $family;

                if (! isset($font['stack'])) {
                    if ($kit !== null && isset($kit['stack']) && is_string($kit['stack'])) {
                        $font['stack'] = $kit['stack'];
                    } else {
                        $errors[] = sprintf('Font "%s": stack is required for an Adobe Fonts family that is not in the font config\'s typekitItems.', $id);
                    }
                }

                $defined = (array) ($kit['weights'] ?? []);
                break;

            case 'custom':
                $custom = self::findBy((array) ($config['customFontItems'] ?? []), 'family', $family);

                if ($custom === null || ! isset($custom['_id'])) {
                    $errors[] = sprintf('Font "%s": custom family "%s" is not in the font config\'s customFontItems (add it with config.customFontItems).', $id, $family);
                    return $font;
                }

                if (isset($font['name']) && $font['name'] !== $custom['_id']) {
                    $errors[] = sprintf('Font "%s": name must be "%s" (the custom font item\'s _id).', $id, (string) $custom['_id']);
                }

                $font['name'] = (string) $custom['_id'];
                $font['stack'] ??= '"' . $family . '"';

                foreach ((array) ($custom['files'] ?? []) as $file) {
                    if (is_array($file) && isset($file['weight'])) {
                        $defined[] = (string) $file['weight'];
                    }
                }
                break;
        }

        $font['weightNormal'] ??= self::closestWeight($defined, 400);
        $font['weightBold'] ??= self::closestWeight($defined, 700);
        $font['weightSelection'] ??= array_values(array_unique([$font['weightNormal'], $font['weightBold']]));

        return $font;
    }

    /**
     * Merge a partial config into the stored config.
     *
     * normalized lists what was changed on the way in (a custom item's stack
     * split into stack and fallback), and warnings what Cornerstone will do
     * with a value that is stored as given.
     *
     * @param  array<string, mixed> $stored
     * @param  array<string, mixed> $update
     * @param  string[]             $errors
     * @return array{config: array<string, mixed>, changed: string[], normalized: array<int, array<string, mixed>>, warnings: string[]}
     */
    public static function mergeConfig(array $stored, array $update, array &$errors): array
    {
        $unknown = array_diff(array_map('strval', array_keys($update)), self::CONFIG_KEYS);

        if ($unknown !== []) {
            $errors[] = sprintf('config has unknown keys: %s (allowed: %s).', implode(', ', $unknown), implode(', ', self::CONFIG_KEYS));
            return ['config' => $stored, 'changed' => [], 'normalized' => [], 'warnings' => []];
        }

        $config = $stored;
        $normalized = [];
        $warnings = [];

        foreach ($update as $key => $value) {
            switch ($key) {
                case 'googleSubsets':
                    if (! is_array($value) || ! array_is_list($value) || count($value) > 30) {
                        $errors[] = 'config.googleSubsets must be a list of subset names.';
                        break;
                    }

                    foreach ($value as $subset) {
                        if (! is_string($subset) || ! preg_match('/^[a-z0-9-]{1,40}$/', $subset)) {
                            $errors[] = 'config.googleSubsets entries must look like "latin-ext".';
                            break 2;
                        }
                    }

                    $config[$key] = $value;
                    break;

                case 'typekitKitID':
                    if (! is_string($value) || ! preg_match('/^[A-Za-z0-9]{0,32}$/', $value)) {
                        $errors[] = 'config.typekitKitID must be letters and digits only.';
                        break;
                    }

                    $config[$key] = $value;
                    break;

                case 'googleDisabled':
                    if (! is_bool($value)) {
                        $errors[] = 'config.googleDisabled must be a boolean.';
                        break;
                    }

                    $config[$key] = $value;
                    break;

                case 'googleFontsURL':
                    $isHttps = is_string($value) && $value !== ''
                        && filter_var($value, FILTER_VALIDATE_URL) !== false
                        && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';

                    if (! is_string($value) || ($value !== '' && ! $isHttps)) {
                        $errors[] = 'config.googleFontsURL must be "" or an https:// URL.';
                        break;
                    }

                    $config[$key] = $value;
                    break;

                case 'fontDisplay':
                    if (! in_array($value, self::FONT_DISPLAY, true)) {
                        $errors[] = sprintf('config.fontDisplay must be one of: %s.', implode(', ', self::FONT_DISPLAY));
                        break;
                    }

                    $config[$key] = $value;
                    break;

                case 'customFontItems':
                    $merged = self::mergeCustomFontItems((array) ($stored['customFontItems'] ?? []), $value, $errors, $normalized, $warnings);

                    if ($merged !== null) {
                        $config[$key] = $merged;
                    }
                    break;

                case 'customFontFaceCSS':
                    if (! is_string($value) || strlen($value) > 262144) {
                        $errors[] = 'config.customFontFaceCSS must be a string of at most 256 KB.';
                        break;
                    }

                    if (preg_match('/<\/style|<script|<\?/i', $value)) {
                        $errors[] = 'config.customFontFaceCSS must not contain "</style", "<script" or "<?".';
                        break;
                    }

                    $config[$key] = $value;
                    break;
            }
        }

        $changed = [];

        foreach (array_keys($update) as $key) {
            if (($stored[$key] ?? null) !== ($config[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        return ['config' => $config, 'changed' => $changed, 'normalized' => $normalized, 'warnings' => $warnings];
    }

    /**
     * @param  array<int, mixed>                 $stored
     * @param  string[]                          $errors
     * @param  array<int, array<string, mixed>>  $normalized
     * @param  string[]                          $warnings
     * @return array<int, mixed>|null
     */
    private static function mergeCustomFontItems(array $stored, mixed $items, array &$errors, array &$normalized, array &$warnings): ?array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $errors[] = 'config.customFontItems must be a list.';
            return null;
        }

        $result = array_values($stored);
        $index = [];

        foreach ($result as $position => $item) {
            if (is_array($item) && isset($item['_id']) && is_scalar($item['_id'])) {
                $index[(string) $item['_id']] ??= $position;
            }
        }

        foreach ($items as $i => $item) {
            $label = sprintf('config.customFontItems[%d]', $i);

            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[] = $label . ' must be an object.';
                continue;
            }

            $unknown = array_diff(array_map('strval', array_keys($item)), self::CUSTOM_ITEM_KEYS);

            if ($unknown !== []) {
                $errors[] = sprintf('%s has unknown keys: %s (allowed: %s).', $label, implode(', ', $unknown), implode(', ', self::CUSTOM_ITEM_KEYS));
                continue;
            }

            if (! isset($item['_id']) || ! is_string($item['_id']) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $item['_id'])) {
                $errors[] = $label . '._id is required (letters, digits, "_" or "-").';
                continue;
            }

            $exists = array_key_exists($item['_id'], $index);

            if (! $exists && (! isset($item['family']) || ! isset($item['files']))) {
                $errors[] = $label . ' is new, so it needs family and files.';
                continue;
            }

            if (isset($item['family']) && (! is_string($item['family']) || trim($item['family']) === '' || preg_match(self::CSS_VALUE_FORBIDDEN, $item['family']))) {
                $errors[] = $label . '.family must be a font family name.';
                continue;
            }

            if (array_key_exists('stack', $item) && (! is_string($item['stack']) || preg_match(self::CSS_VALUE_FORBIDDEN, $item['stack']))) {
                $errors[] = $label . '.stack must be a CSS font list without ; { } < >.';
                continue;
            }

            if (array_key_exists('fallback', $item) && (! is_string($item['fallback']) || strlen($item['fallback']) > 300 || preg_match(self::CSS_VALUE_FORBIDDEN, $item['fallback']) || ! self::quotesClosed($item['fallback']))) {
                $errors[] = $label . '.fallback must be a CSS font list without ; { } < > or line breaks, such as "sans-serif".';
                continue;
            }

            if (isset($item['files']) && ! self::validFiles($item['files'], $label, $errors)) {
                continue;
            }

            $merged = $exists && is_array($result[$index[$item['_id']]]) ? array_merge($result[$index[$item['_id']]], $item) : $item;
            $before = count($errors);
            $merged = self::normalizeCustomStack($merged, $label, $errors, $normalized);

            if (count($errors) !== $before) {
                continue;
            }

            self::warnRangeWeights($merged, $label, $warnings);

            if ($exists) {
                $result[$index[$item['_id']]] = $merged;
            } else {
                $result[] = $merged;
                $index[$item['_id']] = count($result) - 1;
            }
        }

        return $result;
    }

    /**
     * Make a custom item's stack the one quoted family its @font-face needs.
     *
     * make_custom_font_css() prints the stack (or, without one, the family)
     * as the @font-face font-family verbatim, so a list such as
     * "Brand Sans", Arial gives an invalid rule and the font never loads.
     * resolveFontDefinition() appends the item's fallback to the stack for
     * elements, so the rest of a list belongs there: the first family stays
     * as the stack and the others are appended to the fallback, without
     * repeats. An unquoted single family is quoted. A list whose first
     * family is empty, or a generic family, is refused.
     *
     * @param  array<string, mixed>              $item The item after merging.
     * @param  string[]                          $errors
     * @param  array<int, array<string, mixed>>  $normalized
     * @return array<string, mixed>
     */
    public static function normalizeCustomStack(array $item, string $label, array &$errors, array &$normalized): array
    {
        if (! array_key_exists('stack', $item) || ! is_string($item['stack'])) {
            return $item;
        }

        $id = (string) ($item['_id'] ?? '?');
        $stack = $item['stack'];

        if (! self::quotesClosed($stack)) {
            $errors[] = sprintf('%s.stack has a quote that is never closed: "%s".', $label, $stack);
            return $item;
        }

        $parts = self::splitFontList($stack);
        $first = $parts[0] ?? '';
        $quoted = preg_match('/^(?:"[^"]+"|\'[^\']+\')$/', $first) === 1;

        if ($first === '' || (! $quoted && ! preg_match('/^[A-Za-z_][\w -]*$/', $first))) {
            $errors[] = sprintf('%s.stack must begin with the font\'s own family name in quotes, which the @font-face rule declares (for example \'"%s"\'); "%s" does not.', $label, (string) ($item['family'] ?? 'Brand Sans'), $stack);
            return $item;
        }

        if (! $quoted && in_array(strtolower($first), self::GENERIC_FAMILIES, true)) {
            $errors[] = sprintf('%s.stack is the generic family "%s", which an @font-face rule cannot declare; use the font\'s own family name, quoted, and put "%s" in fallback.', $label, $first, $first);
            return $item;
        }

        $family = $quoted ? $first : '"' . $first . '"';

        if ($family !== $stack) {
            $normalized[] = [
                'item'   => $id,
                'key'    => 'stack',
                'from'   => $stack,
                'to'     => $family,
                'reason' => count($parts) > 1
                    ? 'Cornerstone prints the stack as the @font-face font-family, which takes one family; the rest moved to fallback, which Cornerstone appends for elements.'
                    : 'Quoted, so the @font-face font-family is read as one name.',
            ];
            $item['stack'] = $family;
        }

        if (count($parts) > 1) {
            $was = isset($item['fallback']) && is_string($item['fallback']) ? $item['fallback'] : null;
            $fallback = self::splitFontList((string) $was);
            $seen = array_map(static fn (string $part): string => self::familyKey($part), $fallback);

            foreach (array_slice($parts, 1) as $part) {
                if ($part !== '' && ! in_array(self::familyKey($part), $seen, true) && self::familyKey($part) !== self::familyKey($family)) {
                    $fallback[] = $part;
                    $seen[] = self::familyKey($part);
                }
            }

            $now = implode(', ', array_values(array_filter($fallback, static fn (string $part): bool => $part !== '')));

            if ($now !== ($was ?? '')) {
                $normalized[] = ['item' => $id, 'key' => 'fallback', 'from' => $was, 'to' => $now, 'reason' => 'The families after the first in stack, appended after the fallback already set.'];
                $item['fallback'] = $now;
            }
        }

        return $item;
    }

    /**
     * A CSS font list split on the commas outside quotes, each part trimmed.
     *
     * @return string[]
     */
    public static function splitFontList(string $list): array
    {
        if (trim($list) === '') {
            return [];
        }

        $parts = [];
        $current = '';
        $quote = null;

        foreach (str_split($list) as $char) {
            if ($quote !== null) {
                $current .= $char;

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === ',') {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return $parts;
    }

    /**
     * Whether every quote in a CSS font list is closed.
     */
    public static function quotesClosed(string $list): bool
    {
        $quote = null;

        foreach (str_split($list) as $char) {
            if ($quote === null && ($char === '"' || $char === "'")) {
                $quote = $char;
            } elseif ($char === $quote) {
                $quote = null;
            }
        }

        return $quote === null;
    }

    private static function familyKey(string $family): string
    {
        return strtolower(trim($family, " \t\"'"));
    }

    /**
     * Warn when a custom item's weights are all ranges.
     *
     * Variable fonts: make_custom_font_css() prints files[].weight verbatim as
     * the @font-face font-weight, so a range such as "100 900" is emitted as
     * a valid range and the browser uses the file for every weight in it. That
     * is why a range is accepted and stored untouched. But Cornerstone resolves
     * "fw-normal", "fw-bold" and numeric weights against the files' weights
     * with intval() (getClosestWeight()), so a range counts as its lower end
     * and fw-normal would render 100. Listing the same file again under each
     * weight the site references ("400", "700") gives those rules too, and the
     * resolution the numbers it needs.
     *
     * Cornerstone's @font-face has no font-stretch descriptor, and
     * config.customFontFaceCSS is stored but never printed by Cornerstone
     * 7.9.4's PHP, so a width axis is not written anywhere here; its
     * @font-face (with a font-stretch range) goes in Global CSS.
     *
     * @param array<string, mixed> $item
     * @param string[]             $warnings
     */
    private static function warnRangeWeights(array $item, string $label, array &$warnings): void
    {
        $ranges = [];
        $numbers = [];

        foreach ((array) ($item['files'] ?? []) as $file) {
            $weight = is_array($file) ? (string) ($file['weight'] ?? '') : '';

            if (str_contains($weight, ' ')) {
                $ranges[] = $weight;
            } elseif ($weight !== '') {
                $numbers[] = $weight;
            }
        }

        if ($ranges !== [] && $numbers === []) {
            $warnings[] = sprintf(
                '%s ("%s") only has range weights (%s). Cornerstone prints the range in @font-face as given, but it resolves "fw-normal", "fw-bold" and numeric weights with the lower end of a range, so fw-normal renders %s. Add the same file again under each weight the site uses, for example {"weight": "400"} and {"weight": "700"}.',
                $label,
                (string) ($item['_id'] ?? '?'),
                implode(', ', array_unique($ranges)),
                (string) (int) $ranges[0]
            );
        }
    }

    /**
     * @param string[] $errors
     */
    private static function validFiles(mixed $files, string $label, array &$errors): bool
    {
        if (! is_array($files) || ! array_is_list($files) || $files === []) {
            $errors[] = $label . '.files must be a non-empty list.';
            return false;
        }

        foreach ($files as $j => $file) {
            $fileLabel = sprintf('%s.files[%d]', $label, $j);

            if (! is_array($file) || ($file !== [] && array_is_list($file))) {
                $errors[] = $fileLabel . ' must be an object.';
                return false;
            }

            $unknown = array_diff(array_map('strval', array_keys($file)), ['weight', 'style', 'filename', 'url', 'id']);

            if ($unknown !== []) {
                $errors[] = sprintf('%s has unknown keys: %s (allowed: weight, style, filename, url, id).', $fileLabel, implode(', ', $unknown));
                return false;
            }

            if (! isset($file['weight']) || ! is_string($file['weight']) || ! self::validFileWeight($file['weight'])) {
                $errors[] = $fileLabel . '.weight must be a weight such as "400", or for a variable font a range such as "100 900".';
                return false;
            }

            if (! isset($file['style']) || ! in_array($file['style'], ['normal', 'italic', 'regular'], true)) {
                $errors[] = $fileLabel . '.style must be "normal", "italic" or "regular".';
                return false;
            }

            if (! isset($file['filename']) || ! is_string($file['filename']) || ! preg_match('/^[^\/\\\\<>"\']+\.(?:woff2|woff|ttf|otf)$/i', $file['filename'])) {
                $errors[] = $fileLabel . '.filename must be a file name ending in .woff2, .woff, .ttf or .otf.';
                return false;
            }

            if (! isset($file['url']) || ! is_string($file['url']) || ! preg_match('#^(?:https://|/)[^\s\'"()<>]+$#i', $file['url'])) {
                $errors[] = $fileLabel . '.url must be an https:// URL or a site-relative path.';
                return false;
            }

            if (isset($file['id']) && ! is_int($file['id'])) {
                $errors[] = $fileLabel . '.id must be an attachment ID.';
                return false;
            }
        }

        return true;
    }

    /**
     * A file weight: "400", or a variable font's range "100 900" (1 to 1000,
     * low to high), which Cornerstone prints in @font-face as given.
     */
    public static function validFileWeight(string $weight): bool
    {
        if (preg_match(self::WEIGHT_PATTERN, $weight)) {
            return true;
        }

        if (! preg_match('/^([1-9]\d{0,3}) ([1-9]\d{0,3})$/', $weight, $match)) {
            return false;
        }

        return (int) $match[1] < (int) $match[2] && (int) $match[2] <= 1000;
    }

    /**
     * @param  array<int, mixed> $items
     * @return array<string, mixed>|null
     */
    private static function findBy(array $items, string $key, string $value): ?array
    {
        foreach ($items as $item) {
            if (is_array($item) && ($item[$key] ?? null) === $value) {
                return $item;
            }
        }

        return null;
    }

    /**
     * GlobalFonts::getClosestWeight().
     *
     * @param array<int, mixed> $options
     */
    private static function closestWeight(array $options, int $target): string
    {
        $numeric = array_values(array_filter(array_map('intval', $options)));

        usort($numeric, static fn(int $a, int $b): int => abs($a - $target) <=> abs($b - $target));

        return (string) ($numeric[0] ?? $target);
    }
}
