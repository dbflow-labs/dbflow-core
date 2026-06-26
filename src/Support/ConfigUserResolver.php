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
use Illuminate\Database\Eloquent\Model;

/**
 * Default UserResolver backed by configuration.
 *
 * Model class resolution priority (three fallbacks):
 *   1. config('dbflow.auth.model')
 *   2. config('auth.providers.users.model')
 *   3. 'App\Models\User'
 */
final class ConfigUserResolver implements UserResolver
{
    /**
     * @return class-string<Authenticatable>
     */
    public function modelClass(): string
    {
        /** @var class-string<Authenticatable>|null $configured */
        $configured = config('dbflow.auth.model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        /** @var class-string<Authenticatable>|null $fromAuth */
        $fromAuth = config('auth.providers.users.model');

        if (is_string($fromAuth) && $fromAuth !== '') {
            return $fromAuth;
        }

        return 'App\\Models\\User';
    }

    public function find(mixed $id): ?Authenticatable
    {
        if ($id === null || $id === '' || $id === 0) {
            return null;
        }

        $class = $this->modelClass();

        // Uses a new model instance to obtain a query builder, supporting any primary key type (int/string/UUID).
        /** @var Model $model */
        $model = new $class;
        $result = $model->newQuery()->find($id);

        return $result instanceof Authenticatable ? $result : null;
    }

    public function current(?string $guard = null): ?Authenticatable
    {
        return auth($guard)->user();
    }
}
