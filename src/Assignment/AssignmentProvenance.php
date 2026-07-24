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
 * Future-facing provenance DTO. Not persisted in Stage 1.1-A.
 */
final class AssignmentProvenance
{
    public function __construct(
        private readonly AssignmentSource $source,
        private readonly int|string|null $originalAssigneeUserId = null,
        private readonly int|string|null $effectiveAssigneeUserId = null,
        private readonly ?int $reassignedFromAssignmentId = null,
        private readonly ?string $reason = null,
    ) {}

    public function source(): AssignmentSource
    {
        return $this->source;
    }

    public function originalAssigneeUserId(): int|string|null
    {
        return $this->originalAssigneeUserId;
    }

    public function effectiveAssigneeUserId(): int|string|null
    {
        return $this->effectiveAssigneeUserId;
    }

    public function reassignedFromAssignmentId(): ?int
    {
        return $this->reassignedFromAssignmentId;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array{
     *     source: string,
     *     original_assignee_user_id: int|string|null,
     *     effective_assignee_user_id: int|string|null,
     *     reassigned_from_assignment_id: int|null,
     *     reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'original_assignee_user_id' => $this->originalAssigneeUserId,
            'effective_assignee_user_id' => $this->effectiveAssigneeUserId,
            'reassigned_from_assignment_id' => $this->reassignedFromAssignmentId,
            'reason' => $this->reason,
        ];
    }
}
