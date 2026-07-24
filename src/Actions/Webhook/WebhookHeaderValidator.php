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

use InvalidArgumentException;

final class WebhookHeaderValidator
{
    public const PROTECTED_HEADERS = [
        'x-dbflow-idempotency-key',
        'x-dbflow-timestamp',
        'x-dbflow-signature',
    ];

    public const DISALLOWED_HEADERS = [
        'host',
        'content-length',
        'transfer-encoding',
        'connection',
        'proxy-connection',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-dbflow-idempotency-key',
        'x-dbflow-timestamp',
        'x-dbflow-signature',
    ];

    /**
     * Validate workflow-supplied custom headers before DBFlow transport headers are applied.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, true>  $secretDerivedHeaderNames lowercase names produced via secret templates
     * @return array<string, string>
     */
    public function validateCustomHeaders(array $headers, array $secretDerivedHeaderNames = []): array
    {
        $maxCount = (int) config('dbflow.webhook.max_header_count', 32);
        $maxLength = (int) config('dbflow.webhook.max_header_value_length', 8192);

        if (count($headers) > $maxCount) {
            throw new InvalidArgumentException("Webhook headers exceed the maximum count of {$maxCount}.");
        }

        $seen = [];
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('Webhook header names must be non-empty strings.');
            }

            if (! $this->isValidHeaderName($name)) {
                throw new InvalidArgumentException("Webhook header name [{$name}] is invalid.");
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException("Webhook header [{$name}] value must be a string.");
            }

            if ($this->containsCrlf($name) || $this->containsCrlf($value)) {
                throw new InvalidArgumentException('Webhook headers must not contain CR or LF characters.');
            }

            $normalizedName = $this->normalizeName($name);

            if (isset($seen[$normalizedName])) {
                throw new InvalidArgumentException("Duplicate webhook header [{$name}] is not allowed.");
            }

            $seen[$normalizedName] = true;

            if ($this->isDisallowedHeader($normalizedName)) {
                throw new InvalidArgumentException("Webhook header [{$name}] is not allowed.");
            }

            if ($normalizedName === 'authorization' && ! isset($secretDerivedHeaderNames[$normalizedName])) {
                throw new InvalidArgumentException('Authorization header must be produced from a secret reference.');
            }

            if (mb_strlen($value) > $maxLength) {
                throw new InvalidArgumentException("Webhook header [{$name}] exceeds the maximum value length.");
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * @deprecated Use validateCustomHeaders(); kept for transitional callers.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function validateAndNormalize(array $headers): array
    {
        return $this->validateCustomHeaders($headers);
    }

    public function normalizeName(string $name): string
    {
        return strtolower(trim($name));
    }

    private function isDisallowedHeader(string $normalizedName): bool
    {
        $disallowed = config('dbflow.webhook.disallowed_headers', self::DISALLOWED_HEADERS);

        if (! is_array($disallowed)) {
            $disallowed = self::DISALLOWED_HEADERS;
        }

        $disallowed = array_map(
            static fn (mixed $candidate): string => strtolower((string) $candidate),
            $disallowed,
        );

        if (in_array($normalizedName, $disallowed, true)) {
            return true;
        }

        return str_starts_with($normalizedName, 'proxy-');
    }

    private function isValidHeaderName(string $name): bool
    {
        return (bool) preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name);
    }

    private function containsCrlf(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n");
    }
}
