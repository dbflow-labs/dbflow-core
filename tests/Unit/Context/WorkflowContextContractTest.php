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

namespace DbflowLabs\Core\Tests\Unit\Context;

use DbflowLabs\Core\Context\ContextPathResolver;
use DbflowLabs\Core\Context\FieldCatalog;
use DbflowLabs\Core\Context\FieldDefinition;
use DbflowLabs\Core\Context\WorkflowContextNormalizer;
use DbflowLabs\Core\Contracts\WorkflowContextInterface;
use DbflowLabs\Core\Enums\ContextAccessPurpose;
use DbflowLabs\Core\Enums\FieldType;
use DbflowLabs\Core\Exceptions\ContextPathException;
use DbflowLabs\Core\Exceptions\InvalidWorkflowContextException;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowContextContractTest extends TestCase
{
    #[Test]
    public function legacy_workflow_context_interface_normalizes_into_context_namespace(): void
    {
        $provider = (new ContextTestSubject)->withWorkflowVariables([
            'amount' => 1200,
            'approved' => true,
            'note' => null,
        ]);

        $this->assertInstanceOf(WorkflowContextInterface::class, $provider);

        $normalized = (new WorkflowContextNormalizer)->fromWorkflowContextInterface(
            $provider,
            workflow: ['key' => 'demo', 'instance_id' => 9],
            starter: ['id' => '1'],
            actor: ['id' => '2'],
        );

        $bag = $normalized->toArray();
        $this->assertSame(1200, $bag['context']['amount']);
        $this->assertTrue($bag['context']['approved']);
        $this->assertNull($bag['context']['note']);
        $this->assertSame('demo', $bag['workflow']['key']);
        $this->assertSame('1', $bag['starter']['id']);
        $this->assertSame('2', $bag['actor']['id']);
        $this->assertNotSame($bag['starter'], $bag['actor']);
    }

    #[Test]
    public function user_context_cannot_overwrite_system_namespaces(): void
    {
        $this->expectException(InvalidWorkflowContextException::class);

        (new WorkflowContextNormalizer)->normalize(
            legacyVariables: ['amount' => 1],
            workflow: ['key' => 'demo'],
            userContextOverrides: ['workflow' => ['key' => 'hacked']],
        );
    }

    #[Test]
    public function namespace_collision_with_nested_system_key_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowContextException::class);

        (new WorkflowContextNormalizer)->normalize([
            'workflow' => ['key' => 'collision'],
        ]);
    }

    #[Test]
    public function eloquent_models_cannot_be_persisted_in_context(): void
    {
        $this->expectException(InvalidWorkflowContextException::class);

        (new WorkflowContextNormalizer)->normalize([
            'subject' => new ContextTestSubject,
        ]);
    }

    #[Test]
    public function field_catalog_rejects_duplicates_and_invalid_operators(): void
    {
        FieldCatalog::fromArrays([
            [
                'path' => 'context.amount',
                'type' => FieldType::Integer->value,
                'label' => 'Amount',
            ],
        ]);

        $this->expectException(InvalidWorkflowContextException::class);

        FieldCatalog::fromArrays([
            [
                'path' => 'context.amount',
                'type' => FieldType::Integer->value,
                'label' => 'Amount',
            ],
            [
                'path' => 'context.amount',
                'type' => FieldType::Integer->value,
                'label' => 'Amount again',
            ],
        ]);
    }

    #[Test]
    public function field_definition_rejects_invalid_path_and_operator_combinations(): void
    {
        $this->expectException(InvalidWorkflowContextException::class);

        FieldDefinition::fromArray([
            'path' => 'invalid-path',
            'type' => 'string',
            'label' => 'Bad',
        ]);
    }

    #[Test]
    public function path_resolver_distinguishes_found_null_and_missing(): void
    {
        $context = (new WorkflowContextNormalizer)->normalize(
            legacyVariables: [
                'amount' => 10,
                'note' => null,
                'nested' => ['code' => 'A'],
            ],
            workflow: ['key' => 'demo'],
        );

        $resolver = new ContextPathResolver;

        $found = $resolver->resolve($context, 'context.nested.code');
        $this->assertTrue($found->isFound());
        $this->assertSame('A', $found->value());

        $presentNull = $resolver->resolve($context, 'context.note');
        $this->assertTrue($presentNull->isPresentNull());

        $missing = $resolver->resolve($context, 'context.missing');
        $this->assertTrue($missing->isMissing());
    }

    #[Test]
    public function path_resolver_rejects_invalid_and_sensitive_access(): void
    {
        $catalog = FieldCatalog::fromArrays([
            [
                'path' => 'context.secret',
                'type' => 'string',
                'label' => 'Secret',
                'sensitive' => true,
            ],
        ]);

        $context = (new WorkflowContextNormalizer)->normalize([
            'secret' => 'top-secret',
        ]);

        $resolver = (new ContextPathResolver)->withFieldCatalog($catalog);

        $allowed = $resolver->resolve($context, 'context.secret', ContextAccessPurpose::Condition);
        $this->assertSame('top-secret', $allowed->value());

        $this->expectException(ContextPathException::class);
        $resolver->resolve($context, 'context.secret', ContextAccessPurpose::UiPreview);
    }

    #[Test]
    public function path_resolver_rejects_prohibited_segments(): void
    {
        $context = (new WorkflowContextNormalizer)->normalize(['amount' => 1]);
        $resolver = new ContextPathResolver;

        $this->expectException(ContextPathException::class);
        $resolver->resolve($context, 'context.__get');
    }
}
