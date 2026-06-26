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

namespace DbflowLabs\Core\Contracts;

interface WorkflowContextInterface
{
    /**
     * Return business variables for condition node expression evaluation.
     *
     * Keys should match field names used in outgoing edge condition expressions in the workflow definition.
     * Example: ['total_amount' => 8000, 'supplier_type' => 'external']
     *
     * @return array<string, mixed>
     */
    public function getWorkflowVariables(): array;
}
