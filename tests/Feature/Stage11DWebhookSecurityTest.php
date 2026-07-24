<?php

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Actions\Webhook\ArrayDnsResolver;
use DbflowLabs\Core\Actions\Webhook\ArraySecretResolver;
use DbflowLabs\Core\Actions\Webhook\FakeWebhookHttpTransport;
use DbflowLabs\Core\Actions\Webhook\FixedClock;
use DbflowLabs\Core\Actions\Webhook\OutboundWebhookHandler;
use DbflowLabs\Core\Actions\Webhook\Redactor;
use DbflowLabs\Core\Actions\Webhook\SafeTemplateRenderer;
use DbflowLabs\Core\Actions\Webhook\SsrfGuard;
use DbflowLabs\Core\Actions\Webhook\WebhookHeaderValidator;
use DbflowLabs\Core\Actions\Webhook\WebhookHttpResponse;
use DbflowLabs\Core\Actions\Webhook\WebhookRedirectPolicy;
use DbflowLabs\Core\Actions\Webhook\WebhookRequestSigner;
use DbflowLabs\Core\Contracts\Actions\ReliableActionContext;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Events\WebhookRequestDispatched;
use DbflowLabs\Core\Events\WebhookRequestFailed;
use DbflowLabs\Core\Events\WebhookResponseReceived;
use DbflowLabs\Core\Events\WebhookSecurityPolicyRejected;
use DbflowLabs\Core\Models\WorkflowActionAttempt;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Support\WorkflowBuilderNodeFactory;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class Stage11DWebhookSecurityTest extends TestCase
{
    private const SENTINEL = 'SENTINEL_SECRET_VALUE_9f3a2c1b';

    #[Test]
    public function ssrf_guard_rejects_private_ip_targets(): void
    {
        Event::fake([WebhookSecurityPolicyRejected::class]);

        $handler = $this->makeHandler(transport: new FakeWebhookHttpTransport);
        $result = $handler->handle($this->makeContext([
            'url' => 'http://127.0.0.1/hook',
            'method' => 'POST',
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
        Event::assertDispatched(WebhookSecurityPolicyRejected::class);
    }

    #[Test]
    public function ssrf_guard_rejects_unsafe_dns_results(): void
    {
        $dns = new ArrayDnsResolver(['evil.example.test' => ['10.0.0.8']]);
        $handler = $this->makeHandler(dns: $dns, transport: new FakeWebhookHttpTransport);

        $result = $handler->handle($this->makeContext([
            'url' => 'https://evil.example.test/hook',
            'method' => 'POST',
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
    }

    #[Test]
    public function ssrf_guard_rejects_metadata_endpoint(): void
    {
        $handler = $this->makeHandler(transport: new FakeWebhookHttpTransport);

        $result = $handler->handle($this->makeContext([
            'url' => 'http://169.254.169.254/latest/meta-data',
            'method' => 'POST',
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
    }

    #[Test]
    public function template_renderer_rejects_disallowed_tokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SafeTemplateRenderer)->render('{{ evil.payload }}', [], [], 1);
    }

    #[Test]
    public function redactor_masks_sensitive_headers_and_truncates_body(): void
    {
        $redactor = new Redactor;
        $redacted = $redactor->redactArray([
            'authorization' => 'Bearer secret-token',
            'content-type' => 'application/json',
        ]);

        $this->assertSame('[REDACTED]', $redacted['authorization']);
        $this->assertSame('application/json', $redacted['content-type']);
        $this->assertStringEndsWith('...[truncated]', $redactor->truncate(str_repeat('x', 5000), 100));
    }

    #[Test]
    public function idempotency_header_is_stable_and_not_overridable(): void
    {
        $transport = new FakeWebhookHttpTransport([
            new WebhookHttpResponse(200, 'ok'),
            new WebhookHttpResponse(200, 'ok'),
        ]);
        $handler = $this->makeHandler(transport: $transport);
        $context = $this->makeContext([
            'url' => 'https://hooks.example.test/workflow',
            'method' => 'POST',
            'headers' => [
                'X-DBFlow-Idempotency-Key' => 'override-me',
            ],
            'body' => '{}',
        ]);

        $first = $handler->handle($context);
        $this->assertFalse($first->isSuccessful());

        $context = $this->makeContext([
            'url' => 'https://hooks.example.test/workflow',
            'method' => 'POST',
            'body' => '{}',
        ]);

        $this->assertTrue($handler->handle($context)->isSuccessful());
        $this->assertTrue($handler->handle($context)->isSuccessful());

        $this->assertCount(2, $transport->requests);
        $this->assertSame(
            $transport->requests[0]['headers']['X-DBFlow-Idempotency-Key'],
            $transport->requests[1]['headers']['X-DBFlow-Idempotency-Key'],
        );
        $this->assertSame($context->logicalExecutionKey, $transport->requests[0]['headers']['X-DBFlow-Idempotency-Key']);
    }

    #[Test]
    public function hmac_signature_is_deterministic_for_known_vector(): void
    {
        $signer = new WebhookRequestSigner;
        $signed = $signer->sign(
            'instance:1:node:webhook_action:visit:1',
            '1700000000',
            'POST',
            'https://hooks.example.test/path?q=1',
            '{"a":1}',
            'test-secret',
        );

        $expectedCanonical = implode("\n", [
            '1700000000',
            'instance:1:node:webhook_action:visit:1',
            'POST',
            '/path?q=1',
            hash('sha256', '{"a":1}'),
        ]);

        $this->assertSame($expectedCanonical, $signed['canonical_payload']);
        $this->assertSame(hash_hmac('sha256', $expectedCanonical, 'test-secret'), $signed['signature']);

        $transport = new FakeWebhookHttpTransport([new WebhookHttpResponse(200, 'ok')]);
        $handler = $this->makeHandler(
            secrets: ['signing' => 'test-secret'],
            transport: $transport,
            clock: new FixedClock(1700000000),
        );

        $context = $this->makeContext([
            'url' => 'https://hooks.example.test/path?q=1',
            'method' => 'POST',
            'body' => '{"a":1}',
            'signing_secret_key' => 'signing',
            'secret_keys' => ['signing'],
        ]);

        $this->assertTrue($handler->handle($context)->isSuccessful());
        $this->assertSame('1700000000', $transport->requests[0]['headers']['X-DBFlow-Timestamp']);
        $this->assertSame($signed['signature'], $transport->requests[0]['headers']['X-DBFlow-Signature']);
    }

    #[Test]
    public function signing_secret_reference_mustache_form_resolves_for_hmac(): void
    {
        $signer = new WebhookRequestSigner;
        $signed = $signer->sign(
            'instance:1:node:webhook_action:visit:1',
            '1700000000',
            'POST',
            'https://hooks.example.test/path?q=1',
            '{"a":1}',
            'test-secret',
        );

        $transport = new FakeWebhookHttpTransport([new WebhookHttpResponse(200, 'ok')]);
        $handler = $this->makeHandler(
            secrets: ['webhook_signing' => 'test-secret'],
            transport: $transport,
            clock: new FixedClock(1700000000),
        );

        $context = $this->makeContext([
            'url' => 'https://hooks.example.test/path?q=1',
            'method' => 'POST',
            'body' => '{"a":1}',
            'signing_secret_key' => '{{ secret.webhook_signing }}',
        ]);

        $this->assertTrue($handler->handle($context)->isSuccessful());
        $this->assertSame($signed['signature'], $transport->requests[0]['headers']['X-DBFlow-Signature']);
    }

    #[Test]
    public function compact_mustache_signing_secret_and_authorization_header_are_accepted(): void
    {
        $signer = new WebhookRequestSigner;
        $signed = $signer->sign(
            'instance:1:node:webhook_action:visit:1',
            '1700000000',
            'POST',
            'https://hooks.example.test/path',
            '{}',
            'test-secret',
        );

        $transport = new FakeWebhookHttpTransport([new WebhookHttpResponse(200, 'ok')]);
        $handler = $this->makeHandler(
            secrets: [
                'webhook_signing' => 'test-secret',
                'api_token' => 'Bearer '.self::SENTINEL,
            ],
            transport: $transport,
            clock: new FixedClock(1700000000),
        );

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/path',
            'method' => 'POST',
            'headers' => [
                'Authorization' => '{{secret.api_token}}',
            ],
            'body' => '{}',
            'secret_keys' => ['{{secret.api_token}}'],
            'signing_secret_key' => '{{secret.webhook_signing}}',
        ]));

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Bearer '.self::SENTINEL, $transport->requests[0]['headers']['Authorization']);
        $this->assertSame($signed['signature'], $transport->requests[0]['headers']['X-DBFlow-Signature']);
    }

    #[Test]
    public function mixed_plain_and_mustache_secret_keys_resolve_once(): void
    {
        $transport = new FakeWebhookHttpTransport([new WebhookHttpResponse(200, 'ok')]);
        $handler = $this->makeHandler(
            secrets: ['api_token' => self::SENTINEL],
            transport: $transport,
        );

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/workflow',
            'method' => 'POST',
            'headers' => [
                'X-Api-Key' => '{{ secret.api_token }}',
            ],
            'body' => '{}',
            'secret_keys' => ['api_token', '{{ secret.api_token }}'],
        ]));

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(self::SENTINEL, $transport->requests[0]['headers']['X-Api-Key']);
    }

    #[Test]
    public function hmac_signature_changes_when_body_or_method_changes(): void
    {
        $signer = new WebhookRequestSigner;
        $base = $signer->sign('key', '1', 'POST', 'https://example.test/a', 'body', 'secret');
        $bodyChanged = $signer->sign('key', '1', 'POST', 'https://example.test/a', 'body2', 'secret');
        $methodChanged = $signer->sign('key', '1', 'PUT', 'https://example.test/a', 'body', 'secret');
        $pathChanged = $signer->sign('key', '1', 'POST', 'https://example.test/b', 'body', 'secret');
        $wrongSecret = $signer->sign('key', '1', 'POST', 'https://example.test/a', 'body', 'other');

        $this->assertNotSame($base['signature'], $bodyChanged['signature']);
        $this->assertNotSame($base['signature'], $methodChanged['signature']);
        $this->assertNotSame($base['signature'], $pathChanged['signature']);
        $this->assertNotSame($base['signature'], $wrongSecret['signature']);
    }

    #[Test]
    public function redirects_are_disabled_by_default(): void
    {
        $transport = new FakeWebhookHttpTransport([
            new WebhookHttpResponse(302, '', ['Location' => 'https://hooks.example.test/next']),
        ]);
        $handler = $this->makeHandler(transport: $transport, followRedirects: false);

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/start',
            'method' => 'POST',
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(302, $result->responseStatus);
        $this->assertCount(1, $transport->requests);
    }

    #[Test]
    public function protocol_relative_redirect_is_revalidated_as_absolute_url(): void
    {
        $dns = new ArrayDnsResolver([
            'hooks.example.test' => ['203.0.113.10'],
        ]);
        $transport = new FakeWebhookHttpTransport([
            new WebhookHttpResponse(302, '', ['Location' => '//127.0.0.1/private']),
        ]);
        $handler = $this->makeHandler(dns: $dns, transport: $transport, followRedirects: true);

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/start',
            'method' => 'POST',
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
        $this->assertCount(1, $transport->requests);
    }

    #[Test]
    public function enabled_redirect_to_private_ip_is_rejected(): void
    {
        $transport = new FakeWebhookHttpTransport([
            new WebhookHttpResponse(302, '', ['Location' => 'https://10.0.0.5/next']),
        ]);
        $handler = $this->makeHandler(transport: $transport, followRedirects: true);

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/start',
            'method' => 'POST',
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
    }

    #[Test]
    public function enabled_redirect_https_downgrade_is_rejected(): void
    {
        $dns = new ArrayDnsResolver(['hooks.example.test' => ['203.0.113.10']]);
        $transport = new FakeWebhookHttpTransport([
            new WebhookHttpResponse(302, '', ['Location' => 'http://hooks.example.test/next']),
        ]);
        $handler = $this->makeHandler(dns: $dns, transport: $transport, followRedirects: true);

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/start',
            'method' => 'POST',
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
    }

    #[Test]
    public function enabled_public_redirect_strips_authorization_cross_host(): void
    {
        $dns = new ArrayDnsResolver([
            'hooks.example.test' => ['203.0.113.10'],
            'other.example.test' => ['203.0.113.11'],
        ]);
        $transport = new FakeWebhookHttpTransport([
            new WebhookHttpResponse(302, '', ['Location' => 'https://other.example.test/next']),
            new WebhookHttpResponse(200, 'ok'),
        ]);
        $handler = $this->makeHandler(
            secrets: ['api_token' => 'Bearer '.self::SENTINEL],
            dns: $dns,
            transport: $transport,
            followRedirects: true,
        );

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/start',
            'method' => 'POST',
            'headers' => [
                'Authorization' => '{{ secret.api_token }}',
            ],
            'body' => '{}',
            'secret_keys' => ['api_token'],
        ]));

        $this->assertTrue($result->isSuccessful());
        $this->assertArrayHasKey('Authorization', $transport->requests[0]['headers']);
        $this->assertArrayNotHasKey('Authorization', $transport->requests[1]['headers']);
    }

    #[Test]
    public function unsafe_headers_and_crlf_are_rejected(): void
    {
        $handler = $this->makeHandler(transport: new FakeWebhookHttpTransport);

        $host = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/workflow',
            'method' => 'POST',
            'headers' => ['Host' => 'evil.test'],
            'body' => '{}',
        ]));
        $this->assertFalse($host->isSuccessful());

        $crlf = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/workflow',
            'method' => 'POST',
            'headers' => ["X-Foo\r\nX-Injected" => 'bar'],
            'body' => '{}',
        ]));
        $this->assertFalse($crlf->isSuccessful());
    }

    #[Test]
    public function duplicate_case_insensitive_headers_are_rejected(): void
    {
        $handler = $this->makeHandler(transport: new FakeWebhookHttpTransport);

        $result = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/workflow',
            'method' => 'POST',
            'headers' => [
                'X-Trace' => 'a',
                'x-trace' => 'b',
            ],
            'body' => '{}',
        ]));

        $this->assertFalse($result->isSuccessful());
    }

    #[Test]
    public function outbound_webhook_marks_5xx_and_429_as_retryable(): void
    {
        $transport = new FakeWebhookHttpTransport([
            new WebhookHttpResponse(503, 'down'),
            new WebhookHttpResponse(429, 'slow'),
        ]);
        $handler = $this->makeHandler(transport: $transport);

        $first = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/down',
            'method' => 'POST',
            'body' => '{}',
        ]));
        $this->assertTrue($first->isRetryable());
        $this->assertSame(503, $first->responseStatus);

        $second = $handler->handle($this->makeContext([
            'url' => 'https://hooks.example.test/rate',
            'method' => 'POST',
            'body' => '{}',
        ]));
        $this->assertTrue($second->isRetryable());
        $this->assertSame(429, $second->responseStatus);
    }

    #[Test]
    public function secret_sentinel_never_persists_in_events_logs_or_snapshots(): void
    {
        Event::fake([
            WebhookRequestDispatched::class,
            WebhookResponseReceived::class,
            WebhookRequestFailed::class,
            WebhookSecurityPolicyRejected::class,
        ]);

        $transport = new FakeWebhookHttpTransport([new WebhookHttpResponse(200, 'ok')]);
        $handler = $this->makeHandler(
            secrets: [
                'api_token' => self::SENTINEL,
                'signing' => self::SENTINEL,
            ],
            transport: $transport,
            clock: new FixedClock(1700000000),
        );

        $context = $this->makeContext([
            'url' => 'https://hooks.example.test/workflow',
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer {{ secret.api_token }}',
                'X-Api-Key' => '{{ secret.api_token }}',
            ],
            'body' => '{"token":"{{ secret.api_token }}"}',
            'secret_keys' => ['api_token', 'signing'],
            'signing_secret_key' => 'signing',
        ]);

        $this->assertTrue($handler->handle($context)->isSuccessful());
        $this->assertStringContainsString(self::SENTINEL, $transport->requests[0]['headers']['Authorization']);

        $haystacks = [
            json_encode($context->execution->fresh()->toArray()),
            json_encode(WorkflowActionAttempt::query()->get()->toArray()),
            json_encode(WorkflowLog::query()->get()->toArray()),
        ];

        Event::assertDispatched(WebhookRequestDispatched::class, function (WebhookRequestDispatched $event) use (&$haystacks): bool {
            $haystacks[] = json_encode($event);

            return ! str_contains(json_encode($event) ?: '', self::SENTINEL);
        });

        foreach ($haystacks as $haystack) {
            $this->assertIsString($haystack);
            $this->assertStringNotContainsString(self::SENTINEL, (string) $haystack);
        }
    }

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>|null  $dns
     */
    private function makeHandler(
        array $secrets = [],
        ?ArrayDnsResolver $dns = null,
        ?FakeWebhookHttpTransport $transport = null,
        bool $followRedirects = false,
        ?FixedClock $clock = null,
    ): OutboundWebhookHandler {
        $dnsResolver = $dns ?? new ArrayDnsResolver([
            'hooks.example.test' => ['203.0.113.10'],
            'other.example.test' => ['203.0.113.11'],
        ]);

        $ssrf = new SsrfGuard(
            denyPrivateIps: true,
            allowedSchemes: ['https', 'http'],
            dnsResolver: $dnsResolver,
        );

        return new OutboundWebhookHandler(
            new SafeTemplateRenderer,
            new Redactor,
            $ssrf,
            new WebhookHeaderValidator,
            new WebhookRequestSigner,
            new WebhookRedirectPolicy($ssrf, $followRedirects, 3),
            $transport ?? new FakeWebhookHttpTransport([new WebhookHttpResponse(200, 'ok')]),
            $clock ?? new FixedClock(1_700_000_000),
            new ArraySecretResolver($secrets),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $variables
     */
    private function makeContext(array $payload, array $variables = []): ReliableActionContext
    {
        $factory = app(WorkflowBuilderNodeFactory::class);
        $definition = [
            WorkflowDefinitionSchema::FIELD_KEY => 'webhook_ctx_flow_'.uniqid(),
            WorkflowDefinitionSchema::FIELD_NAME => 'Webhook Context Flow',
            WorkflowDefinitionSchema::FIELD_NODES => [
                $factory->make(WorkflowDefinitionSchema::NODE_TYPE_START, 'start', 'Start'),
                [
                    'key' => 'webhook_action',
                    'type' => 'action',
                    'name' => 'Webhook',
                    'config' => [
                        'action_key' => 'outbound_webhook',
                        'execution_mode' => ActionExecutionMode::ReliableNonBlocking->value,
                        'payload' => $payload,
                    ],
                ],
                $factory->make(WorkflowDefinitionSchema::NODE_TYPE_END, 'end', 'End'),
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                ['from' => 'start', 'to' => 'webhook_action'],
                ['from' => 'webhook_action', 'to' => 'end'],
            ],
        ];

        app(\DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry::class)
            ->enable(\DbflowLabs\Core\Enums\RuntimeCapability::OutboundWebhook);

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        $published = app(PublishWorkflowDraft::class)->handle($workflow, 1);
        $version = WorkflowVersion::query()->where('workflow_id', $published->getKey())->where('is_active', true)->firstOrFail();

        $subject = ContextTestSubject::query()->create(['reference_code' => 'WH-SUBJECT-'.uniqid()]);
        $instance = WorkflowInstance::query()->create([
            'workflow_id' => $published->getKey(),
            'workflow_version_id' => $version->getKey(),
            'workflowable_type' => $subject->getMorphClass(),
            'workflowable_id' => $subject->getKey(),
            'status' => 'running',
            'current_node_key' => 'webhook_action',
            'started_at' => now(),
        ]);

        $node = ActionNode::fromArray($definition['nodes'][1]);
        $execution = WorkflowActionExecution::query()->create([
            'workflow_instance_id' => $instance->getKey(),
            'node_key' => 'webhook_action',
            'action_key' => 'outbound_webhook',
            'execution_mode' => ActionExecutionMode::ReliableNonBlocking,
            'status' => 'running',
            'logical_execution_key' => 'instance:'.$instance->getKey().':node:webhook_action:visit:1',
            'visit_sequence' => 1,
            'attempts' => 1,
            'max_attempts' => 3,
            'queued_at' => now(),
            'node_snapshot' => $node->toArray(),
            'payload_snapshot' => $payload,
        ]);

        return new ReliableActionContext(
            $execution,
            $instance->refresh(),
            null,
            $node,
            'outbound_webhook',
            ActionExecutionMode::ReliableNonBlocking,
            (string) $execution->logical_execution_key,
            1,
            $payload,
            $variables,
        );
    }
}
