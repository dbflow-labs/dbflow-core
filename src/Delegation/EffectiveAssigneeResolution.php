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

namespace DbflowLabs\Core\Delegation;

use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Models\WorkflowDelegation;

final class EffectiveAssigneeResolution
{
    public function __construct(
        private readonly string $originalUserId,
        private readonly string $effectiveUserId,
        private readonly AssignmentSource $source,
        private readonly ?WorkflowDelegation $delegation = null,
    ) {}

    public function originalUserId(): string
    {
        return $this->originalUserId;
    }

    public function effectiveUserId(): string
    {
        return $this->effectiveUserId;
    }

    public function source(): AssignmentSource
    {
        return $this->source;
    }

    public function delegation(): ?WorkflowDelegation
    {
        return $this->delegation;
    }

    public function delegationId(): ?int
    {
        return $this->delegation?->getKey();
    }

    /**
     * @return array{
     *     original_user_id: string,
     *     effective_user_id: string,
     *     source: string,
     *     delegation_id: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'original_user_id' => $this->originalUserId,
            'effective_user_id' => $this->effectiveUserId,
            'source' => $this->source->value,
            'delegation_id' => $this->delegationId(),
        ];
    }
}
