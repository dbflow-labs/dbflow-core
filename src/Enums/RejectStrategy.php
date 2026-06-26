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

enum RejectStrategy: string
{
    case Starter = 'starter';
    case PreviousNode = 'previous_node';
    case SpecificNode = 'specific_node';
    case End = 'end';

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Return to initiator',
            self::PreviousNode => 'Return to previous node',
            self::SpecificNode => 'Return to specific node',
            self::End => 'End workflow',
        };
    }
}
