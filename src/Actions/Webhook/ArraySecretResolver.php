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

namespace DbflowLabs\Core\Actions\Webhook;

use DbflowLabs\Core\Contracts\Actions\WorkflowSecretResolver;

final class ArraySecretResolver implements WorkflowSecretResolver
{
    /**
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        private readonly array $secrets = [],
    ) {}

    public function resolve(string $key): ?string
    {
        return $this->secrets[$key] ?? null;
    }
}
