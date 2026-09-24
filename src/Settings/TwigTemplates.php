<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * Twig templates kept in Theme Options (`cs_twig_templates`).
 *
 * Cornerstone's Twig integration registers the option as a list control of
 * {id, title, template} items (integration/Twig/src/ThemeOptions.php) and
 * loads each one into an ArrayLoader as "cs-template:<id>"
 * (Renderer::twigInstance), which reads `$template['id']` and
 * `$template['template']` without checking either exists — a malformed item
 * breaks every Twig render on the site. So a write is checked here first.
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class TwigTemplates
{
    public const OPTION = 'cs_twig_templates';

    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,99}$/';

    public const MAX_TEMPLATE_BYTES = 65536;

    public const MAX_TEMPLATES = 200;

    /** Keys an item may carry. */
    private const KEYS = ['id', 'title', 'template'];

    /**
     * Problems with a value for cs_twig_templates; [] when it can be stored.
     *
     * @return string[]
     */
    public static function errors(mixed $value): array
    {
        if ($value === '' || $value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
            return ['cs_twig_templates must be a list of {"id", "title", "template"} objects.'];
        }

        if (count($value) > self::MAX_TEMPLATES) {
            return [sprintf('cs_twig_templates holds at most %d templates.', self::MAX_TEMPLATES)];
        }

        $errors = [];
        $seen = [];

        foreach ($value as $i => $item) {
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[] = sprintf('cs_twig_templates[%d] must be an object with id, title and template.', $i);
                continue;
            }

            foreach (array_keys($item) as $key) {
                // "_"-prefixed keys are left for the builder's own bookkeeping.
                if (in_array($key, self::KEYS, true) || str_starts_with((string) $key, '_')) {
                    continue;
                }

                $errors[] = $key === 'content'
                    ? sprintf('cs_twig_templates[%d]: the Twig source is stored under "template", not "content".', $i)
                    : sprintf('cs_twig_templates[%d]: unknown key "%s" (items hold id, title and template).', $i, (string) $key);
            }

            $id = $item['id'] ?? null;

            if (! is_string($id) || ! preg_match(self::ID_PATTERN, $id)) {
                $errors[] = sprintf('cs_twig_templates[%d].id must be letters, digits, ".", "_" or "-" (it is included as \'cs-template:<id>\').', $i);
            } elseif (isset($seen[$id])) {
                $errors[] = sprintf('cs_twig_templates[%d].id "%s" is used twice; each include name must be unique.', $i, $id);
            } else {
                $seen[$id] = true;
            }

            if (array_key_exists('title', $item) && ! is_string($item['title'])) {
                $errors[] = sprintf('cs_twig_templates[%d].title must be a string.', $i);
            }

            $template = $item['template'] ?? null;

            if (! is_string($template)) {
                $errors[] = sprintf('cs_twig_templates[%d].template must be a string of Twig.', $i);
            } elseif (strlen($template) > self::MAX_TEMPLATE_BYTES) {
                $errors[] = sprintf('cs_twig_templates[%d].template is larger than %d bytes.', $i, self::MAX_TEMPLATE_BYTES);
            } elseif (preg_match('/<\?(?:php|=)?/i', $template)) {
                $errors[] = sprintf('cs_twig_templates[%d].template must not contain "<?".', $i);
            }
        }

        return $errors;
    }

    /**
     * A summary of the stored templates: id, title, size and the include
     * that pulls each one in.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function summarize(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';
            $template = is_string($item['template'] ?? null) ? $item['template'] : '';

            $rows[] = [
                'id'      => $id,
                'title'   => is_scalar($item['title'] ?? null) ? (string) $item['title'] : '',
                'bytes'   => strlen($template),
                'lines'   => $template === '' ? 0 : substr_count($template, "\n") + 1,
                'include' => $id === '' ? null : sprintf("{%% include 'cs-template:%s' %%}", $id),
            ];
        }

        return $rows;
    }
}
