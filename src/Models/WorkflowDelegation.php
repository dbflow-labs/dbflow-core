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

namespace DbflowLabs\Core\Models;

use DbflowLabs\Core\Enums\DelegationLifecycle;
use DbflowLabs\Core\Enums\DelegationScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class WorkflowDelegation extends Model
{
    protected $table = 'dbflow_workflow_delegations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'delegator_user_id',
        'delegate_user_id',
        'workflow_key',
        'node_key',
        'starts_at',
        'ends_at',
        'reason',
        'created_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'delegator_user_id' => 'string',
            'delegate_user_id' => 'string',
            'created_by_user_id' => 'string',
            'revoked_by_user_id' => 'string',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeType(): DelegationScope
    {
        if ($this->workflow_key === null || $this->workflow_key === '') {
            return DelegationScope::Global;
        }

        if ($this->node_key === null || $this->node_key === '') {
            return DelegationScope::Workflow;
        }

        return DelegationScope::Node;
    }

    public function lifecycle(?CarbonInterface $at = null): DelegationLifecycle
    {
        $moment = $at?->copy()->utc() ?? Carbon::now('UTC');

        if ($this->revoked_at !== null) {
            return DelegationLifecycle::Revoked;
        }

        if ($moment->lt($this->starts_at)) {
            return DelegationLifecycle::Scheduled;
        }

        if ($moment->gte($this->ends_at)) {
            return DelegationLifecycle::Expired;
        }

        return DelegationLifecycle::Active;
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        return $this->lifecycle($at) === DelegationLifecycle::Active;
    }

    public function matchesScope(?string $workflowKey, ?string $nodeKey): bool
    {
        return match ($this->scopeType()) {
            DelegationScope::Global => true,
            DelegationScope::Workflow => $workflowKey !== null && $workflowKey === $this->workflow_key,
            DelegationScope::Node => $workflowKey === $this->workflow_key && $nodeKey === $this->node_key,
        };
    }

    public function effectiveEndsAt(): CarbonInterface
    {
        if ($this->revoked_at !== null && $this->revoked_at->lt($this->ends_at)) {
            return $this->revoked_at;
        }

        return $this->ends_at;
    }
}
