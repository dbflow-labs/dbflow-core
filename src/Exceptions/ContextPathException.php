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

use RuntimeException;

final class ContextPathException extends RuntimeException
{
    public const CODE_INVALID = 'path_invalid';

    public const CODE_MISSING = 'path_missing';

    public const CODE_SENSITIVE = 'path_sensitive';

    public const CODE_PROHIBITED = 'path_prohibited';

    public function __construct(
        private readonly string $path,
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
