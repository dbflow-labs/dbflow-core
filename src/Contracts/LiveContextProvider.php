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

namespace DbflowLabs\Core\Contracts;

/**
 * Explicit live-context provider for future Stage 1.1-A+ definitions.
 * Stage 1.1-A validates the contract only; live refresh runtime is deferred.
 */
interface LiveContextProvider
{
    /**
     * @return array<string, mixed> Scalar/array/null values only; no Eloquent models or services.
     */
    public function resolveLiveContext(): array;
}
