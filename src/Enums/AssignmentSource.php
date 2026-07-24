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

namespace DbflowLabs\Core\Enums;

/**
 * Frozen assignment provenance terminology for Stage 1.1-B+.
 * Persistence is intentionally deferred.
 */
enum AssignmentSource: string
{
    case Direct = 'direct';
    case Reassignment = 'reassignment';
    case Delegation = 'delegation';
    case Escalation = 'escalation';
}
