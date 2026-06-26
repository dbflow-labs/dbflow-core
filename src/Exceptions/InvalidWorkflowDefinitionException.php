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

namespace DbflowLabs\Core\Exceptions;

use DbflowLabs\Core\Validation\WorkflowDefinitionValidationResult;

class InvalidWorkflowDefinitionException extends WorkflowException
{
    private ?WorkflowDefinitionValidationResult $validationResult = null;

    public function __construct(WorkflowDefinitionValidationResult|string $resultOrMessage = '')
    {
        if ($resultOrMessage instanceof WorkflowDefinitionValidationResult) {
            $this->validationResult = $resultOrMessage;

            parent::__construct($this->buildMessageFromResult($resultOrMessage));

            return;
        }

        parent::__construct($resultOrMessage);
    }

    public function validationResult(): ?WorkflowDefinitionValidationResult
    {
        return $this->validationResult;
    }

    private function buildMessageFromResult(WorkflowDefinitionValidationResult $result): string
    {
        $firstError = $result->errors()[0] ?? null;

        if ($firstError === null) {
            return 'Workflow definition is invalid.';
        }

        return $firstError['message'];
    }
}
