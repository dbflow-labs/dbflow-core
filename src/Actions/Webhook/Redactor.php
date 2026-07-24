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

final class Redactor
{
    /**
     * @param  list<string>  $extraKeys
     */
    public function __construct(
        private readonly array $extraKeys = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if ($this->shouldRedactKey((string) $key)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    public function truncate(string $value, ?int $maxLength = null): string
    {
        $maxLength ??= (int) config('dbflow.webhook.max_response_body_length', 4096);

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength).'...[truncated]';
    }

    private function shouldRedactKey(string $key): bool
    {
        $normalized = strtolower($key);
        $defaults = config('dbflow.webhook.redacted_header_keys', [
            'authorization',
            'cookie',
            'set-cookie',
            'token',
            'secret',
            'password',
            'api_key',
            'access_key',
            'client_secret',
        ]);

        $keys = array_merge($defaults, $this->extraKeys);

        foreach ($keys as $candidate) {
            if ($normalized === strtolower((string) $candidate)) {
                return true;
            }
        }

        return false;
    }
}
