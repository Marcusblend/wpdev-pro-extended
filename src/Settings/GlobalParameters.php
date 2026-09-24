<?php

declare(strict_types=1);

namespace ProExtended\Settings;

/**
 * Global parameters: named values the whole site's elements can be bound to.
 *
 * Two theme options hold them. cs_global_parameter_json is the schema — the
 * parameters that exist, their types and defaults, as a JSON string, the same
 * shape a component's _p_json uses. cs_global_parameter_data holds the values,
 * plus _bp_base and _bp_data<base> for the ones that vary by breakpoint.
 *
 * The pair has one trap worth guarding: Cornerstone only generates :root
 * custom properties for responsive parameters when the data carries _bp_base.
 * Write per-breakpoint values without it and the parameters exist, the elements
 * bind to them, and nothing renders.
 *
 * The schema is kept in the shape it came in. Decoding JSON into PHP arrays
 * turns every {} into [], and the builder reads [] as a list: a schema string
 * is therefore stored exactly as given, and a schema that arrives already
 * decoded is encoded with its objects put back where the schema always has
 * one (encodeSchema()).
 *
 * Pure PHP with no WordPress calls, so it is unit-tested without a site.
 */
final class GlobalParameters
{
    public const JSON_OPTION = 'cs_global_parameter_json';
    public const DATA_OPTION = 'cs_global_parameter_data';

    /**
     * Check a schema and value pair before it is written.
     *
     * @param  mixed  $json          The schema, as a JSON string or an array to encode.
     * @param  mixed  $data          The values.
     * @param  string $breakpointTag The site's breakpoint tag, such as "4_4".
     * @return array{json: string, data: array<string, mixed>, parameters: string[], responsive: string[], errors: string[], warnings: string[]}
     */
    public static function prepare(mixed $json, mixed $data, string $breakpointTag): array
    {
        $errors = [];
        $warnings = [];

        $schema = self::decodeSchema($json, $errors);
        $values = self::decodeData($data, $errors);

        if ($errors !== []) {
            return ['json' => '', 'data' => [], 'parameters' => [], 'responsive' => [], 'errors' => $errors, 'warnings' => $warnings];
        }

        $parameters = [];

        foreach (array_keys($schema) as $name) {
            if (is_string($name) && $name !== '' && ! str_starts_with($name, '_')) {
                $parameters[] = $name;
            }
        }

        $baseKey = '_bp_data' . $breakpointTag;
        $responsive = [];

        foreach ($values as $key => $value) {
            if ($key === $baseKey && is_array($value)) {
                foreach (array_keys($value) as $name) {
                    if (is_string($name)) {
                        $responsive[] = $name;
                    }
                }
            }
        }

        // Per-breakpoint values with no _bp_base: Cornerstone reads the base tag
        // to decide which media queries to write, so without it the responsive
        // values are stored and never rendered.
        if ($responsive !== [] && empty($values['_bp_base'])) {
            $values['_bp_base'] = $breakpointTag;
            $warnings[] = sprintf('The values carried per-breakpoint parameters but no _bp_base, so "%s" was added. Without it Cornerstone generates no :root variables for them.', $breakpointTag);
        }

        if (isset($values['_bp_base']) && $values['_bp_base'] !== $breakpointTag) {
            $warnings[] = sprintf('The values carry _bp_base "%s" while this site is on "%s"; Cornerstone will convert them when it reads them.', (string) $values['_bp_base'], $breakpointTag);
        }

        foreach ($responsive as $name) {
            if (! in_array($name, $parameters, true)) {
                $warnings[] = sprintf('"%s" has per-breakpoint values but is not in the schema, so nothing binds to it.', $name);
            }
        }

        // A string is written back byte for byte: decoding and re-encoding it
        // would turn {} into [] and change its escaping, so a write that only
        // touched the values would rewrite the schema.
        $encoded = is_string($json) ? $json : self::encodeSchema($schema);

        if ($encoded === null) {
            $errors[] = 'json could not be encoded as JSON.';

            return ['json' => '', 'data' => [], 'parameters' => [], 'responsive' => [], 'errors' => $errors, 'warnings' => $warnings];
        }

        return [
            'json'       => $encoded,
            'data'       => $values,
            'parameters' => $parameters,
            'responsive' => array_values(array_unique($responsive)),
            'errors'     => $errors,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Encode a _p_json parameter schema without losing its objects.
     *
     * A string is returned as given. An array has usually been through
     * json_decode(..., true), which turns {} into []; the places a schema is
     * always an object get their {} back: the top level, a group's params
     * (and the value of a "name#" or "name[]" key, which is one), each
     * parameter's definition, and a group's initial value. A list stays a
     * list, so a select's options or a group[]'s initial rows are untouched.
     *
     * @param  array<mixed>|string $schema
     * @return string|null Null when the array cannot be encoded.
     */
    public static function encodeSchema(array|string $schema): ?string
    {
        if (is_string($schema)) {
            return $schema;
        }

        $encoded = json_encode(self::objects($schema, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : null;
    }

    /**
     * @param bool $params Whether $node is a map of parameters (true) or one parameter's definition.
     */
    private static function objects(mixed $node, bool $params): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if ($node === []) {
            return new \stdClass();
        }

        if (array_is_list($node)) {
            return $node;
        }

        if ($params) {
            foreach ($node as $name => $definition) {
                $isGroup = str_ends_with((string) $name, '#') || str_ends_with((string) $name, '[]');
                $node[$name] = self::objects($definition, $isGroup);
            }

            return $node;
        }

        $type = is_string($node['type'] ?? null) ? $node['type'] : 'text';

        if (array_key_exists('params', $node)) {
            $node['params'] = self::objects($node['params'], true);
        }

        if ($type === 'group' && ($node['initial'] ?? null) === []) {
            $node['initial'] = new \stdClass();
        }

        return $node;
    }

    /**
     * @param  string[] $errors
     * @return array<string, mixed>
     */
    private static function decodeSchema(mixed $json, array &$errors): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json)) {
            $errors[] = 'json must be the parameter schema: a JSON object, or an object.';

            return [];
        }

        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $errors[] = 'json is not valid JSON, so Cornerstone would silently read no parameters at all.';

            return [];
        }

        return $decoded;
    }

    /**
     * @param  string[] $errors
     * @return array<string, mixed>
     */
    private static function decodeData(mixed $data, array &$errors): array
    {
        if ($data === null || $data === '') {
            return [];
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            if (! is_array($decoded)) {
                $errors[] = 'data is not valid JSON.';

                return [];
            }

            return $decoded;
        }

        if (! is_array($data)) {
            $errors[] = 'data must be an object of parameter values.';

            return [];
        }

        return $data;
    }
}
