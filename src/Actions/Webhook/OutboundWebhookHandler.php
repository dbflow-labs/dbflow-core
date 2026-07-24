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

use DbflowLabs\Core\Contracts\Actions\ReliableActionContext;
use DbflowLabs\Core\Contracts\Actions\ReliableActionHandler;
use DbflowLabs\Core\Contracts\Actions\ReliableActionResult;
use DbflowLabs\Core\Contracts\Actions\WorkflowSecretResolver;
use DbflowLabs\Core\Events\WebhookRequestDispatched;
use DbflowLabs\Core\Events\WebhookRequestFailed;
use DbflowLabs\Core\Events\WebhookResponseReceived;
use DbflowLabs\Core\Events\WebhookSecurityPolicyRejected;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use Throwable;

final class OutboundWebhookHandler implements ReliableActionHandler
{
    private const ALLOWED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @var array<string, string>
     */
    private array $activeSecrets = [];

    public function __construct(
        private readonly SafeTemplateRenderer $templateRenderer,
        private readonly Redactor $redactor,
        private readonly SsrfGuard $ssrfGuard,
        private readonly WebhookHeaderValidator $headerValidator,
        private readonly WebhookRequestSigner $requestSigner,
        private readonly WebhookRedirectPolicy $redirectPolicy,
        private readonly WebhookHttpTransport $httpTransport,
        private readonly Clock $clock = new SystemClock,
        private readonly ?WorkflowSecretResolver $secretResolver = null,
    ) {}

    public function handle(ReliableActionContext $context): ReliableActionResult
    {
        $payload = $context->payloadSnapshot;
        $urlTemplate = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : '';

        if ($urlTemplate === '') {
            return ReliableActionResult::failed('Webhook URL is required.');
        }

        $method = isset($payload['method']) && is_string($payload['method'])
            ? strtoupper($payload['method'])
            : 'POST';

        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            return ReliableActionResult::failed("Webhook method [{$method}] is not allowed.");
        }

        $headers = isset($payload['headers']) && is_array($payload['headers']) ? $payload['headers'] : [];
        $bodyTemplate = isset($payload['body']) && is_string($payload['body']) ? $payload['body'] : '';

        $model = $this->resolveModelData($context);
        $contextVariables = $context->variables;
        $idempotencyKey = $context->logicalExecutionKey;
        $idempotencyHeader = (string) config('dbflow.webhook.idempotency_header', 'X-DBFlow-Idempotency-Key');
        $timestampHeader = (string) config('dbflow.webhook.timestamp_header', 'X-DBFlow-Timestamp');
        $signatureHeader = (string) config('dbflow.webhook.signature_header', 'X-DBFlow-Signature');

        try {
            $this->activeSecrets = $this->resolveSecrets($payload);
            $secrets = $this->activeSecrets;

            $url = $this->templateRenderer->render(
                $urlTemplate,
                $model,
                $contextVariables,
                (int) $context->instance->getKey(),
                $secrets,
            );

            $this->ssrfGuard->assertAllowedUrl($url);

            $body = $bodyTemplate !== ''
                ? $this->templateRenderer->render(
                    $bodyTemplate,
                    $model,
                    $contextVariables,
                    (int) $context->instance->getKey(),
                    $secrets,
                )
                : '';

            $this->assertRequestBodyWithinLimit($body);

            $secretDerived = [];
            $renderedHeaders = [];

            foreach ($headers as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }

                $rawValue = is_string($value) ? $value : (string) $value;
                $usesSecret = WebhookSecretReference::containsSecretToken($rawValue);

                $renderedValue = is_string($value)
                    ? $this->templateRenderer->render(
                        $value,
                        $model,
                        $contextVariables,
                        (int) $context->instance->getKey(),
                        $secrets,
                    )
                    : (string) $value;

                if ($usesSecret) {
                    $secretDerived[$this->headerValidator->normalizeName($key)] = true;
                }

                $renderedHeaders[$key] = $renderedValue;
            }

            $renderedHeaders = $this->headerValidator->validateCustomHeaders($renderedHeaders, $secretDerived);

            // Transport headers are applied after custom validation and cannot be overridden.
            $renderedHeaders[$idempotencyHeader] = $idempotencyKey;

            $signingSecret = $this->resolveSigningSecret($payload);
            $timeout = (int) config('dbflow.webhook.timeout_seconds', 30);

            $response = $this->sendWithRedirectPolicy(
                $method,
                $url,
                $renderedHeaders,
                $body,
                $idempotencyKey,
                $idempotencyHeader,
                $timestampHeader,
                $signatureHeader,
                $signingSecret,
                $timeout,
                $context,
            );

            $statusCode = $response->status;
            $responseBody = $this->safeTruncate($response->body);
            $responseMetadata = $this->safeRedact([
                'status_code' => $statusCode,
                'body' => $responseBody,
            ], $this->activeSecrets);

            if ($this->isExpectedStatus($statusCode, $payload)) {
                event(new WebhookResponseReceived($context->execution, $statusCode, $responseMetadata));

                return ReliableActionResult::successful($responseMetadata, $statusCode);
            }

            event(new WebhookRequestFailed($context->execution, "HTTP {$statusCode}", $statusCode));

            if ($this->isRetryableStatus($statusCode)) {
                return ReliableActionResult::retryable("Webhook returned HTTP {$statusCode}.", $responseMetadata, $statusCode);
            }

            return ReliableActionResult::failed("Webhook returned HTTP {$statusCode}.", $responseMetadata, $statusCode);
        } catch (InvalidArgumentException $exception) {
            event(new WebhookSecurityPolicyRejected($context->execution, $this->sanitizeExceptionMessage($exception->getMessage(), $secrets ?? [])));

            return ReliableActionResult::failed($this->sanitizeExceptionMessage($exception->getMessage(), $secrets ?? []));
        } catch (ConnectionException $exception) {
            event(new WebhookRequestFailed($context->execution, $this->sanitizeExceptionMessage($exception->getMessage(), $secrets ?? [])));

            return ReliableActionResult::retryable($this->sanitizeExceptionMessage($exception->getMessage(), $secrets ?? []));
        } catch (Throwable $throwable) {
            event(new WebhookRequestFailed($context->execution, $this->sanitizeExceptionMessage($throwable->getMessage(), $secrets ?? [])));

            return ReliableActionResult::retryable($this->sanitizeExceptionMessage($throwable->getMessage(), $secrets ?? []));
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function sendWithRedirectPolicy(
        string $method,
        string $url,
        array $headers,
        string $body,
        string $idempotencyKey,
        string $idempotencyHeader,
        string $timestampHeader,
        string $signatureHeader,
        ?string $signingSecret,
        int $timeout,
        ReliableActionContext $context,
    ): WebhookHttpResponse {
        $currentUrl = $url;
        $currentHeaders = $headers;
        $redirectCount = 0;

        while (true) {
            $requestHeaders = $this->applyTransportHeaders(
                $currentHeaders,
                $currentUrl,
                $method,
                $body,
                $idempotencyKey,
                $idempotencyHeader,
                $timestampHeader,
                $signatureHeader,
                $signingSecret,
            );

            $requestMetadata = $this->safeRedact([
                'method' => $method,
                'url' => $currentUrl,
                'headers' => $requestHeaders,
                'body' => $this->safeTruncate($body),
                'idempotency_key' => $idempotencyKey,
            ], $this->activeSecrets);

            event(new WebhookRequestDispatched($context->execution, $requestMetadata));

            $response = $this->httpTransport->send($method, $currentUrl, $requestHeaders, $body, $timeout);

            if (! $response->isRedirect()) {
                return $response;
            }

            // Redirects disabled: return the redirect response as a normal non-success result.
            // Do not follow and do not treat the remote 3xx itself as a security-policy exception.
            if (! $this->redirectPolicy->followRedirectsEnabled()) {
                return $response;
            }

            $location = $response->locationHeader();

            if ($location === null || $location === '') {
                throw new InvalidArgumentException('Webhook redirect Location header is missing.');
            }

            $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
            $redirectCount++;
            $this->redirectPolicy->assertRedirectAllowed($currentUrl, $nextUrl, $redirectCount);

            $currentHeaders = $this->redirectPolicy->headersForRedirect($currentUrl, $nextUrl, $currentHeaders);
            // Idempotency key must remain on every hop; signatures are regenerated per request.
            $currentHeaders[$idempotencyHeader] = $idempotencyKey;
            $currentUrl = $nextUrl;
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function applyTransportHeaders(
        array $headers,
        string $url,
        string $method,
        string $body,
        string $idempotencyKey,
        string $idempotencyHeader,
        string $timestampHeader,
        string $signatureHeader,
        ?string $signingSecret,
    ): array {
        $headers[$idempotencyHeader] = $idempotencyKey;

        unset($headers[$timestampHeader], $headers[$signatureHeader]);

        if ($signingSecret !== null) {
            $timestamp = (string) $this->clock->unixTimestamp();
            $signed = $this->requestSigner->sign($idempotencyKey, $timestamp, $method, $url, $body, $signingSecret);
            $headers[$timestampHeader] = $signed['timestamp'];
            $headers[$signatureHeader] = $signed['signature'];
        }

        return $headers;
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        $location = trim($location);

        if ($location === '') {
            throw new InvalidArgumentException('Webhook redirect Location header is empty.');
        }

        // Absolute URL.
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($currentUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Webhook redirect base URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        $origin = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        // Protocol-relative URL: //host/path
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = $parts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        if ($directory === '' || $directory === '.') {
            $directory = '';
        }

        return $origin.$directory.'/'.$location;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isExpectedStatus(int $statusCode, array $payload): bool
    {
        $expected = $payload['expected_status'] ?? [200, 201, 202, 204];

        if (! is_array($expected)) {
            $expected = [$expected];
        }

        $normalized = [];

        foreach ($expected as $value) {
            if (is_int($value)) {
                $normalized[] = $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $normalized[] = (int) $value;
            }
        }

        if ($normalized === []) {
            $normalized = [200, 201, 202, 204];
        }

        return in_array($statusCode, $normalized, true);
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode === 408
            || $statusCode === 429
            || $statusCode >= 500;
    }

    private function assertRequestBodyWithinLimit(string $body): void
    {
        $max = (int) config('dbflow.webhook.max_request_body_length', 65536);

        if (strlen($body) > $max) {
            throw new InvalidArgumentException("Webhook request body exceeds the maximum length of {$max} bytes.");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSigningSecret(array $payload): ?string
    {
        $signingSecretKey = isset($payload['signing_secret_key']) && is_string($payload['signing_secret_key'])
            ? $payload['signing_secret_key']
            : null;

        if ($signingSecretKey === null || $signingSecretKey === '') {
            return null;
        }

        if ($this->secretResolver === null) {
            throw new InvalidArgumentException('Webhook signing requires a bound WorkflowSecretResolver.');
        }

        $secret = $this->secretResolver->resolve(WebhookSecretReference::normalize($signingSecretKey));

        if ($secret === null || $secret === '') {
            throw new InvalidArgumentException('Webhook signing secret is not available.');
        }

        return $secret;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveModelData(ReliableActionContext $context): array
    {
        $workflowable = $context->instance->workflowable;

        if ($workflowable === null) {
            return [];
        }

        if (method_exists($workflowable, 'getAttributes')) {
            return $workflowable->getAttributes();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function resolveSecrets(array $payload): array
    {
        $secretKeys = isset($payload['secret_keys']) && is_array($payload['secret_keys'])
            ? $payload['secret_keys']
            : [];

        $signingSecretKey = isset($payload['signing_secret_key']) && is_string($payload['signing_secret_key'])
            ? $payload['signing_secret_key']
            : null;

        if ($signingSecretKey !== null && $signingSecretKey !== '') {
            $secretKeys[] = $signingSecretKey;
        }

        $normalizedKeys = [];

        foreach ($secretKeys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $normalizedKeys[] = WebhookSecretReference::normalize($key);
        }

        $secretKeys = array_values(array_unique($normalizedKeys));

        if ($secretKeys === []) {
            return [];
        }

        if ($this->secretResolver === null) {
            throw new InvalidArgumentException('Webhook secret references require a bound WorkflowSecretResolver.');
        }

        $resolved = [];

        foreach ($secretKeys as $key) {
            $value = $this->secretResolver->resolve($key);

            if ($value === null || $value === '') {
                throw new InvalidArgumentException('A referenced webhook secret is not available.');
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $secrets
     * @return array<string, mixed>
     */
    private function safeRedact(array $data, array $secrets = []): array
    {
        try {
            $redacted = $this->redactor->redactArray($data);

            return $this->scrubSecretsFromMixed($redacted, $secrets);
        } catch (Throwable) {
            return ['redaction_failed' => true];
        }
    }

    /**
     * @param  array<string, string>  $secrets
     */
    private function scrubSecretsFromMixed(mixed $value, array $secrets): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeExceptionMessage($value, $secrets);
        }

        if (! is_array($value)) {
            return $value;
        }

        $scrubbed = [];

        foreach ($value as $key => $item) {
            $scrubbed[$key] = $this->scrubSecretsFromMixed($item, $secrets);
        }

        return $scrubbed;
    }

    private function safeTruncate(string $value): string
    {
        try {
            return $this->redactor->truncate($value);
        } catch (Throwable) {
            return '[REDACTION_FAILED]';
        }
    }

    /**
     * @param  array<string, string>  $secrets
     */
    private function sanitizeExceptionMessage(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        return $message;
    }
}
