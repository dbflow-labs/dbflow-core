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

use Illuminate\Support\Facades\Http;

final class LaravelWebhookHttpTransport implements WebhookHttpTransport
{
    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds): WebhookHttpResponse
    {
        // Redirect following is owned by WebhookRedirectPolicy, not the HTTP client.
        $response = Http::timeout($timeoutSeconds)
            ->withoutRedirecting()
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => false, 'cookies' => false])
            ->send($method, $url, $body !== '' ? ['body' => $body] : []);

        return new WebhookHttpResponse(
            $response->status(),
            $response->body(),
            $response->headers(),
        );
    }
}
