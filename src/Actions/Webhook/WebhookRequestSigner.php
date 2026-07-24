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

/**
 * HMAC-SHA256 request signer.
 *
 * Canonical payload (UTF-8, LF-separated):
 *
 *   {timestamp}\n
 *   {idempotency_key}\n
 *   {METHOD}\n
 *   {path_and_query}\n
 *   {sha256_hex_of_raw_body}
 *
 * Signature = hex(HMAC-SHA256(canonical_payload, secret))
 *
 * Verification:
 * 1. Read X-DBFlow-Timestamp, X-DBFlow-Idempotency-Key, X-DBFlow-Signature.
 * 2. Rebuild the canonical payload from the received request.
 * 3. Compute HMAC-SHA256 with the shared secret.
 * 4. Compare signatures with hash_equals().
 * 5. Optionally reject stale timestamps using host policy.
 */
final class WebhookRequestSigner
{
    /**
     * @return array{timestamp: string, signature: string, canonical_payload: string, body_sha256: string}
     */
    public function sign(
        string $idempotencyKey,
        string $timestamp,
        string $method,
        string $url,
        string $body,
        string $secret,
    ): array {
        if ($secret === '') {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }

        $bodySha256 = hash('sha256', $body);
        $canonical = $this->canonicalPayload($timestamp, $idempotencyKey, $method, $url, $bodySha256);
        $signature = hash_hmac('sha256', $canonical, $secret);

        return [
            'timestamp' => $timestamp,
            'signature' => $signature,
            'canonical_payload' => $canonical,
            'body_sha256' => $bodySha256,
        ];
    }

    public function canonicalPayload(
        string $timestamp,
        string $idempotencyKey,
        string $method,
        string $url,
        string $bodySha256,
    ): string {
        return implode("\n", [
            $timestamp,
            $idempotencyKey,
            strtoupper($method),
            $this->normalizePathAndQuery($url),
            $bodySha256,
        ]);
    }

    public function normalizePathAndQuery(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Webhook URL is invalid for signing.');
        }

        $path = $parts['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            return $path.'?'.$parts['query'];
        }

        return $path;
    }
}
