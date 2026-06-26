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

namespace DbflowLabs\Core\Exceptions;

final class PremiumFeatureMissingException extends WorkflowException
{
    public function __construct(
        private readonly string $actionKey,
        private readonly string $nodeKey = '',
    ) {
        parent::__construct(
            "Action handler [{$actionKey}] is not registered in the open-core engine."
            .($nodeKey !== '' ? " Requested by action node [{$nodeKey}]." : '')
            .' Register a handler via ActionManager or install the premium extension package.',
        );
    }

    public function actionKey(): string
    {
        return $this->actionKey;
    }

    public function nodeKey(): string
    {
        return $this->nodeKey;
    }
}
