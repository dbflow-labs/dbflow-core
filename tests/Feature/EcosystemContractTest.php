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

use DbflowLabs\Core\Services\WorkflowTaskQueryService;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Locks the public integration surface documented in docs/integration/filament.md.
 */
final class EcosystemContractTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const DOCUMENTED_EAGER_LOADS = [
        'workflowTask',
        'workflowTask.workflowInstance',
        'workflowTask.workflowInstance.workflow',
        'workflowTask.workflowInstance.workflowVersion',
    ];

    #[Test]
    public function workflow_task_query_service_is_registered_in_the_container(): void
    {
        $service = $this->app->make(WorkflowTaskQueryService::class);

        $this->assertInstanceOf(WorkflowTaskQueryService::class, $service);
    }

    #[Test]
    public function workflow_task_query_service_public_api_matches_documented_contract(): void
    {
        $reflection = new ReflectionClass(WorkflowTaskQueryService::class);
        $publicMethods = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === WorkflowTaskQueryService::class,
        ));

        $this->assertCount(2, $publicMethods);

        $signatures = [];

        foreach ($publicMethods as $method) {
            $signatures[$method->getName()] = $this->methodSignatureKey($method);
        }

        ksort($signatures);

        $this->assertSame([
            'countPendingTasksForUser' => 'countPendingTasksForUser:string:int',
            'getPendingTasksForUser' => 'getPendingTasksForUser:string:int:'.LengthAwarePaginator::class,
        ], $signatures);
    }

    #[Test]
    public function get_pending_tasks_for_user_eager_loads_documented_relations(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WorkflowTaskQueryService::class))->getFileName(),
        );

        foreach (self::DOCUMENTED_EAGER_LOADS as $relation) {
            $this->assertStringContainsString(
                "'{$relation}'",
                $source,
                "Missing documented eager load [{$relation}] in WorkflowTaskQueryService.",
            );
        }
    }

    #[Test]
    public function documented_event_classes_exist_with_expected_public_properties(): void
    {
        $expected = [
            'WorkflowStarted' => ['instance'],
            'WorkflowCompleted' => ['instance'],
            'WorkflowRejected' => ['instance'],
            'WorkflowCancelled' => ['instance'],
            'TaskCreated' => ['task', 'instance'],
            'TaskApproved' => ['task', 'instance', 'actor', 'comment'],
            'TaskRejected' => ['task', 'instance', 'actor', 'comment'],
            'TaskReassigned' => ['task', 'instance', 'previousAssignment', 'newAssignment', 'actor', 'comment'],
            'TaskTimedOut' => ['task', 'instance', 'payload'],
        ];

        foreach ($expected as $shortName => $properties) {
            $class = "DbflowLabs\\Core\\Events\\{$shortName}";
            $this->assertTrue(class_exists($class), "Missing event class [{$class}].");

            $constructor = (new ReflectionClass($class))->getConstructor();
            $this->assertNotNull($constructor, "Event [{$class}] must have a constructor.");

            $actual = [];

            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isPromoted()) {
                    $actual[] = $parameter->getName();
                }
            }

            $this->assertSame($properties, $actual, "Event [{$class}] public properties changed.");
        }
    }

    private function methodSignatureKey(ReflectionMethod $method): string
    {
        $parts = [$method->getName()];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parts[] = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
        }

        $returnType = $method->getReturnType();
        $parts[] = $returnType instanceof ReflectionNamedType ? $returnType->getName() : 'mixed';

        return implode(':', $parts);
    }

    #[Test]
    public function get_pending_tasks_for_user_returns_length_aware_paginator(): void
    {
        $reflection = new ReflectionClass(WorkflowTaskQueryService::class);
        $method = $reflection->getMethod('getPendingTasksForUser');
        $returnType = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(LengthAwarePaginator::class, $returnType->getName());
    }
}
