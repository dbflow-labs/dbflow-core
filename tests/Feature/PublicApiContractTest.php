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
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Actions\SaveWorkflowDraft;
use DbflowLabs\Core\Actions\SyncWorkflowDefinitions;
use DbflowLabs\Core\Actions\UpdateWorkflowDraftNodePositions;
use DbflowLabs\Core\Actions\UpdateWorkflowDraftStructure;
use DbflowLabs\Core\Contracts\TaskHooks;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Enums\RejectStrategy;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Locks the stable 1.0 public API surface (runtime facade, hooks, and internal boundaries).
 */
final class PublicApiContractTest extends TestCase
{
    /**
     * @var list<class-string>
     */
    private const INTERNAL_DEFINITION_ACTIONS = [
        ArchiveWorkflow::class,
        CopyWorkflow::class,
        CreateWorkflowDraft::class,
        CreateWorkflowFromTemplate::class,
        DeleteWorkflow::class,
        DisableWorkflow::class,
        EnableWorkflow::class,
        PublishWorkflowDraft::class,
        SaveWorkflowDraft::class,
        SyncWorkflowDefinitions::class,
        UpdateWorkflowDraftNodePositions::class,
        UpdateWorkflowDraftStructure::class,
    ];

    #[Test]
    public function dbflow_runtime_api_matches_frozen_signatures(): void
    {
        $reflection = new ReflectionClass(DBFlow::class);
        $runtimeMethods = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === DBFlow::class
                && in_array($method->getName(), ['start', 'approve', 'reject', 'cancel', 'reassign'], true),
        ));

        $this->assertCount(5, $runtimeMethods);

        $signatures = [];

        foreach ($runtimeMethods as $method) {
            $signatures[$method->getName()] = $this->methodSignatureKey($method);
        }

        ksort($signatures);

        $this->assertSame([
            'approve' => 'approve:'.WorkflowTask::class.':mixed:?string:'.WorkflowInstance::class,
            'cancel' => 'cancel:'.WorkflowInstance::class.':mixed:?string:'.WorkflowInstance::class,
            'reassign' => 'reassign:'.WorkflowTask::class.':mixed:string:?string:'.WorkflowInstance::class,
            'reject' => 'reject:'.WorkflowTask::class.':mixed:?string:'.RejectStrategy::class.':?string:'.WorkflowInstance::class,
            'start' => 'start:string:'.Model::class.':mixed:array:'.WorkflowInstance::class,
        ], $signatures);
    }

    #[Test]
    public function dbflow_registration_api_matches_frozen_signatures(): void
    {
        $reflection = new ReflectionClass(DBFlow::class);
        $registrationMethods = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === DBFlow::class
                && str_starts_with($method->getName(), 'register'),
        ));

        $this->assertCount(6, $registrationMethods);

        $names = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $registrationMethods);
        sort($names);

        $this->assertSame([
            'registerAll',
            'registerAssigneeResolver',
            'registerCore',
            'registerDefinitionProvider',
            'registerTaskHooks',
            'registerWorkflowHooks',
        ], $names);
    }

    #[Test]
    public function task_hooks_contract_methods_are_frozen(): void
    {
        $reflection = new ReflectionClass(TaskHooks::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $signatures = [];

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== TaskHooks::class) {
                continue;
            }

            $signatures[$method->getName()] = $this->methodSignatureKey($method);
        }

        ksort($signatures);

        $this->assertSame([
            'onAfterApprove' => 'onAfterApprove:'.WorkflowTask::class.':'.WorkflowInstance::class.':mixed:void',
            'onAfterReject' => 'onAfterReject:'.WorkflowTask::class.':'.WorkflowInstance::class.':mixed:void',
            'onReassigned' => 'onReassigned:'.WorkflowTask::class.':'.WorkflowInstance::class.':mixed:string:void',
            'onTaskCreated' => 'onTaskCreated:'.WorkflowTask::class.':'.WorkflowInstance::class.':void',
        ], $signatures);
    }

    #[Test]
    public function definition_management_actions_are_marked_internal(): void
    {
        foreach (self::INTERNAL_DEFINITION_ACTIONS as $class) {
            $docComment = (new ReflectionClass($class))->getDocComment();

            $this->assertNotFalse(
                $docComment,
                "Class [{$class}] must have a docblock with @internal.",
            );
            $this->assertStringContainsString(
                '@internal',
                $docComment,
                "Class [{$class}] must be marked @internal.",
            );
        }
    }

    private function methodSignatureKey(ReflectionMethod $method): string
    {
        $parts = [$method->getName()];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();
                $parts[] = $typeName === 'mixed'
                    ? 'mixed'
                    : ($type->allowsNull() ? '?'.$typeName : $typeName);
            } else {
                $parts[] = 'mixed';
            }
        }

        $returnType = $method->getReturnType();
        $parts[] = $returnType instanceof ReflectionNamedType ? $returnType->getName() : 'mixed';

        return implode(':', $parts);
    }
}
