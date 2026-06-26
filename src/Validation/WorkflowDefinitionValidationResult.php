<?php

/**
 * This file is part of the dbflow-labs/core package.
 *
 * Copyright (c) 2026 Baron Wang <hello@dbflow.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 * @link    https://dbflow.dev
 * @see     https://github.com/dbflow-labs/dbflow-core
 */

declare(strict_types=1);

namespace DbflowLabs\Core\Validation;

final class WorkflowDefinitionValidationResult
{
    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     */
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = [],
        private readonly array $warnings = [],
    ) {}

    /**
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     */
    public static function valid(array $warnings = []): self
    {
        return new self(true, [], $warnings);
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     * @param  list<array{path: string, code: string, message: string}>  $warnings
     */
    public static function invalid(array $errors, array $warnings = []): self
    {
        return new self(false, $errors, $warnings);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array{valid: bool, errors: list<array{path: string, code: string, message: string}>, warnings: list<array{path: string, code: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
