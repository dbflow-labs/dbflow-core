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

final class ArrayDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(
        private readonly array $records = [],
    ) {}

    public function resolve(string $host): array
    {
        $host = strtolower($host);

        return $this->records[$host] ?? [];
    }
}
