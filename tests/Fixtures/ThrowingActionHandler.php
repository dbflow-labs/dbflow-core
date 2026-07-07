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
use RuntimeException;

final class ThrowingActionHandler implements ActionHandler
{
    public static int $callCount = 0;

    public function execute(ActionNode $node, WorkflowInstance $instance): void
    {
        self::$callCount++;

        throw new RuntimeException('Simulated action handler failure.');
    }

    public static function reset(): void
    {
        self::$callCount = 0;
    }
}
