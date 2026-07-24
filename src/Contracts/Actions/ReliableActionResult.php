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

namespace DbflowLabs\Core\Contracts\Actions;

enum ReliableActionResultStatus: string
{
    case Successful = 'successful';
    case Retryable = 'retryable';
    case Failed = 'failed';
}

final class ReliableActionResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly ReliableActionResultStatus $status,
        public readonly ?string $message = null,
        public readonly array $metadata = [],
        public readonly ?int $responseStatus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function successful(array $metadata = [], ?int $responseStatus = null): self
    {
        return new self(ReliableActionResultStatus::Successful, metadata: $metadata, responseStatus: $responseStatus);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function retryable(string $message, array $metadata = [], ?int $responseStatus = null): self
    {
        return new self(ReliableActionResultStatus::Retryable, $message, $metadata, $responseStatus);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(string $message, array $metadata = [], ?int $responseStatus = null): self
    {
        return new self(ReliableActionResultStatus::Failed, $message, $metadata, $responseStatus);
    }

    public function isSuccessful(): bool
    {
        return $this->status === ReliableActionResultStatus::Successful;
    }

    public function isRetryable(): bool
    {
        return $this->status === ReliableActionResultStatus::Retryable;
    }
}
