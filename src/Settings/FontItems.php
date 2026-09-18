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
     * @param  array<string, mixed> $stored
     * @param  array<string, mixed> $update
     * @param  string[]             $errors
     * @return array{config: array<string, mixed>, changed: string[]}
     */
    public static function mergeConfig(array $stored, array $update, array &$errors): array
    {
        $unknown = array_diff(array_map('strval', array_keys($update)), self::CONFIG_KEYS);

        if ($unknown !== []) {
            $errors[] = sprintf('config has unknown keys: %s (allowed: %s).', implode(', ', $unknown), implode(', ', self::CONFIG_KEYS));
            return ['config' => $stored, 'changed' => []];
        }

        $config = $stored;

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
                    $merged = self::mergeCustomFontItems((array) ($stored['customFontItems'] ?? []), $value, $errors);

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

        return ['config' => $config, 'changed' => $changed];
    }

    /**
     * @param  array<int, mixed> $stored
     * @param  string[]          $errors
     * @return array<int, mixed>|null
     */
    private static function mergeCustomFontItems(array $stored, mixed $items, array &$errors): ?array
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

            $unknown = array_diff(array_map('strval', array_keys($item)), ['_id', 'family', 'stack', 'files']);

            if ($unknown !== []) {
                $errors[] = sprintf('%s has unknown keys: %s (allowed: _id, family, stack, files).', $label, implode(', ', $unknown));
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

            if (isset($item['stack']) && (! is_string($item['stack']) || preg_match(self::CSS_VALUE_FORBIDDEN, $item['stack']))) {
                $errors[] = $label . '.stack must be a CSS font list without ; { } < >.';
                continue;
            }

            if (isset($item['files']) && ! self::validFiles($item['files'], $label, $errors)) {
                continue;
            }

            if ($exists) {
                $position = $index[$item['_id']];
                $result[$position] = array_merge(is_array($result[$position]) ? $result[$position] : [], $item);
            } else {
                $result[] = $item;
                $index[$item['_id']] = count($result) - 1;
            }
        }

        return $result;
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

            if (! isset($file['weight']) || ! is_string($file['weight']) || ! preg_match(self::WEIGHT_PATTERN, $file['weight'])) {
                $errors[] = $fileLabel . '.weight must be a weight such as "400".';
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
