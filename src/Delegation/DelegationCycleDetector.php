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
use DbflowLabs\Core\Enums\DelegationScope;
use DbflowLabs\Core\Exceptions\DelegationCycleException;
use DbflowLabs\Core\Models\WorkflowDelegation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DelegationCycleDetector
{
    public function __construct(
        private readonly int $maxDepth = 16,
    ) {}

    /**
     * @param  Collection<int, WorkflowDelegation>  $existing
     */
    public function assertNoCycle(
        string $delegatorUserId,
        string $delegateUserId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?string $workflowKey,
        ?string $nodeKey,
        Collection $existing,
    ): void {
        if ($delegatorUserId === $delegateUserId) {
            throw new DelegationCycleException('Self-delegation is not allowed.');
        }

        $candidateScope = $this->scope($workflowKey, $nodeKey);
        $graph = [];

        foreach ($existing as $rule) {
            if (! $this->intervalsOverlap($startsAt, $endsAt, $rule->starts_at, $rule->effectiveEndsAt())) {
                continue;
            }

            if (! $this->scopesCompatible($candidateScope, $workflowKey, $nodeKey, $rule)) {
                continue;
            }

            $graph[(string) $rule->delegator_user_id][] = (string) $rule->delegate_user_id;
        }

        $graph[$delegatorUserId][] = $delegateUserId;

        $visited = [];
        $stack = [];

        $this->dfs($delegateUserId, $delegatorUserId, $graph, $visited, $stack, 0);
    }

    /**
     * @param  array<string, list<string>>  $graph
     * @param  array<string, true>  $visited
     * @param  array<string, true>  $stack
     */
    private function dfs(
        string $node,
        string $target,
        array $graph,
        array &$visited,
        array &$stack,
        int $depth,
    ): void {
        if ($depth > $this->maxDepth) {
            throw new DelegationCycleException(
                'Delegation cycle detection exceeded the configured maximum depth.',
            );
        }

        if ($node === $target) {
            throw new DelegationCycleException('Delegation cycle detected.');
        }

        if (isset($stack[$node])) {
            return;
        }

        $stack[$node] = true;
        $visited[$node] = true;

        foreach ($graph[$node] ?? [] as $next) {
            $this->dfs($next, $target, $graph, $visited, $stack, $depth + 1);
        }

        unset($stack[$node]);
    }

    private function intervalsOverlap(
        CarbonInterface $aStart,
        CarbonInterface $aEnd,
        CarbonInterface $bStart,
        CarbonInterface $bEnd,
    ): bool {
        return $aStart->lt($bEnd) && $bStart->lt($aEnd);
    }

    private function scope(?string $workflowKey, ?string $nodeKey): DelegationScope
    {
        if ($workflowKey === null || $workflowKey === '') {
            return DelegationScope::Global;
        }

        if ($nodeKey === null || $nodeKey === '') {
            return DelegationScope::Workflow;
        }

        return DelegationScope::Node;
    }

    private function scopesCompatible(
        DelegationScope $candidateScope,
        ?string $workflowKey,
        ?string $nodeKey,
        WorkflowDelegation $rule,
    ): bool {
        $ruleScope = $rule->scopeType();

        // Compatible when either rule could apply to the same assignment context.
        if ($candidateScope === DelegationScope::Global || $ruleScope === DelegationScope::Global) {
            return true;
        }

        if ($candidateScope === DelegationScope::Workflow) {
            return $rule->workflow_key === $workflowKey;
        }

        if ($ruleScope === DelegationScope::Workflow) {
            return $rule->workflow_key === $workflowKey;
        }

        return $rule->workflow_key === $workflowKey && $rule->node_key === $nodeKey;
    }
}
