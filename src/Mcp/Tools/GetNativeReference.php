<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\NativeReference;
use ProExtended\Cornerstone\TwigCatalog;
use ProExtended\Support\Args;

final class GetNativeReference implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['section', 'refresh', 'group', 'search', 'groups_only', 'template', 'scope'];

    public function __construct(private readonly NativeReference $reference) {}

    public function name(): string
    {
        return 'get_native_reference';
    }

    public function description(): string
    {
        return 'Look up what Cornerstone on this site offers natively, read from its live registries (never a hand-kept list; a registry that cannot be read is reported as unavailable). section: "dynamic_content" — every {{dc:group:field}} token and its arguments (narrow with group, search, groups_only); "twig" — whether Twig is on, each sub-toggle, the functions, filters, tests, tags and globals the site\'s Twig environment registers, how Dynamic Content groups read as Twig variables (post.title, looper.item, param.x), the stored Twig templates (pass template: "<id>" for one in full) and notes such as passing the site timezone to date(); "conditions" — the rule keys, operators and value choices for element show_condition and for document assignments, with the stored rule shape (scope narrows to one; search filters rules); "loopers" — each looper provider, the looper_provider_* keys it reads and what the current item is, plus the {{dc:looper:*}} fields; "parameter_types" — the types a component\'s _p_json can use and what each outputs; "regions" — the region names each document type renders. Cached until Cornerstone or the site\'s plugins change; pass refresh: true after adding fields, post types or Twig templates.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['section'],
            'properties' => [
                'section' => [
                    'type'        => 'string',
                    'enum'        => NativeReference::SECTIONS,
                    'description' => 'Which reference to return.',
                ],
                'refresh' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Read the registry again instead of the cache. Default: false.',
                ],
                'group' => [
                    'type'        => 'string',
                    'description' => 'dynamic_content only. Only this group\'s fields.',
                ],
                'search' => [
                    'type'        => 'string',
                    'description' => 'dynamic_content, conditions or loopers. Match a name, label, key or token.',
                ],
                'groups_only' => [
                    'type'        => 'boolean',
                    'description' => 'dynamic_content only. Return the groups without their fields.',
                ],
                'template' => [
                    'type'        => 'string',
                    'description' => 'twig only. The id of a stored Twig template to return in full.',
                ],
                'scope' => [
                    'type'        => 'string',
                    'enum'        => ['show_conditions', 'assignments'],
                    'description' => 'conditions only. Return just element show conditions or just document assignments.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $section = Args::enum($arguments, 'section', NativeReference::SECTIONS, null);

        if ($section === null) {
            throw new \InvalidArgumentException(sprintf('"section" is required: one of %s.', implode(', ', NativeReference::SECTIONS)));
        }

        $allowed = match ($section) {
            'dynamic_content' => ['group', 'search', 'groups_only'],
            'twig'            => ['template'],
            'conditions'      => ['scope', 'search'],
            'loopers'         => ['search'],
            default           => [],
        };

        foreach (['group', 'search', 'groups_only', 'template', 'scope'] as $option) {
            if (array_key_exists($option, $arguments) && ! in_array($option, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('"%s" does not apply to the %s section.', $option, $section));
            }
        }

        $refresh = Args::bool($arguments, 'refresh', false);
        $read = $this->reference->section($section, $refresh);
        $data = $read['data'];
        $search = Args::string($arguments, 'search', null, 200);

        $result = ['section' => $section];

        if (isset($data['unavailable'])) {
            return $result + $data + ['cache' => $read['cache']];
        }

        switch ($section) {
            case 'dynamic_content':
                $result += ListDynamicContent::present($data, $arguments);
                break;

            case 'twig':
                $template = Args::string($arguments, 'template', null, 100);
                $result += $data;

                if ($template !== null && $template !== '') {
                    $result['template'] = TwigCatalog::template($this->storedTemplates(), $template);
                }
                break;

            case 'conditions':
                $scope = Args::enum($arguments, 'scope', ['show_conditions', 'assignments'], null);

                foreach (['show_conditions', 'assignments'] as $part) {
                    if ($scope !== null && $scope !== $part) {
                        continue;
                    }

                    $result[$part] = self::filterRules($data[$part] ?? [], $search);
                }
                break;

            case 'loopers':
                $result += $data;

                if ($search !== null && $search !== '') {
                    $needle = strtolower($search);
                    $result['providers'] = array_values(array_filter(
                        (array) ($data['providers'] ?? []),
                        static fn (array $provider): bool => str_contains(strtolower($provider['provider'] . ' ' . $provider['label'] . ' ' . implode(' ', $provider['setting_keys'])), $needle)
                    ));
                }
                break;

            default:
                $result += $data;
        }

        $result['cache'] = $read['cache'];

        return $result;
    }

    /**
     * @param  array<string, mixed> $part
     * @return array<string, mixed>
     */
    private static function filterRules(array $part, ?string $search): array
    {
        if ($search === null || $search === '' || ! is_array($part['rules'] ?? null)) {
            return $part;
        }

        $needle = strtolower($search);
        $part['rules'] = array_values(array_filter($part['rules'], static fn (array $rule): bool => str_contains(strtolower($rule['condition'] . ' ' . $rule['label'] . ' ' . $rule['context']), $needle)));
        $part['searched'] = $search;

        return $part;
    }

    private function storedTemplates(): mixed
    {
        if (function_exists('cs_stack_get_value')) {
            try {
                return cs_stack_get_value('cs_twig_templates');
            } catch (\Throwable) {
                // Read the option below.
            }
        }

        return get_option('cs_twig_templates', []);
    }

    public function annotations(): array
    {
        return Annotations::read('Get Native Reference');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
