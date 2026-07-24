<?php

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Unit\Actions\Webhook;

use DbflowLabs\Core\Actions\Webhook\WebhookSecretReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookSecretReferenceTest extends TestCase
{
    #[Test]
    public function it_normalizes_mustache_secret_references(): void
    {
        $this->assertSame('webhook_signing', WebhookSecretReference::normalize('{{ secret.webhook_signing }}'));
        $this->assertSame('api_token', WebhookSecretReference::normalize('{{secret.api_token}}'));
    }

    #[Test]
    public function it_preserves_plain_secret_keys(): void
    {
        $this->assertSame('signing', WebhookSecretReference::normalize('signing'));
    }

    #[Test]
    public function it_detects_spaced_and_compact_secret_tokens(): void
    {
        $this->assertTrue(WebhookSecretReference::containsSecretToken('Bearer {{ secret.api_token }}'));
        $this->assertTrue(WebhookSecretReference::containsSecretToken('Bearer {{secret.api_token}}'));
        $this->assertFalse(WebhookSecretReference::containsSecretToken('Bearer static-token'));
        $this->assertFalse(WebhookSecretReference::containsSecretToken('{{ model.name }}'));
    }

    #[Test]
    public function it_rejects_malformed_secret_references(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebhookSecretReference::normalize('{{ secret.webhook_signing');
    }
}
