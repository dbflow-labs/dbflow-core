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

        $this->createMinimalPublishedWorkflow(
            'inbox_query_flow',
            'Inbox Query Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'INBOX-001']);
        DBFlow::start('inbox_query_flow', $subject, $otherUser->getKey());

        $service = new WorkflowTaskQueryService;
        $paginator = $service->getPendingTasksForUser((int) $assignee->getKey());

        $this->assertSame(1, $paginator->total());

        $assignment = $paginator->items()[0];
        $this->assertNotNull($assignment->workflowTask);
        $this->assertNotNull($assignment->workflowTask->workflowInstance);
        $this->assertNotNull($assignment->workflowTask->workflowInstance->workflow);
        $this->assertNotNull($assignment->workflowTask->workflowInstance->workflowVersion);
        $this->assertSame('inbox_query_flow', $assignment->workflowTask->workflowInstance->workflow->key);
    }

    #[Test]
    public function pending_count_decreases_after_task_is_approved(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Counter User',
            'email' => 'counter-user@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'count_query_flow',
            'Count Query Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'COUNT-001']);
        $instance = DBFlow::start('count_query_flow', $subject, $assignee->getKey());

        $service = new WorkflowTaskQueryService;
        $userId = (int) $assignee->getKey();

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

        $this->createMinimalPublishedWorkflow(
            'stale_query_flow',
            'Stale Query Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'STALE-001']);
        $instance = DBFlow::start('stale_query_flow', $subject, $assignee->getKey());

        $task = $instance->tasks()->where('status', 'pending')->firstOrFail();
        DBFlow::approve($task, $assignee->getKey());

        $service = new WorkflowTaskQueryService;

        $this->assertSame(0, $service->countPendingTasksForUser((int) $assignee->getKey()));
        $this->assertSame(0, $service->getPendingTasksForUser((int) $assignee->getKey())->total());
    }
}
