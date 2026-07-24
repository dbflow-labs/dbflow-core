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

namespace DbflowLabs\Core\Actions\Delegation;

use DbflowLabs\Core\Delegation\DelegationCycleDetector;
use DbflowLabs\Core\Events\DelegationCreated;
use DbflowLabs\Core\Exceptions\InvalidDelegationException;
use DbflowLabs\Core\Models\WorkflowDelegation;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonInterface;

final class CreateDelegation
{
    use ResolvesActorUserId;

    public function __construct(
        private readonly DelegationCycleDetector $cycleDetector,
    ) {}

    public function handle(
        mixed $delegator,
        mixed $delegate,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
        mixed $createdBy = null,
        ?string $workflowKey = null,
        ?string $nodeKey = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): WorkflowDelegation {
        $delegatorId = $this->requireUserId($delegator, 'Delegator');
        $delegateId = $this->requireUserId($delegate, 'Delegate');

        if ($delegatorId === $delegateId) {
            throw new InvalidDelegationException('Delegator and delegate must be different.');
        }

        if ((bool) config('dbflow.delegation.require_reason', false) && ($reason === null || trim($reason) === '')) {
            throw new InvalidDelegationException('Delegation reason is required.');
        }

        $creatorId = $this->resolveActorUserId($createdBy ?? $delegator);

        if ($creatorId === null) {
            throw new InvalidDelegationException('Creator user id is invalid.');
        }

        $selfService = (bool) config('dbflow.delegation.allow_self_service', true);
        $adminForOthers = (bool) config('dbflow.delegation.allow_admin_create_for_others', true);

        if ($creatorId === $delegatorId && ! $selfService) {
            throw new InvalidDelegationException('Self-service delegation is disabled.');
        }

        if ($creatorId !== $delegatorId && ! $adminForOthers) {
            throw new InvalidDelegationException('Creating delegation for another actor is disabled.');
        }

        $start = $this->normalizeUtc($startsAt);
        $end = $this->normalizeUtc($endsAt);

        if (! $start->lt($end)) {
            throw new InvalidDelegationException('Delegation starts_at must be before ends_at.');
        }

        $maxDays = (int) config('dbflow.delegation.max_duration_days', 365);

        if ($start->diffInDays($end) > $maxDays) {
            throw new InvalidDelegationException(
                "Delegation duration exceeds the configured maximum of {$maxDays} days.",
            );
        }

        $workflowKey = $this->normalizeKey($workflowKey);
        $nodeKey = $this->normalizeKey($nodeKey);

        if ($nodeKey !== null && $workflowKey === null) {
            throw new InvalidDelegationException('node_key requires workflow_key.');
        }

        if ($workflowKey !== null && ! preg_match(WorkflowDefinitionSchema::KEY_PATTERN, $workflowKey)) {
            throw new InvalidDelegationException('workflow_key format is invalid.');
        }

        if ($nodeKey !== null && ! preg_match(WorkflowDefinitionSchema::KEY_PATTERN, $nodeKey)) {
            throw new InvalidDelegationException('node_key format is invalid.');
        }

        // Retry on InnoDB deadlocks from concurrent overlapping creates (empty prior lock set
        // or cycle-scan lock ordering). After a peer commits, the loser should observe overlap.
        return DB::transaction(function () use (
            $delegatorId,
            $delegateId,
            $creatorId,
            $start,
            $end,
            $workflowKey,
            $nodeKey,
            $reason,
            $metadata,
        ): WorkflowDelegation {
            $existing = WorkflowDelegation::query()
                ->where('delegator_user_id', $delegatorId)
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($existing as $rule) {
                if (! $this->sameSpecificity($rule, $workflowKey, $nodeKey)) {
                    continue;
                }

                if ($start->lt($rule->effectiveEndsAt()) && $rule->starts_at->lt($end)) {
                    throw new InvalidDelegationException(
                        'An overlapping delegation already exists for the same scope.',
                    );
                }
            }

            $this->cycleDetector->assertNoCycle(
                $delegatorId,
                $delegateId,
                $start,
                $end,
                $workflowKey,
                $nodeKey,
                WorkflowDelegation::query()
                    ->whereNull('revoked_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(),
            );

            $delegation = WorkflowDelegation::query()->create([
                'delegator_user_id' => $delegatorId,
                'delegate_user_id' => $delegateId,
                'workflow_key' => $workflowKey,
                'node_key' => $nodeKey,
                'starts_at' => $start,
                'ends_at' => $end,
                'reason' => $reason,
                'created_by_user_id' => $creatorId,
                'metadata' => $metadata,
            ]);

            // Re-check after insert so concurrent creators cannot both commit overlapping rules
            // when no prior row existed to lockForUpdate.
            $overlappingPeers = WorkflowDelegation::query()
                ->where('delegator_user_id', $delegatorId)
                ->whereNull('revoked_at')
                ->where('id', '!=', $delegation->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($overlappingPeers as $rule) {
                if (! $this->sameSpecificity($rule, $workflowKey, $nodeKey)) {
                    continue;
                }

                if ($start->lt($rule->effectiveEndsAt()) && $rule->starts_at->lt($end)) {
                    throw new InvalidDelegationException(
                        'An overlapping delegation already exists for the same scope.',
                    );
                }
            }

            event(new DelegationCreated($delegation));

            return $delegation;
        }, 5);
    }

    private function requireUserId(mixed $actor, string $label): string
    {
        $id = $this->resolveActorUserId($actor);

        if ($id === null || trim((string) $id) === '') {
            throw new InvalidDelegationException("{$label} user id is invalid.");
        }

        return (string) $id;
    }

    private function normalizeUtc(CarbonInterface|string $value): CarbonInterface
    {
        return Carbon::parse($value)->utc();
    }

    private function normalizeKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    private function sameSpecificity(WorkflowDelegation $rule, ?string $workflowKey, ?string $nodeKey): bool
    {
        return $rule->workflow_key === $workflowKey && $rule->node_key === $nodeKey;
    }
}
