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

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Actions\ArchiveWorkflow;
use DbflowLabs\Core\Actions\CopyWorkflow;
use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\CreateWorkflowFromTemplate;
use DbflowLabs\Core\Actions\DeleteWorkflow;
use DbflowLabs\Core\Actions\DisableWorkflow;
use DbflowLabs\Core\Actions\EnableWorkflow;
use DbflowLabs\Core\Actions\SaveWorkflowDraft;
use DbflowLabs\Core\Actions\UpdateWorkflowDraftNodePositions;
use DbflowLabs\Core\Actions\UpdateWorkflowDraftStructure;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Exceptions\WorkflowInvalidStateException;
use DbflowLabs\Core\Exceptions\WorkflowKeyAlreadyExistsException;
use DbflowLabs\Core\Exceptions\WorkflowTemplateNotFoundException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Support\WorkflowBuilderNodeFactory;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowDefinitionManagementTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;

    #[Test]
    public function save_workflow_draft_updates_definition_metadata(): void
    {
        $workflow = $this->createMinimalPublishedWorkflow('save_draft_flow', 'Save Draft Flow');
        $definition = $workflow->draftDefinition();
        $definition[WorkflowDefinitionSchema::FIELD_NAME] = 'Renamed Draft Flow';

        $saved = app(SaveWorkflowDraft::class)->handle($workflow, $definition, '42');

        $this->assertSame('Renamed Draft Flow', $saved->draftDefinition()[WorkflowDefinitionSchema::FIELD_NAME]);
        $this->assertSame('42', $saved->draft_updated_by);
        $this->assertNull($saved->draft_validation_errors);
    }

    #[Test]
    public function update_workflow_draft_structure_normalizes_and_persists_definition(): void
    {
        $workflow = $this->createMinimalPublishedWorkflow('structure_flow', 'Structure Flow');
        $factory = app(WorkflowBuilderNodeFactory::class);

        $nodes = [
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_START, 'start', 'Start'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_APPROVAL, 'approval', 'Approval'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_END, 'end', 'End'),
        ];

        $nodes[1]['config'][WorkflowDefinitionSchema::CONFIG_ASSIGNEES] = [
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => '1',
        ];

        $updated = app(UpdateWorkflowDraftStructure::class)->handle($workflow, [
            WorkflowDefinitionSchema::FIELD_NODES => $nodes,
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'start',
                    WorkflowDefinitionSchema::FIELD_TO => 'approval',
                    WorkflowDefinitionSchema::FIELD_PRIORITY => '2',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'approval',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                ],
            ],
        ], '7');

        $definition = $updated->draftDefinition();

        $this->assertSame('structure_flow', $definition[WorkflowDefinitionSchema::FIELD_KEY]);
        $this->assertCount(3, $definition[WorkflowDefinitionSchema::FIELD_NODES]);
        $this->assertSame(2, $definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS][0][WorkflowDefinitionSchema::FIELD_PRIORITY]);
        $this->assertTrue($definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS][0][WorkflowDefinitionSchema::FIELD_IS_DEFAULT]);
    }

    #[Test]
    public function update_workflow_draft_node_positions_applies_canvas_coordinates(): void
    {
        $workflow = $this->createMinimalPublishedWorkflow('positions_flow', 'Positions Flow');

        $updated = app(UpdateWorkflowDraftNodePositions::class)->handle($workflow, [
            'start' => ['x' => 15, 'y' => 25],
        ], '9');

        $nodes = $updated->draftDefinition()[WorkflowDefinitionSchema::FIELD_NODES];
        $startNode = collect($nodes)->firstWhere(WorkflowDefinitionSchema::FIELD_KEY, 'start');

        $this->assertIsArray($startNode);
        $this->assertSame(15, $startNode['position']['x']);
        $this->assertSame(25, $startNode['position']['y']);
    }

    #[Test]
    public function copy_workflow_creates_new_draft_from_published_source(): void
    {
        $source = $this->createMinimalPublishedWorkflow('copy_source_flow', 'Copy Source Flow');

        $copy = app(CopyWorkflow::class)->handle($source, 'copy_target_flow', 'Copy Target Flow', '3');

        $this->assertSame('copy_target_flow', $copy->key);
        $this->assertSame('Copy Target Flow', $copy->name);
        $this->assertTrue($copy->hasDraft());
        $this->assertNull($copy->current_version_id);
    }

    #[Test]
    public function copy_workflow_rejects_duplicate_keys(): void
    {
        $source = $this->createMinimalPublishedWorkflow('copy_dup_source', 'Copy Dup Source');
        $this->createMinimalPublishedWorkflow('copy_dup_target', 'Copy Dup Target');

        $this->expectException(WorkflowKeyAlreadyExistsException::class);

        app(CopyWorkflow::class)->handle($source, 'copy_dup_target', 'Duplicate Target');
    }

    #[Test]
    public function disable_and_enable_workflow_toggle_lifecycle_state(): void
    {
        $workflow = $this->createMinimalPublishedWorkflow('toggle_flow', 'Toggle Flow');

        $disabled = app(DisableWorkflow::class)->handle($workflow, '1');
        $this->assertTrue($disabled->isDisabled());
        $this->assertFalse($disabled->is_enabled);

        $disabledAgain = app(DisableWorkflow::class)->handle($disabled);
        $this->assertTrue($disabledAgain->isDisabled());

        $enabled = app(EnableWorkflow::class)->handle($disabledAgain, '2');
        $this->assertFalse($enabled->isDisabled());
        $this->assertTrue($enabled->is_enabled);
        $this->assertTrue($enabled->isPublished());
    }

    #[Test]
    public function archive_workflow_is_idempotent(): void
    {
        $workflow = $this->createMinimalPublishedWorkflow('archive_flow', 'Archive Flow');

        $archived = app(ArchiveWorkflow::class)->handle($workflow, '5');
        $this->assertTrue($archived->isArchived());
        $this->assertFalse($archived->is_enabled);

        $archivedAgain = app(ArchiveWorkflow::class)->handle($archived);
        $this->assertTrue($archivedAgain->isArchived());
    }

    #[Test]
    public function archived_workflow_cannot_be_enabled_or_disabled(): void
    {
        $workflow = $this->createMinimalPublishedWorkflow('archived_guard_flow', 'Archived Guard Flow');
        $archived = app(ArchiveWorkflow::class)->handle($workflow);

        try {
            app(EnableWorkflow::class)->handle($archived);
            $this->fail('Expected WorkflowInvalidStateException when enabling archived workflow.');
        } catch (WorkflowInvalidStateException) {
        }

        try {
            app(DisableWorkflow::class)->handle($archived);
            $this->fail('Expected WorkflowInvalidStateException when disabling archived workflow.');
        } catch (WorkflowInvalidStateException) {
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function delete_workflow_removes_definition_without_instances(): void
    {
        $draft = app(CreateWorkflowDraft::class)->handle($this->minimalDraftDefinition('delete_me_flow', 'Delete Me'), '1');

        app(DeleteWorkflow::class)->handle($draft);

        $this->assertNull(Workflow::query()->find($draft->getKey()));
    }

    #[Test]
    public function delete_workflow_is_blocked_when_instances_exist(): void
    {
        $workflow = $this->createMinimalPublishedWorkflow('delete_blocked_flow', 'Delete Blocked Flow');
        $subject = ContextTestSubject::query()->create(['reference_code' => 'DEL-BLOCK-001']);
        DBFlow::start('delete_blocked_flow', $subject, '1');

        $this->expectException(WorkflowInvalidStateException::class);

        app(DeleteWorkflow::class)->handle($workflow);
    }

    #[Test]
    public function create_workflow_from_template_validates_inputs(): void
    {
        $action = app(CreateWorkflowFromTemplate::class);

        try {
            $action->handle('missing_template', 'from_tpl_flow', 'From Template');
            $this->fail('Expected WorkflowTemplateNotFoundException.');
        } catch (WorkflowTemplateNotFoundException) {
        }

        try {
            $action->handle('simple_approval', '', 'From Template');
            $this->fail('Expected InvalidArgumentException for empty workflow key.');
        } catch (InvalidArgumentException) {
        }

        $this->createMinimalPublishedWorkflow('existing_tpl_flow', 'Existing Template Flow');

        try {
            $action->handle('simple_approval', 'existing_tpl_flow', 'Duplicate');
            $this->fail('Expected WorkflowKeyAlreadyExistsException.');
        } catch (WorkflowKeyAlreadyExistsException) {
        }

        $this->assertTrue(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalDraftDefinition(string $key, string $name): array
    {
        $factory = app(WorkflowBuilderNodeFactory::class);

        $nodes = [
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_START, 'start', 'Start'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_APPROVAL, 'approval', 'Approval'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_END, 'end', 'End'),
        ];

        $nodes[1]['config'][WorkflowDefinitionSchema::CONFIG_ASSIGNEES] = [
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => '1',
        ];

        return [
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
    }
}
