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

use DbflowLabs\Core\Contracts\Actions\ReliableActionContext;
use DbflowLabs\Core\Contracts\Actions\ReliableActionHandler;
use DbflowLabs\Core\Contracts\Actions\ReliableActionResult;

final class ReliableRecordingActionHandler implements ReliableActionHandler
{
    public static int $callCount = 0;

    /**
     * @var list<array{execution_id: int|string|null, attempt: int, action_key: string}>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$callCount = 0;
        self::$calls = [];
    }

    public function handle(ReliableActionContext $context): ReliableActionResult
    {
        self::$callCount++;
        self::$calls[] = [
            'execution_id' => $context->execution->getKey(),
            'attempt' => $context->attemptNumber,
            'action_key' => $context->actionKey,
        ];

        $behavior = (string) ($context->payloadSnapshot['behavior'] ?? 'success');

        return match ($behavior) {
            'retryable' => ReliableActionResult::retryable('Simulated retryable failure.'),
            'failed' => ReliableActionResult::failed('Simulated permanent failure.'),
            default => ReliableActionResult::successful(['recorded' => true]),
        };
    }
}
