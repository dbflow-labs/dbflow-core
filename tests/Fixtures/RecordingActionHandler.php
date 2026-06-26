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

namespace DbflowLabs\Core\Tests\Fixtures;

use DbflowLabs\Core\Contracts\ActionHandler;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Models\WorkflowInstance;

final class RecordingActionHandler implements ActionHandler
{
    public static int $callCount = 0;

    /**
     * @var list<array{instance_id: int|string|null, node_key: string, action_key: string, payload: array<string, mixed>|null}>
     */
    public static array $calls = [];

    public function execute(ActionNode $node, WorkflowInstance $instance): void
    {
        self::$callCount++;
        self::$calls[] = [
            'instance_id' => $instance->getKey(),
            'node_key' => $node->key(),
            'action_key' => $node->actionKey(),
            'payload' => $node->payload(),
        ];
    }

    public static function reset(): void
    {
        self::$callCount = 0;
        self::$calls = [];
    }
}
