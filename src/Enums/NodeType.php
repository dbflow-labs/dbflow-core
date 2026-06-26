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

enum NodeType: string
{
    case Start = 'start';
    case Approval = 'approval';
    case Condition = 'condition';
    case Action = 'action';
    case End = 'end';

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Start',
            self::Approval => 'Approval',
            self::Condition => 'Condition',
            self::Action => 'Action',
            self::End => 'End',
        };
    }
}
