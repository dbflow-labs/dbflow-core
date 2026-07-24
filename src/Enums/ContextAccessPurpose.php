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

enum ContextAccessPurpose: string
{
    case Condition = 'condition';
    case ActionTemplate = 'action_template';
    case AuditDisplay = 'audit_display';
    case UiPreview = 'ui_preview';

    public function allowsSensitiveValues(): bool
    {
        return match ($this) {
            self::Condition, self::ActionTemplate => true,
            self::AuditDisplay, self::UiPreview => false,
        };
    }
}
