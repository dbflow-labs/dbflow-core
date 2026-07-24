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

use Carbon\CarbonInterface;
use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Enums\DelegationLifecycle;
use DbflowLabs\Core\Enums\DelegationScope;
use DbflowLabs\Core\Exceptions\AmbiguousDelegationException;
use DbflowLabs\Core\Models\WorkflowDelegation;
use Illuminate\Support\Carbon;

/**
 * Direct-only effective assignee resolution (no multi-hop follow).
 */
final class ResolveEffectiveAssignee
{
    public function resolve(
        string $originalUserId,
        ?string $workflowKey,
        ?string $nodeKey,
        ?CarbonInterface $at = null,
        bool $delegationEnabled = true,
    ): EffectiveAssigneeResolution {
        $originalUserId = trim($originalUserId);

        if (! $delegationEnabled || $originalUserId === '') {
            return new EffectiveAssigneeResolution(
                $originalUserId,
                $originalUserId,
                AssignmentSource::Direct,
            );
        }

        $moment = ($at?->copy() ?? Carbon::now())->utc();

        $candidates = WorkflowDelegation::query()
            ->where('delegator_user_id', $originalUserId)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $moment)
            ->where('ends_at', '>', $moment)
            ->get()
            ->filter(fn (WorkflowDelegation $delegation): bool => $delegation->matchesScope($workflowKey, $nodeKey)
                && $delegation->lifecycle($moment) === DelegationLifecycle::Active)
            ->values();

        if ($candidates->isEmpty()) {
            return new EffectiveAssigneeResolution(
                $originalUserId,
                $originalUserId,
                AssignmentSource::Direct,
            );
        }

        $bestPrecedence = $candidates->max(
            static fn (WorkflowDelegation $delegation): int => $delegation->scopeType()->precedence(),
        );

        $top = $candidates
            ->filter(static fn (WorkflowDelegation $delegation): bool => $delegation->scopeType()->precedence() === $bestPrecedence)
            ->values();

        if ($top->count() > 1) {
            throw new AmbiguousDelegationException(
                'Multiple active delegations matched the same specificity for the original assignee.',
            );
        }

        /** @var WorkflowDelegation $match */
        $match = $top->first();

        return new EffectiveAssigneeResolution(
            $originalUserId,
            (string) $match->delegate_user_id,
            AssignmentSource::Delegation,
            $match,
        );
    }
}
