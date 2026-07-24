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

use DbflowLabs\Core\Enums\DelegationLifecycle;
use DbflowLabs\Core\Events\DelegationRevoked;
use DbflowLabs\Core\Exceptions\InvalidDelegationException;
use DbflowLabs\Core\Models\WorkflowDelegation;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RevokeDelegation
{
    use ResolvesActorUserId;

    public function handle(
        WorkflowDelegation $delegation,
        mixed $revokedBy = null,
        ?string $reason = null,
    ): WorkflowDelegation {
        return DB::transaction(function () use ($delegation, $revokedBy, $reason): WorkflowDelegation {
            /** @var WorkflowDelegation $locked */
            $locked = WorkflowDelegation::query()
                ->whereKey($delegation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $lifecycle = $locked->lifecycle(Carbon::now('UTC'));

            if ($lifecycle === DelegationLifecycle::Expired) {
                throw new InvalidDelegationException('Expired delegations cannot be revoked.');
            }

            $locked->forceFill([
                'revoked_at' => Carbon::now('UTC'),
                'revoked_by_user_id' => $this->resolveActorUserId($revokedBy),
                'revocation_reason' => $reason,
            ])->save();

            event(new DelegationRevoked($locked->refresh()));

            return $locked->refresh();
        });
    }
}
