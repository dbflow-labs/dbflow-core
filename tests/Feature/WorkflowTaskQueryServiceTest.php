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

use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\WorkflowTaskQueryService;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowTaskQueryServiceTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;

    #[Test]
    public function it_returns_pending_assignments_for_a_user_with_eager_loaded_relations(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Inbox User',
            'email' => 'inbox-user@dbflow.dev',
        ]);

        $otherUser = TestUser::query()->create([
            'name' => 'Other User',
            'email' => 'other-user@dbflow.dev',
        ]);

        $assigneeId = (string) $assignee->getKey();

        $this->createMinimalPublishedWorkflow(
            'inbox_query_flow',
            'Inbox Query Flow',
            $assigneeId,
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'INBOX-001']);
        DBFlow::start('inbox_query_flow', $subject, $otherUser->getKey());

        $service = new WorkflowTaskQueryService;
        $paginator = $service->getPendingTasksForUser($assigneeId);

        $this->assertSame(1, $paginator->total());

        $assignment = $paginator->items()[0];
        $this->assertNotNull($assignment->workflowTask);
        $this->assertNotNull($assignment->workflowTask->workflowInstance);
        $this->assertNotNull($assignment->workflowTask->workflowInstance->workflow);
        $this->assertNotNull($assignment->workflowTask->workflowInstance->workflowVersion);
        $this->assertSame('inbox_query_flow', $assignment->workflowTask->workflowInstance->workflow->key);
    }

    #[Test]
    public function it_returns_pending_assignments_for_uuid_assignee_ids(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'UUID Inbox User',
            'email' => 'uuid-inbox-user@dbflow.dev',
        ]);

        $uuidAssigneeId = '550e8400-e29b-41d4-a716-446655440000';

        $this->createMinimalPublishedWorkflow(
            'uuid_inbox_query_flow',
            'UUID Inbox Query Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'UUID-INBOX-001']);
        DBFlow::start('uuid_inbox_query_flow', $subject, $assignee->getKey());

        WorkflowTaskAssignment::query()
            ->where('assignee_user_id', (string) $assignee->getKey())
            ->update(['assignee_user_id' => $uuidAssigneeId]);

        $service = new WorkflowTaskQueryService;

        $this->assertSame(1, $service->getPendingTasksForUser($uuidAssigneeId)->total());
        $this->assertSame(1, $service->countPendingTasksForUser($uuidAssigneeId));
    }

    #[Test]
    public function pending_count_decreases_after_task_is_approved(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Counter User',
            'email' => 'counter-user@dbflow.dev',
        ]);

        $userId = (string) $assignee->getKey();

        $this->createMinimalPublishedWorkflow(
            'count_query_flow',
            'Count Query Flow',
            $userId,
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'COUNT-001']);
        $instance = DBFlow::start('count_query_flow', $subject, $assignee->getKey());

        $service = new WorkflowTaskQueryService;

        $this->assertSame(1, $service->countPendingTasksForUser($userId));

        $task = $instance->tasks()->where('status', 'pending')->firstOrFail();
        DBFlow::approve($task, $assignee->getKey());

        $this->assertSame(0, $service->countPendingTasksForUser($userId));
    }

    #[Test]
    public function it_excludes_assignments_when_parent_task_is_no_longer_pending(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Stale Row User',
            'email' => 'stale-user@dbflow.dev',
        ]);

        $userId = (string) $assignee->getKey();

        $this->createMinimalPublishedWorkflow(
            'stale_query_flow',
            'Stale Query Flow',
            $userId,
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'STALE-001']);
        $instance = DBFlow::start('stale_query_flow', $subject, $assignee->getKey());

        $task = $instance->tasks()->where('status', 'pending')->firstOrFail();
        DBFlow::approve($task, $assignee->getKey());

        $service = new WorkflowTaskQueryService;

        $this->assertSame(0, $service->countPendingTasksForUser($userId));
        $this->assertSame(0, $service->getPendingTasksForUser($userId)->total());
    }

    #[Test]
    public function reassignment_moves_actionable_inbox_from_previous_effective_actor_to_target(): void
    {
        $fromAssignee = TestUser::query()->create([
            'name' => 'From Assignee',
            'email' => 'reassign-from@dbflow.dev',
        ]);

        $toAssignee = TestUser::query()->create([
            'name' => 'To Assignee',
            'email' => 'reassign-to@dbflow.dev',
        ]);

        $fromUserId = (string) $fromAssignee->getKey();
        $toUserId = (string) $toAssignee->getKey();

        $this->createMinimalPublishedWorkflow(
            'reassign_inbox_flow',
            'Reassign Inbox Flow',
            $fromUserId,
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-INBOX-001']);
        $instance = DBFlow::start('reassign_inbox_flow', $subject, $fromAssignee->getKey());
        $task = $instance->tasks()->where('status', 'pending')->firstOrFail();

        $service = new WorkflowTaskQueryService;

        $this->assertSame(1, $service->countPendingTasksForUser($fromUserId));
        $this->assertSame(0, $service->countPendingTasksForUser($toUserId));

        DBFlow::reassign($task, $fromAssignee->getKey(), $toUserId, 'Coverage handover');

        $this->assertSame(0, $service->countPendingTasksForUser($fromUserId));
        $this->assertSame(1, $service->countPendingTasksForUser($toUserId));

        $pendingForTarget = $service->pendingAssignmentsQueryForUser($toUserId)->get();
        $this->assertCount(1, $pendingForTarget);
        $this->assertSame($toUserId, $pendingForTarget->first()?->effectiveAssigneeUserId());
        $this->assertSame($fromUserId, $pendingForTarget->first()?->originalAssigneeUserId());
    }
}
