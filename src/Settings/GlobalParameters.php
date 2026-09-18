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

        $encoded = (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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
