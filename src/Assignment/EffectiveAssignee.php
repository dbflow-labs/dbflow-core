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

namespace DbflowLabs\Core\Assignment;

use DbflowLabs\Core\Enums\AssignmentSource;

/**
 * Effective assignee view for future reassignment/delegation consumers.
 * Stage 1.1-A defines the contract only.
 */
final class EffectiveAssignee
{
    public function __construct(
        private readonly int|string $userId,
        private readonly AssignmentSource $source = AssignmentSource::Direct,
        private readonly int|string|null $originalUserId = null,
    ) {}

    public function userId(): int|string
    {
        return $this->userId;
    }

    public function source(): AssignmentSource
    {
        return $this->source;
    }

    public function originalUserId(): int|string
    {
        return $this->originalUserId ?? $this->userId;
    }

    /**
     * @return array{user_id: int|string, source: string, original_user_id: int|string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'source' => $this->source->value,
            'original_user_id' => $this->originalUserId(),
        ];
    }
}
