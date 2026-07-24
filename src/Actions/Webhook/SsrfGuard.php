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

final class SsrfGuard
{
    /**
     * @param  list<string>  $allowedSchemes
     * @param  list<string>  $hostAllowlist
     */
    public function __construct(
        private readonly bool $denyPrivateIps = true,
        private readonly array $allowedSchemes = ['https', 'http'],
        private readonly DnsResolver $dnsResolver = new NativeDnsResolver,
        private readonly bool $requireHttps = false,
        private readonly array $hostAllowlist = [],
    ) {}

    public function assertAllowedUrl(string $url, ?string $previousScheme = null): void
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! is_string($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('Webhook URL is invalid.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URL must not contain embedded credentials.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme === '') {
            throw new InvalidArgumentException('Webhook URL scheme is required.');
        }

        if ($this->requireHttps && $scheme !== 'https') {
            throw new InvalidArgumentException('Webhook URL must use HTTPS.');
        }

        if (! in_array($scheme, $this->allowedSchemes, true)) {
            throw new InvalidArgumentException("Webhook URL scheme [{$scheme}] is not allowed.");
        }

        if ($previousScheme === 'https' && $scheme === 'http') {
            throw new InvalidArgumentException('Webhook redirect HTTPS-to-HTTP downgrade is not allowed.');
        }

        $host = strtolower($parts['host']);

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
            throw new InvalidArgumentException('Webhook URL host is not allowed.');
        }

        if ($this->hostAllowlist !== [] && ! in_array($host, array_map('strtolower', $this->hostAllowlist), true)) {
            throw new InvalidArgumentException("Webhook URL host [{$host}] is not in the allowlist.");
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertIpAllowed($host);

            return;
        }

        $ips = $this->dnsResolver->resolve($host);

        if ($ips === []) {
            throw new InvalidArgumentException('Webhook URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            $this->assertIpAllowed($ip);
        }
    }

    public function assertRedirectTarget(string $fromUrl, string $toUrl): void
    {
        $fromParts = parse_url($fromUrl);
        $fromScheme = is_array($fromParts) ? strtolower((string) ($fromParts['scheme'] ?? '')) : null;

        $this->assertAllowedUrl($toUrl, $fromScheme);
    }

    private function assertIpAllowed(string $ip): void
    {
        if ($this->isMetadataIp($ip)) {
            throw new InvalidArgumentException('Webhook URL resolves to a cloud metadata address.');
        }

        if (! $this->denyPrivateIps) {
            return;
        }

        // Host allowlists never override private/reserved IP denial unless deny_private_ips is false.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidArgumentException('Webhook URL resolves to a private or reserved IP address.');
        }
    }

    private function isMetadataIp(string $ip): bool
    {
        return $ip === '169.254.169.254'
            || $ip === '169.254.170.2'
            || str_starts_with($ip, 'fd00:ec2::')
            || $ip === 'fd00:ec2::254';
    }
}
