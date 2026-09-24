<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * The condition rules this site's Cornerstone knows, for element show
 * conditions and for document assignments.
 *
 * Both lists come from Cornerstone's Conditionals service
 * (`get_condition_contexts()` and `get_assignment_contexts()`, each behind
 * its own filter), which is what the builder's condition pickers show. A rule
 * key such as "single:post-type" names a handler; anything after a "|" is an
 * argument the key carries ("single:specific-post-of-type|page"). RuleMatching
 * turns the handler into a method or a `cs_condition_rule_<name>` filter,
 * adding "_<operator>" for the expression rules.
 *
 * shape() is pure, so it is unit-tested from a fixture of the registry.
 */
final class ConditionCatalog
{
    /** Choices listed per rule before the list is cut short. */
    public const MAX_CHOICES = 40;

    /**
     * How element show_condition rules are stored (RuleMatching::normalizeRuleSet).
     */
    public const SHOW_CONDITION_SHAPE = [
        'key'       => 'show_condition',
        'rule'      => '{"group": bool, "condition": "<rule key>", "value": "<criteria value>", "toggle": bool, "operator": "<operator>", "operand": "<left-hand value>"}',
        'group'     => 'true starts a new group. Rules inside a group must all match (AND); the element shows when any group matches (OR).',
        'toggle'    => 'Boolean rules: true is "is", false is "is not". Omit for operator rules.',
        'operator'  => 'Operator rules (type "operator") only: one of the rule\'s operators. operand is the left-hand value (often a Dynamic Content token), value the right-hand one.',
        'value'     => 'The criteria value; "" for rules whose criteria type is static.',
    ];

    /**
     * How document assignments are stored (update_document_settings "assignments").
     */
    public const ASSIGNMENT_SHAPE = [
        'key'   => 'assignments',
        'rule'  => '{"group": bool, "condition": "<rule key>", "value": "<criteria value>"}',
        'group' => 'true starts a new group; rules in a group are ANDed and groups are ORed.',
        'value' => '"" for rules whose criteria type is static.',
    ];

    /**
     * Turn one Conditionals contexts array into rule rows.
     *
     * @param  array<string, mixed> $contexts {labels: {context: label}, controls: {context: rule[]}}
     * @return array{contexts: array<int, array{context: string, label: string, rules: int}>, rules: array<int, array<string, mixed>>}
     */
    public static function shape(array $contexts): array
    {
        $labels = is_array($contexts['labels'] ?? null) ? $contexts['labels'] : [];
        $controls = is_array($contexts['controls'] ?? null) ? $contexts['controls'] : [];

        $summary = [];
        $rules = [];

        foreach ($controls as $context => $list) {
            if (! is_string($context) || ! is_array($list)) {
                continue;
            }

            $count = 0;

            foreach ($list as $rule) {
                $row = is_array($rule) ? self::rule($rule, $context) : null;

                if ($row !== null) {
                    $rules[] = $row;
                    $count++;
                }
            }

            $summary[] = [
                'context' => $context,
                'label'   => is_string($labels[$context] ?? null) ? $labels[$context] : $context,
                'rules'   => $count,
            ];
        }

        return ['contexts' => $summary, 'rules' => $rules];
    }

    /**
     * Stable identifiers for the rules, for the platform baseline: each
     * handler once, and each operator an operator rule accepts.
     *
     * @param  array<int, array<string, mixed>> $rules shape()['rules'], from any number of contexts.
     * @return string[]
     */
    public static function fingerprint(array $rules): array
    {
        $ids = [];

        foreach ($rules as $rule) {
            $handler = (string) ($rule['handler'] ?? '');

            if ($handler === '') {
                continue;
            }

            $ids[$handler] = true;

            foreach ((array) ($rule['operators'] ?? []) as $operator) {
                $ids[$handler . '[' . (string) $operator . ']'] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * The method or filter name RuleMatching resolves a rule to.
     */
    public static function ruleName(string $condition, ?string $operator = null): string
    {
        $handler = explode('|', $condition)[0];
        $name = str_replace([':', '-'], '_', $handler);

        return $operator !== null && $operator !== '' ? $name . '_' . str_replace('-', '_', $operator) : $name;
    }

    /**
     * @param  array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    private static function rule(array $rule, string $context): ?array
    {
        $key = $rule['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $parts = explode('|', $key);
        $handler = array_shift($parts);

        $row = [
            'condition' => $key,
            'label'     => is_string($rule['label'] ?? null) ? $rule['label'] : $key,
            'context'   => $context,
            'handler'   => $handler,
        ];

        if ($parts !== []) {
            $row['arguments'] = $parts;
        }

        $toggle = is_array($rule['toggle'] ?? null) ? $rule['toggle'] : [];
        $toggleType = is_string($toggle['type'] ?? null) ? $toggle['type'] : 'boolean';
        $row['type'] = $toggleType;

        if ($toggleType === 'operator') {
            $values = array_values(array_map('strval', is_array($toggle['values'] ?? null) ? $toggle['values'] : []));
            $labels = array_values(is_array($toggle['labels'] ?? null) ? $toggle['labels'] : []);
            $row['operators'] = $values;

            $named = [];

            foreach ($values as $i => $value) {
                $named[$value] = is_scalar($labels[$i] ?? null) ? (string) $labels[$i] : $value;
            }

            $row['operator_labels'] = $named;
            $row['rule_names'] = array_map(static fn (string $op): string => self::ruleName($key, $op), $values);
        } else {
            $labels = is_array($toggle['labels'] ?? null) ? array_values($toggle['labels']) : [];

            if (count($labels) === 2 && is_scalar($labels[0]) && is_scalar($labels[1])) {
                $row['toggle_labels'] = ['true' => (string) $labels[0], 'false' => (string) $labels[1]];
            }

            $row['rule_name'] = self::ruleName($key);
        }

        $row['value'] = self::criteria(is_array($rule['criteria'] ?? null) ? $rule['criteria'] : ['type' => 'static']);

        return $row;
    }

    /**
     * What a rule's value can be.
     *
     * @param  array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private static function criteria(array $criteria): array
    {
        $type = is_string($criteria['type'] ?? null) ? $criteria['type'] : 'static';
        $out = ['type' => $type];

        if ($type === 'static') {
            $out['note'] = 'Takes no value; store "".';
        }

        if ($type === 'date-picker') {
            $out['note'] = 'A date or date-time string, for example "2026-11-01 00:00".';
        }

        $choices = $criteria['choices'] ?? null;

        if (is_string($choices) && $choices !== '') {
            // A remote lookup the builder resolves, e.g. "posts:page" or "terms:category".
            $out['lookup'] = $choices;
            $out['note'] = self::lookupNote($choices);
        } elseif (is_array($choices)) {
            $values = [];

            foreach ($choices as $index => $choice) {
                if (is_array($choice) && array_key_exists('value', $choice) && is_scalar($choice['value'])) {
                    $values[] = ['value' => (string) $choice['value'], 'label' => is_scalar($choice['label'] ?? null) ? (string) $choice['label'] : (string) $choice['value']];
                } elseif (is_scalar($choice)) {
                    $values[] = ['value' => is_string($index) ? $index : (string) $choice, 'label' => (string) $choice];
                }
            }

            $out['choice_count'] = count($values);
            $out['choices'] = array_slice($values, 0, self::MAX_CHOICES);

            if (count($values) > self::MAX_CHOICES) {
                $out['truncated'] = true;
            }
        }

        return $out;
    }

    private static function lookupNote(string $lookup): string
    {
        [$kind, $argument] = array_pad(explode(':', $lookup, 2), 2, '');

        return match ($kind) {
            'posts'  => sprintf('A post ID (as a number) of type "%s".', $argument),
            'terms', 'taxonomy-terms' => $argument === 'all' ? 'A term ID from any taxonomy.' : sprintf('A term ID from the "%s" taxonomy.', $argument),
            'user'   => 'A user ID.',
            default  => sprintf('A value from the builder lookup "%s".', $lookup),
        };
    }

    // ─── Live registry ───────────────────────────────────────────────────────

    /**
     * Both registries, shaped, or null for one that could not be read.
     *
     * @return array{show_conditions: array<string, mixed>|null, assignments: array<string, mixed>|null}
     */
    public function read(): array
    {
        $contexts = BuilderContext::read(static function (): array {
            if (! function_exists('cornerstone')) {
                return [];
            }

            $service = cornerstone('Conditionals');

            if (! is_object($service)) {
                return [];
            }

            return [
                'show'   => method_exists($service, 'get_condition_contexts') ? $service->get_condition_contexts() : null,
                'assign' => method_exists($service, 'get_assignment_contexts') ? $service->get_assignment_contexts() : null,
            ];
        }, []);

        return [
            'show_conditions' => is_array($contexts['show'] ?? null) ? self::shape($contexts['show']) : null,
            'assignments'     => is_array($contexts['assign'] ?? null) ? self::shape($contexts['assign']) : null,
        ];
    }
}
