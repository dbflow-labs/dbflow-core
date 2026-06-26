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

namespace DbflowLabs\Core\Support;

final class WorkflowDefinitionDisplay
{
    public function workflowName(?string $workflowKey, ?string $fallbackName = null): string
    {
        if ($workflowKey !== null && $workflowKey !== '') {
            $translationKey = "dbflow.definitions.workflows.{$workflowKey}.name";
            $translated = __($translationKey);

            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        if ($fallbackName !== null && $fallbackName !== '') {
            return $fallbackName;
        }

        return "\u{2014}";
    }

    public function nodeLabel(?string $workflowKey, ?string $nodeKey, ?string $fallbackName = null): string
    {
        if ($workflowKey !== null && $workflowKey !== '' && $nodeKey !== null && $nodeKey !== '') {
            $translationKey = "dbflow.definitions.workflows.{$workflowKey}.nodes.{$nodeKey}";
            $translated = __($translationKey);

            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        if ($fallbackName !== null && $fallbackName !== '') {
            return $fallbackName;
        }

        if ($nodeKey !== null && $nodeKey !== '') {
            return $nodeKey;
        }

        return "\u{2014}";
    }
}
