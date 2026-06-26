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

namespace DbflowLabs\Core\Tests\Feature\Engine;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Exceptions\PremiumFeatureMissingException;
use DbflowLabs\Core\Support\ActionManager;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\RecordingActionHandler;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ActionFencingTest extends TestCase
{
    use RegistersEngineTestResources;

    #[Test]
    public function unresolved_premium_action_type_throws_premium_feature_missing_exception(): void
    {
        $users = $this->seedEngineUsers();
        $this->publishDefinition($this->actionDefinition('premium_only_action'));

        $subject = ContextTestSubject::query()->create(['reference_code' => 'FENCE-PREMIUM-001']);

        $this->expectException(PremiumFeatureMissingException::class);
        $this->expectExceptionMessage('premium_only_action');

        DBFlow::start('premium_action_flow', $subject, $users['first']->getKey());
    }

    #[Test]
    public function registered_core_action_handler_resolves_and_executes(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerRecordingActionHandler();
        $this->publishDefinition($this->actionDefinition('record_preprocess'));

        $subject = ContextTestSubject::query()->create(['reference_code' => 'FENCE-CORE-001']);

        DBFlow::start('premium_action_flow', $subject, $users['first']->getKey());

        $this->assertSame(1, RecordingActionHandler::$callCount);
    }

    #[Test]
    public function core_action_manager_does_not_register_commercial_handlers_by_default(): void
    {
        $manager = app(ActionManager::class);

        $this->assertFalse($manager->has('premium_only_action'));
        $this->assertFalse($manager->has('send_notification'));
        $this->assertTrue($manager->has('log'));
        $this->assertTrue($manager->has('local_status_update'));
    }

    #[Test]
    public function premium_action_handler_classes_are_not_part_of_core_package(): void
    {
        $this->assertFalse(class_exists('DbflowLabs\\FilamentPro\\Actions\\SendNotificationHandler'));
        $this->assertTrue(class_exists(RecordingActionHandler::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function actionDefinition(string $actionKey): array
    {
        return [
            'key' => 'premium_action_flow',
            'name' => 'Premium Action Flow',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'premium_action',
                    'type' => 'action',
                    'name' => 'Premium Action',
                    'config' => ['action_key' => $actionKey],
                ],
                ['key' => 'end', 'type' => 'end', 'name' => 'End'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'premium_action'],
                ['from' => 'premium_action', 'to' => 'end'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function publishDefinition(array $definition): void
    {
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }
}
