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

enum RuntimeCapability: string
{
    case ContextSchemaV11 = 'context_schema_v1_1';
    case LiveContext = 'live_context';
    case Delegation = 'delegation';
    case Sla = 'sla';
    case ReliableAction = 'reliable_action';
    case OutboundWebhook = 'outbound_webhook';
}
