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

enum ContextNamespace: string
{
    case Model = 'model';
    case Starter = 'starter';
    case Actor = 'actor';
    case Context = 'context';
    case Workflow = 'workflow';
    case Task = 'task';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $namespace): string => $namespace->value,
            self::cases(),
        );
    }

    public function isSystemManaged(): bool
    {
        return $this === self::Workflow || $this === self::Task;
    }
}
