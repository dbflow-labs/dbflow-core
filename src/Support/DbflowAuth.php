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

use DbflowLabs\Core\Contracts\UserResolver;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Static proxy for DBFlow user identity.
 * All methods delegate to the container-bound UserResolver to avoid hard-coding host model classes.
 */
final class DbflowAuth
{
    /**
     * Returns the configured host user model FQCN.
     *
     * @return class-string<Authenticatable>
     */
    public static function userModelClass(): string
    {
        return app(UserResolver::class)->modelClass();
    }

    /**
     * Find a user by primary key; returns null when not found.
     * Accepts int, string, or null; returns null for null, zero, or empty string.
     */
    public static function findUser(mixed $id): ?Authenticatable
    {
        if ($id === null || $id === 0 || $id === '') {
            return null;
        }

        return app(UserResolver::class)->find($id);
    }

    /**
     * Returns the authenticated user, or null when not logged in.
     */
    public static function currentUser(?string $guard = null): ?Authenticatable
    {
        return app(UserResolver::class)->current($guard);
    }

    /**
     * Whether the given value is an instance of the configured host user model.
     */
    public static function isUserInstance(mixed $value): bool
    {
        return $value instanceof Authenticatable;
    }
}
