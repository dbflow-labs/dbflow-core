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

namespace DbflowLabs\Core\Contracts\Sla;

enum SlaHandlerResultStatus: string
{
    case Successful = 'successful';
    case Retryable = 'retryable';
    case Failed = 'failed';
}

final class SlaHandlerResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly SlaHandlerResultStatus $status,
        public readonly ?string $message = null,
        public readonly array $metadata = [],
    ) {}

    public static function successful(array $metadata = []): self
    {
        return new self(SlaHandlerResultStatus::Successful, metadata: $metadata);
    }

    public static function retryable(string $message, array $metadata = []): self
    {
        return new self(SlaHandlerResultStatus::Retryable, $message, $metadata);
    }

    public static function failed(string $message, array $metadata = []): self
    {
        return new self(SlaHandlerResultStatus::Failed, $message, $metadata);
    }

    public function isSuccessful(): bool
    {
        return $this->status === SlaHandlerResultStatus::Successful;
    }

    public function isRetryable(): bool
    {
        return $this->status === SlaHandlerResultStatus::Retryable;
    }
}

