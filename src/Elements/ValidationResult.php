<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Result of a layout validation.
 */
final class ValidationResult
{
    /** Issues returned in full by toArray(); the count is always complete. */
    public const MAX_LISTED_ISSUES = 100;

    /**
     * @param bool     $valid    Whether the layout is valid (no errors).
     * @param string[] $errors   List of validation errors.
     * @param string[] $warnings List of validation warnings.
     * @param array<int, array{code: string, path: string, type: string, message: string}> $issues
     *                           Every warning with its stable code and element path.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $issues = [],
    ) {}

    /**
     * How many issues each code produced.
     *
     * @return array<string, int>
     */
    public function codes(): array
    {
        $codes = [];

        foreach ($this->issues as $issue) {
            $codes[$issue['code']] = ($codes[$issue['code']] ?? 0) + 1;
        }

        ksort($codes);

        return $codes;
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array{valid: bool, errors: string[], warnings: string[], codes: array<string, int>|object, issue_count: int, issues: array<int, array<string, string>>}
     */
    public function toArray(): array
    {
        $codes = $this->codes();

        return [
            'valid'       => $this->valid,
            'errors'      => $this->errors,
            'warnings'    => $this->warnings,
            'codes'       => $codes === [] ? (object) [] : $codes,
            'issue_count' => count($this->issues),
            'issues'      => array_slice($this->issues, 0, self::MAX_LISTED_ISSUES),
        ];
    }
}
