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

final class InvalidWorkflowTopologyException extends WorkflowException
{
    /**
     * @param  list<string>  $cyclePath
     */
    public function __construct(
        public readonly string $violationCode,
        string $message,
        public readonly string $nodeKey = '',
        public readonly array $cyclePath = [],
        public readonly ?WorkflowDefinitionValidationResult $validationResult = null,
    ) {
        parent::__construct($message);
    }
}
