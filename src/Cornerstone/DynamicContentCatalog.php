<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * The Dynamic Content tokens a site actually has.
 *
 * A token is `{{dc:<group>:<field> arg='value'}}`, and which groups and fields
 * exist depends on what is installed — ACF, WooCommerce, The Events Calendar
 * and the Cornerstone extensions each add their own. Writing a token for a
 * field a site does not have produces no error: it renders as nothing, which is
 * why having the catalog matters more than guessing from documentation.
 *
 * The registry is assembled for builder requests, so reads go through
 * BuilderContext.
 */
final class DynamicContentCatalog
{
    /**
     * Groups and their fields, with the token each one writes.
     *
     * @return array{groups: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>}
     */
    public function all(): array
    {
        $data = $this->registry();

        $groups = [];

        foreach ((array) ($data['groups'] ?? []) as $name => $group) {
            if (! is_string($name) || ! is_array($group)) {
                continue;
            }

            $groups[$name] = [
                'group'   => $name,
                'label'   => (string) ($group['label'] ?? $name),
                'aliases' => array_values(array_filter(array_map('strval', (array) ($group['aliases'] ?? [])))),
                'fields'  => 0,
            ];
        }

        $fields = [];

        foreach ((array) ($data['fields'] ?? []) as $key => $field) {
            if (! is_string($key) || ! is_array($field)) {
                continue;
            }

            $group = (string) ($field['group'] ?? '');
            $name = (string) ($field['name'] ?? '');

            if ($group === '' || $name === '') {
                continue;
            }

            if (isset($groups[$group])) {
                $groups[$group]['fields']++;
            }

            $arguments = $this->arguments($field['controls'] ?? []);

            $row = [
                'token' => sprintf('{{dc:%s:%s}}', $group, $name),
                'group' => $group,
                'field' => $name,
                'label' => (string) ($field['label'] ?? $name),
            ];

            if ($arguments !== []) {
                $row['arguments'] = $arguments;
                $row['example'] = sprintf(
                    "{{dc:%s:%s %s='…'}}",
                    $group,
                    $name,
                    (string) ($arguments[0]['name'] ?? 'arg')
                );
            }

            $fields[] = $row;
        }

        usort($fields, static fn (array $a, array $b): int => [$a['group'], $a['field']] <=> [$b['group'], $b['field']]);
        ksort($groups);

        return ['groups' => array_values($groups), 'fields' => $fields];
    }

    /**
     * The named arguments a field takes.
     *
     * A control that is just a group name is the context the field reads (the
     * post, the term), not an argument someone writes in the token, so only the
     * spelled-out controls count.
     *
     * @param  mixed $controls
     * @return array<int, array<string, mixed>>
     */
    private function arguments(mixed $controls): array
    {
        if (! is_array($controls)) {
            return [];
        }

        $arguments = [];

        foreach ($controls as $control) {
            if (! is_array($control) || ! is_string($control['key'] ?? null) || $control['key'] === '') {
                continue;
            }

            $argument = [
                'name'  => (string) $control['key'],
                'type'  => (string) ($control['type'] ?? 'text'),
                'label' => (string) ($control['label'] ?? $control['key']),
            ];

            $choices = $control['options']['choices'] ?? null;

            if (is_array($choices) && $choices !== []) {
                $argument['choices'] = array_map('strval', array_keys($choices));
            }

            $arguments[] = $argument;
        }

        return $arguments;
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(): array
    {
        return BuilderContext::read(static function (): array {
            if (! function_exists('cornerstone')) {
                return [];
            }

            $service = cornerstone('DynamicContent');

            if (! is_object($service) || ! method_exists($service, 'get_dynamic_fields')) {
                return [];
            }

            $data = $service->get_dynamic_fields();

            return is_array($data) ? $data : [];
        }, []);
    }
}
