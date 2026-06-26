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

trait ResolvesActorUserId
{
    /**
     * Resolve a user primary key from any actor value; supports int and string (UUID/ULID).
     * Returns null when no valid actor is present.
     */
    protected function resolveActorUserId(mixed $actor): int|string|null
    {
        if ($actor === null) {
            return null;
        }

        if (is_int($actor)) {
            return $actor > 0 ? $actor : null;
        }

        if (is_string($actor) && $actor !== '') {
            return $actor;
        }

        if (! is_object($actor)) {
            return null;
        }

        if (method_exists($actor, 'getKey')) {
            $key = $actor->getKey();

            if (is_int($key)) {
                return $key > 0 ? $key : null;
            }

            if (is_string($key) && $key !== '') {
                return $key;
            }

            return null;
        }

        if (isset($actor->id)) {
            $id = $actor->id;

            if (is_int($id)) {
                return $id > 0 ? $id : null;
            }

            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }
}
