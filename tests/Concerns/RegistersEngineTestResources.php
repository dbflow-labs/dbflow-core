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

use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Support\ActionManager;
use DbflowLabs\Core\Tests\Fixtures\RecordingActionHandler;
use DbflowLabs\Core\Tests\Fixtures\SequentialTeamAssigneeResolver;
use DbflowLabs\Core\Tests\Fixtures\TestUser;

trait RegistersEngineTestResources
{
    /**
     * @return array{first: TestUser, second: TestUser, director: TestUser}
     */
    protected function seedEngineUsers(): array
    {
        $first = TestUser::query()->create([
            'name' => 'Engine User One',
            'email' => 'engine-user-one@dbflow.dev',
        ]);

        $second = TestUser::query()->create([
            'name' => 'Engine User Two',
            'email' => 'engine-user-two@dbflow.dev',
        ]);

        $director = TestUser::query()->create([
            'name' => 'Engine Director',
            'email' => 'engine-director@dbflow.dev',
        ]);

        return [
            'first' => $first,
            'second' => $second,
            'director' => $director,
        ];
    }

    /**
     * @param  list<int>  $userIds
     */
    protected function registerSequentialTeamResolver(array $userIds): void
    {
        app(AssigneeResolverRegistry::class)->register(
            'sequential_team',
            new SequentialTeamAssigneeResolver($userIds),
        );
    }

    protected function registerRecordingActionHandler(): void
    {
        RecordingActionHandler::reset();

        app(ActionManager::class)->register(
            'record_preprocess',
            RecordingActionHandler::class,
        );
    }
}
