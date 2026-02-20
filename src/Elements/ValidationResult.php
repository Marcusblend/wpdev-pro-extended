<?php

declare(strict_types=1);

namespace ProExtended\Elements;

/**
 * Result of a layout validation.
 */
final class ValidationResult
{
    /**
     * @param bool     $valid    Whether the layout is valid (no errors).
     * @param string[] $errors   List of validation errors.
     * @param string[] $warnings List of validation warnings.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly array $warnings,
    ) {}

    /**
     * Convert to array for JSON serialization.
     *
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    public function toArray(): array
    {
        return [
            'valid'    => $this->valid,
            'errors'   => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
