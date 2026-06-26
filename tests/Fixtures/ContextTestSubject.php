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

namespace DbflowLabs\Core\Tests\Fixtures;

use DbflowLabs\Core\Contracts\WorkflowContextInterface;
use DbflowLabs\Core\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

final class ContextTestSubject extends Model implements WorkflowContextInterface
{
    use HasWorkflow;

    protected $table = 'integer_test_subjects';

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    private array $workflowVariables = [];

    /**
     * @param  array<string, mixed>  $variables
     */
    public function withWorkflowVariables(array $variables): self
    {
        $this->workflowVariables = $variables;

        return $this;
    }

    public function getWorkflowVariables(): array
    {
        return $this->workflowVariables;
    }
}
