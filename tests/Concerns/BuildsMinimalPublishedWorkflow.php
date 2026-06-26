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

namespace DbflowLabs\Core\Tests\Concerns;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Support\WorkflowBuilderNodeFactory;

trait BuildsMinimalPublishedWorkflow
{
    protected function createMinimalPublishedWorkflow(
        string $key = 'test_workflow',
        string $name = 'Test Workflow',
        string $assigneeValue = '1',
    ): Workflow {
        $factory = app(WorkflowBuilderNodeFactory::class);

        $nodes = [
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_START, 'start', 'Start'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_APPROVAL, 'approval', 'Approval'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_END, 'end', 'End'),
        ];

        $nodes[1]['config'][WorkflowDefinitionSchema::CONFIG_ASSIGNEES] = [
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => $assigneeValue,
        ];

        $definition = [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_NAME => $name,
            WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION => '1.0',
            WorkflowDefinitionSchema::FIELD_NODES => $nodes,
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'start',
                    WorkflowDefinitionSchema::FIELD_TO => 'approval',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'approval',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
            ],
        ];

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);

        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        return $workflow->fresh();
    }
}
