# Outbound Webhooks Operations

## Prerequisites

1. Enable runtime capabilities `reliable_action` and `outbound_webhook`.
2. Bind `DbflowLabs\Core\Contracts\Actions\WorkflowSecretResolver` in the host app.
3. Run queue workers and scheduler (`dbflow:actions-dispatch`, `dbflow:actions-recover`).

## Configuration

See `docs/reference/v1.1-configuration.md` (`dbflow.webhook.*`).

Critical defaults:

| Setting | Default | Meaning |
| --- | --- | --- |
| `follow_redirects` | `false` | Redirects disabled |
| `deny_private_ips` | `true` | SSRF default-deny |
| `require_https` | `false` | Optional HTTPS-only |
| `max_redirects` | `3` | Cap when redirects enabled |

## Downstream deduplication

Advise webhook consumers to treat `X-DBFlow-Idempotency-Key` as the dedupe key. After worker crashes with unknown external outcomes, DBFlow may retry with the same key.

## Diagnostics

```bash
php artisan dbflow:diagnostics
```

Reports webhook capability, secret resolver binding, redirect/SSRF policy flags, and size limits. Never prints secrets.
