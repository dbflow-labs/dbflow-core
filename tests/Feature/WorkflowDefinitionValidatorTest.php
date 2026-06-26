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

use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowDefinitionValidatorTest extends TestCase
{
    private WorkflowDefinitionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new WorkflowDefinitionValidator;
    }

    #[Test]
    public function validator_accepts_minimal_valid_definition(): void
    {
        $result = $this->validator->validate($this->minimalValidDefinition());

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->errors());
    }

    #[Test]
    public function validator_rejects_invalid_definition_layout(): void
    {
        $definition = $this->minimalValidDefinition();
        unset($definition['key']);

        $result = $this->validator->validate($definition);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalValidDefinition(): array
    {
        return [
            'key' => 'package_test_workflow',
            'name' => 'Package Test Workflow',
            'nodes' => [
                [
                    'key' => 'start',
                    'type' => 'start',
                    'name' => 'Start',
                ],
                [
                    'key' => 'review',
                    'type' => 'approval',
                    'name' => 'Review',
                    'config' => [
                        'approval_mode' => 'any',
                        'assignees' => [
                            'type' => 'user',
                            'value' => '1',
                        ],
                    ],
                ],
                [
                    'key' => 'end',
                    'type' => 'end',
                    'name' => 'End',
                ],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'end'],
            ],
        ];
    }
}
