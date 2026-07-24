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

enum DelegationScope: string
{
    case Global = 'global';
    case Workflow = 'workflow';
    case Node = 'node';

    public function precedence(): int
    {
        return match ($this) {
            self::Node => 3,
            self::Workflow => 2,
            self::Global => 1,
        };
    }
}
