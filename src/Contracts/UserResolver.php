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

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for resolving DBFlow user identity.
 * Hosts should override the default implementation via config('dbflow.auth.resolver'),
 * supporting any Eloquent model including UUID/ULID primary keys.
 */
interface UserResolver
{
    /**
     * Returns the configured host user model FQCN.
     *
     * @return class-string<Authenticatable>
     */
    public function modelClass(): string;

    /**
     * Returns the configured host user table name.
     */
    public function table(): string;

    /**
     * Find a user by primary key; returns null when not found.
     */
    public function find(mixed $id): ?Authenticatable;

    /**
     * Returns the authenticated user, or null when not logged in.
     *
     * @param  string|null  $guard  Auth guard name; null uses the default guard
     */
    public function current(?string $guard = null): ?Authenticatable;
}
