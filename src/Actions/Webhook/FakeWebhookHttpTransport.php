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

/**
 * Fake HTTP transport for tests. Queue responses in order.
 */
final class FakeWebhookHttpTransport implements WebhookHttpTransport
{
    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    public array $requests = [];

    /**
     * @param  list<WebhookHttpResponse|callable(string,string,array<string,string>,string): WebhookHttpResponse>  $responses
     */
    public function __construct(
        private array $responses = [],
    ) {}

    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds): WebhookHttpResponse
    {
        unset($timeoutSeconds);

        $this->requests[] = compact('method', 'url', 'headers', 'body');

        $next = array_shift($this->responses);

        if ($next === null) {
            return new WebhookHttpResponse(200, 'ok');
        }

        if (is_callable($next)) {
            return $next($method, $url, $headers, $body);
        }

        return $next;
    }
}
