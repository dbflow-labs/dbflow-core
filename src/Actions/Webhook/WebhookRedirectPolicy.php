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
 * Explicit outbound webhook redirect policy.
 * Redirects are disabled by default. When enabled, every target is revalidated.
 */
final class WebhookRedirectPolicy
{
    /**
     * Headers that must never be forwarded to a different host.
     */
    public const SENSITIVE_CROSS_HOST_HEADERS = [
        'authorization',
        'cookie',
        'proxy-authorization',
        'x-dbflow-signature',
        'x-dbflow-timestamp',
        'x-api-key',
        'api-key',
    ];

    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
        private readonly bool $followRedirects = false,
        private readonly int $maxRedirects = 3,
    ) {}

    public function followRedirectsEnabled(): bool
    {
        return $this->followRedirects;
    }

    public function maxRedirects(): int
    {
        return max(0, $this->maxRedirects);
    }

    public function assertRedirectAllowed(string $fromUrl, string $toUrl, int $redirectCount): void
    {
        if (! $this->followRedirects) {
            throw new InvalidArgumentException('Webhook redirects are disabled.');
        }

        if ($redirectCount > $this->maxRedirects()) {
            throw new InvalidArgumentException('Webhook redirect limit exceeded.');
        }

        $this->ssrfGuard->assertRedirectTarget($fromUrl, $toUrl);
    }

    /**
     * Strip sensitive headers when the redirect changes host.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function headersForRedirect(string $fromUrl, string $toUrl, array $headers): array
    {
        if ($this->sameHost($fromUrl, $toUrl)) {
            return $headers;
        }

        $filtered = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower($name);

            if (in_array($normalized, self::SENSITIVE_CROSS_HOST_HEADERS, true)) {
                continue;
            }

            if (str_starts_with($normalized, 'x-dbflow-signature')) {
                continue;
            }

            $filtered[$name] = $value;
        }

        return $filtered;
    }

    public function sameHost(string $fromUrl, string $toUrl): bool
    {
        $from = parse_url($fromUrl);
        $to = parse_url($toUrl);

        if (! is_array($from) || ! is_array($to)) {
            return false;
        }

        $fromHost = strtolower((string) ($from['host'] ?? ''));
        $toHost = strtolower((string) ($to['host'] ?? ''));
        $fromPort = (int) ($from['port'] ?? (($from['scheme'] ?? '') === 'https' ? 443 : 80));
        $toPort = (int) ($to['port'] ?? (($to['scheme'] ?? '') === 'https' ? 443 : 80));

        return $fromHost !== '' && $fromHost === $toHost && $fromPort === $toPort;
    }
}
