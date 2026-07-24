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

/**
 * Resolves hostnames to IP addresses for SSRF checks.
 * Production uses DNS; tests inject a fake resolver.
 */
interface DnsResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
