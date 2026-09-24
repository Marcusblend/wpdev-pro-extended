<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

use ProExtended\Settings\TwigTemplates;
use ProExtended\Site\Features;

/**
 * What Twig can do on this site.
 *
 * Cornerstone builds one Twig Environment per request (Renderer::twigInstance,
 * reached through cs_twig_environment()): its own extension, the state
 * extension, the filters and functions handed out by the `cs_twig_filters` and
 * `cs_twig_functions` filters, then the `cs_twig_boot` action, where the
 * optional extensions (HTML Extra, String Extra, Debug, Advanced) add
 * themselves. The names are read from that Environment, so what is listed is
 * exactly what a template on this site can call. When Twig is off Cornerstone
 * never loads the integration, and the environment is reported as unavailable.
 *
 * Every read here is a read; nothing is rendered.
 */
final class TwigCatalog
{
    public const ENABLED = 'cs_twig_enabled';

    /** The sub-toggle that lets Twig call any PHP function; never written by Pro Extended. */
    public const ADVANCED = 'cs_twig_extension_advanced';

    /** Parameter groups whose Twig access nests (DCToTwigGrabber::$paramGroups). */
    private const PARAM_GROUPS = ['param', 'p', 'g', 'global'];

    /**
     * The Twig switch and every sub-toggle, from the theme option keys
     * Cornerstone registers.
     *
     * @param  string[]             $keys     Registered theme option keys.
     * @param  array<string, mixed> $values   Current values.
     * @return array<string, array<string, mixed>>
     */
    public static function toggles(array $keys, array $values): array
    {
        $toggles = [];

        foreach ($keys as $key) {
            if (! is_string($key) || ! str_starts_with($key, 'cs_twig_') || $key === TwigTemplates::OPTION) {
                continue;
            }

            $toggles[$key] = [
                'on'       => self::truthy($values[$key] ?? null),
                'writable' => $key !== self::ADVANCED,
            ];

            if ($key === self::ADVANCED) {
                $toggles[$key]['note'] = 'Lets Twig call any PHP function and fire WordPress actions. Pro Extended never switches it on; leave it off.';
            }
        }

        ksort($toggles);

        return $toggles;
    }

    /**
     * How Dynamic Content groups read from Twig.
     *
     * Cornerstone exposes every group (and each alias) as a Twig global backed
     * by DCToTwigGrabber, so {{dc:post:title}} is {{ post.title }}, arguments
     * are passed as one object, and parameter groups nest.
     *
     * @param  array<int, array<string, mixed>> $groups DynamicContentCatalog::all()['groups']
     * @param  array<int, array<string, mixed>> $fields DynamicContentCatalog::all()['fields']
     * @return array<string, mixed>
     */
    public static function variables(array $groups, array $fields): array
    {
        $firstField = [];
        $withArgument = [];

        foreach ($fields as $field) {
            $group = (string) ($field['group'] ?? '');

            if ($group === '') {
                continue;
            }

            $firstField[$group] ??= (string) ($field['field'] ?? '');

            if (! isset($withArgument[$group]) && ! empty($field['arguments'][0]['name'])) {
                $withArgument[$group] = [(string) $field['field'], (string) $field['arguments'][0]['name']];
            }
        }

        $rows = [];

        foreach ($groups as $group) {
            $name = (string) ($group['group'] ?? '');

            if ($name === '') {
                continue;
            }

            $row = [
                'group'   => $name,
                'twig'    => $name,
                'aliases' => array_values((array) ($group['aliases'] ?? [])),
            ];

            if (($firstField[$name] ?? '') !== '') {
                $row['example'] = sprintf('{{dc:%1$s:%2$s}} is {{ %1$s.%2$s }}', $name, $firstField[$name]);
            }

            if (isset($withArgument[$name])) {
                [$field, $argument] = $withArgument[$name];
                $row['with_arguments'] = sprintf('{{dc:%1$s:%2$s %3$s="x"}} is {{ %1$s.%2$s({%3$s: "x"}) }}', $name, $field, $argument);
            }

            if (in_array($name, self::PARAM_GROUPS, true)) {
                $row['nests'] = true;
            }

            $rows[] = $row;
        }

        return [
            'groups' => $rows,
            'rules'  => [
                'Every Dynamic Content group is a Twig variable of the same name (and one per alias): {{ post.title }}, {{ looper.item }}, {{ param.heading }}.',
                'Arguments go in one object: {{ post.meta({key: "price"}) }}, {{ looper.field({key: "name"}) }}.',
                'Parameter groups (param, p) nest: {{ param.card.title }}; a group[] parameter is a list to loop with {% for item in param.items %}.',
                'Global Parameters: {{ dc.g.<path> }} (the dc variable reaches any group by name, including the g filter).',
                'Dynamic Content tokens expand before Twig runs, so a {{dc:…}} inside Twig is already text: quote it where a string is expected ("{{dc:post:title}}").',
            ],
        ];
    }

    /**
     * Notes that save a build from the usual Twig mistakes.
     *
     * @return string[]
     */
    public static function notes(string $timezone, bool $autoescape): array
    {
        $tz = $timezone !== '' ? $timezone : '<site timezone>';

        $notes = [
            sprintf('Pass the site timezone to dates: {{ "now"|date("Y", "%1$s") }} or {{ date("now", "%1$s") }}. Twig uses PHP\'s default timezone, which WordPress sets to UTC, so without it dates switch at UTC midnight.', $tz),
            'PHP relative dates work: date("last monday of may", "' . $tz . '"), date("+7 days"). Compare dates with date(): {% if date("now", "' . $tz . '") < date("2026-11-01", "' . $tz . '") %}.',
            'Shared logic lives in Twig templates (cs_twig_templates, written with update_theme_options) and is pulled in with {% include \'cs-template:<id>\' %}.',
            'A Twig error on the live site prints the raw template text; the builder preview shows the message. Check every Twig string with render_preview and validate_layout (twig-syntax).',
            'The Advanced extension (cs_twig_extension_advanced) lets Twig call any PHP function. It stays off, and update_theme_options refuses it.',
        ];

        if ($autoescape) {
            $notes[] = 'Autoescape is on (cs_twig_autoescape): output that should stay HTML needs |raw.';
        }

        return $notes;
    }

    /**
     * Names registered on a Twig Environment.
     *
     * @return array{functions: string[], filters: string[], tests: string[], tags: string[]}
     */
    public static function environmentNames(object $environment): array
    {
        $names = static function (mixed $list): array {
            $out = is_array($list) ? array_values(array_map('strval', array_keys($list))) : [];
            sort($out);

            return $out;
        };

        return [
            'functions' => method_exists($environment, 'getFunctions') ? $names($environment->getFunctions()) : [],
            'filters'   => method_exists($environment, 'getFilters') ? $names($environment->getFilters()) : [],
            'tests'     => method_exists($environment, 'getTests') ? $names($environment->getTests()) : [],
            'tags'      => method_exists($environment, 'getTokenParsers') ? self::tags($environment->getTokenParsers()) : [],
        ];
    }

    /**
     * @return string[]
     */
    private static function tags(mixed $parsers): array
    {
        $tags = [];

        foreach (is_array($parsers) ? $parsers : [] as $key => $parser) {
            if (is_object($parser) && method_exists($parser, 'getTag')) {
                $tags[] = (string) $parser->getTag();
            } elseif (is_string($key)) {
                $tags[] = $key;
            }
        }

        $tags = array_values(array_unique($tags));
        sort($tags);

        return $tags;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    // ─── Live reads ──────────────────────────────────────────────────────────

    /**
     * The site's Twig Environment, or the reason there is none.
     *
     * @return array{0: object|null, 1: string|null}
     */
    public static function environment(): array
    {
        if (! Features::twigEnabled()) {
            return [null, 'Twig is off (cs_twig_enabled), so Cornerstone never builds its environment. Switch it on with update_theme_options {"cs_twig_enabled": true}.'];
        }

        if (! function_exists('cs_twig_environment')) {
            return [null, 'Twig is switched on, but Cornerstone\'s Twig integration is not loaded (it needs PHP 8.1 or later).'];
        }

        try {
            $environment = cs_twig_environment();
        } catch (\Throwable $e) {
            return [null, 'Cornerstone could not build its Twig environment: ' . $e->getMessage()];
        }

        return is_object($environment) ? [$environment, null] : [null, 'cs_twig_environment() did not return an environment.'];
    }

    /**
     * Parse (never render) a string with the site's environment. Returns
     * Twig's message when it does not parse, null when it does.
     *
     * @return (\Closure(string): ?string)|null Null when there is no environment.
     */
    public static function parser(): ?\Closure
    {
        [$environment] = self::environment();

        return $environment === null ? null : self::parserFor($environment);
    }

    /**
     * A parse-only checker bound to one Twig Environment.
     *
     * @return (\Closure(string): ?string)|null Null when the object cannot tokenize and parse.
     */
    public static function parserFor(object $environment): ?\Closure
    {
        if (! method_exists($environment, 'tokenize') || ! method_exists($environment, 'parse') || ! class_exists('Twig\\Source')) {
            return null;
        }

        return static function (string $template) use ($environment): ?string {
            try {
                $environment->parse($environment->tokenize(new \Twig\Source($template, 'element')));

                return null;
            } catch (\Throwable $e) {
                // Only Twig's own errors say something about the string; any
                // other failure (an extension that would not initialise) is
                // the environment's problem, and the lint stays quiet.
                if (! is_a($e, 'Twig\\Error\\Error')) {
                    return null;
                }

                $message = method_exists($e, 'getRawMessage') ? (string) $e->getRawMessage() : $e->getMessage();
                $line = method_exists($e, 'getTemplateLine') ? (int) $e->getTemplateLine() : 0;

                return $line > 0 ? sprintf('%s (line %d)', $message, $line) : $message;
            }
        };
    }

    /**
     * The whole twig section.
     *
     * @return array<string, mixed>
     */
    public function read(DynamicContentCatalog $dynamicContent, ?string $template = null): array
    {
        $options = new \ProExtended\Settings\ThemeOptionsReader();

        try {
            $snapshot = $options->snapshot();
            $toggles = self::toggles($snapshot['keys'], $snapshot['values']);
            $templates = $snapshot['values'][TwigTemplates::OPTION] ?? [];
        } catch (\Throwable $e) {
            $toggles = null;
            $templates = function_exists('get_option') ? get_option(TwigTemplates::OPTION, []) : [];
            $togglesError = $e->getMessage();
        }

        $result = [
            'enabled' => Features::twigEnabled(),
            'toggles' => $toggles ?? ['unavailable' => $togglesError ?? 'Theme Options could not be read.'],
            'write_with' => 'update_theme_options, e.g. {"cs_twig_enabled": true, "cs_twig_extension_wordpress": true}',
        ];

        [$environment, $reason] = self::environment();

        if ($environment === null) {
            $result['environment'] = ['unavailable' => $reason];
        } else {
            $names = BuilderContext::read(static fn (): array => self::environmentNames($environment), null);
            $globals = BuilderContext::read(static function () use ($environment): ?array {
                $list = method_exists($environment, 'getGlobals') ? $environment->getGlobals() : null;

                if (! is_array($list)) {
                    return null;
                }

                $keys = array_values(array_map('strval', array_keys($list)));
                sort($keys);

                return $keys;
            }, null);

            $result['environment'] = is_array($names)
                ? $names + ['globals' => $globals ?? ['unavailable' => 'The environment\'s globals could not be read.']]
                : ['unavailable' => 'The environment could not be read.'];

            if (defined('Twig\\Environment::VERSION')) {
                $result['environment']['twig_version'] = (string) constant('Twig\\Environment::VERSION');
            }
        }

        $catalog = $dynamicContent->all();
        $result['variables'] = $catalog['groups'] === []
            ? ['unavailable' => 'This site returned no Dynamic Content registry.']
            : self::variables($catalog['groups'], $catalog['fields']);

        $result['templates'] = TwigTemplates::summarize($templates);

        if ($template !== null) {
            $result['template'] = self::template($templates, $template);
        }

        $timezone = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
        $result['site_timezone'] = $timezone;
        $result['notes'] = self::notes($timezone, is_array($toggles) && ! empty($toggles['cs_twig_autoescape']['on']));

        return $result;
    }

    /**
     * One stored template, in full.
     *
     * @return array<string, mixed>
     */
    public static function template(mixed $templates, string $id): array
    {
        foreach (is_array($templates) ? $templates : [] as $item) {
            if (is_array($item) && (string) ($item['id'] ?? '') === $id) {
                return [
                    'id'       => $id,
                    'title'    => (string) ($item['title'] ?? ''),
                    'template' => (string) ($item['template'] ?? ''),
                ];
            }
        }

        throw new \InvalidArgumentException(sprintf('No Twig template with id "%s". The twig section lists them under templates.', $id));
    }

    /**
     * Function, filter and test names for the platform baseline, or null
     * when there is no environment to read.
     *
     * @return array{functions: string[], filters: string[], tests: string[]}|null
     */
    public static function fingerprint(): ?array
    {
        [$environment] = self::environment();

        if ($environment === null) {
            return null;
        }

        $names = BuilderContext::read(static fn (): array => self::environmentNames($environment), null);

        return is_array($names) ? ['functions' => $names['functions'], 'filters' => $names['filters'], 'tests' => $names['tests']] : null;
    }
}
