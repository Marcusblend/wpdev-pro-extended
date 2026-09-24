<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DynamicContentCatalog;
use ProExtended\Support\Args;

final class ListDynamicContent implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly DynamicContentCatalog $catalog) {}

    public function name(): string
    {
        return 'list_dynamic_content';
    }

    public function description(): string
    {
        return 'Deprecated: use get_native_reference with section "dynamic_content", which returns the same result (this alias is removed after 1.5). List the Dynamic Content tokens this site has: every group and field, the token each one writes ({{dc:post:title}}), and the named arguments a field takes. A token for a field the site does not have renders as nothing rather than raising an error. Narrow with group or search.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'group' => [
                    'type'        => 'string',
                    'description' => 'Optional. Only this group\'s fields.',
                ],
                'search' => [
                    'type'        => 'string',
                    'description' => 'Optional. Match a field name, label or token.',
                ],
                'groups_only' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Return the groups and their field counts, without the fields. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['group', 'search', 'groups_only'], 'arguments');

        return self::present($this->catalog->all(), $arguments);
    }

    /**
     * The catalog narrowed the way the arguments ask. Shared with
     * get_native_reference's dynamic_content section.
     *
     * @param  array{groups: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>} $catalog
     * @param  array<string, mixed>                                                                       $arguments group, search, groups_only.
     * @return array<string, mixed>
     */
    public static function present(array $catalog, array $arguments): array
    {
        $group = Args::string($arguments, 'group', null, 100);
        $search = Args::string($arguments, 'search', null, 200);
        $groupsOnly = Args::bool($arguments, 'groups_only', false);

        $groups = $catalog['groups'];
        $fields = $catalog['fields'];

        if ($groups === [] && $fields === []) {
            return [
                'groups' => [],
                'fields' => [],
                'note'   => 'This site returned no Dynamic Content registry.',
            ];
        }

        if ($group !== null && $group !== '') {
            $known = array_column($groups, 'group');

            if (! in_array($group, $known, true)) {
                throw new \InvalidArgumentException(sprintf('No token group "%s" on this site (%s).', $group, implode(', ', $known)));
            }

            $fields = array_values(array_filter($fields, static fn (array $field): bool => $field['group'] === $group));
        }

        if ($search !== null && $search !== '') {
            $needle = strtolower($search);
            $fields = array_values(array_filter($fields, static function (array $field) use ($needle): bool {
                foreach (['field', 'label', 'token', 'group'] as $key) {
                    if (str_contains(strtolower((string) $field[$key]), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $result = [
            'group_count' => count($groups),
            'groups'      => $groups,
        ];

        if ($groupsOnly) {
            return $result;
        }

        $result['field_count'] = count($fields);
        $result['fields'] = $fields;

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::read('List Dynamic Content');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
