# DBFlow Core 1.1.0

Enterprise approval controls and reliable actions on top of the frozen 1.0 runtime API.

## Highlights

- Workflow Context contract and field catalog
- Assignment provenance and hardened reassignment
- Time-bounded delegation with effective-assignee resolution and pending-task migration
- Fixed-duration SLA (reminders, overdue, escalation) with queue dispatch and recovery
- Reliable action execution ledger (blocking/non-blocking, manual retry/skip, recovery)
- Outbound webhooks with SSRF/TLS guards, HMAC signing, and idempotency keys
- Runtime capability registry and `dbflow:diagnostics`
- Permanent v1.0 definition regression suite (`V10CompatibilityTest`)

## Upgrade

```bash
composer require dbflowlabs/core:^1.1
php artisan migrate
```

From `1.0.x`: additive migrations and APIs only; v1.0 definitions remain valid. Requires Laravel 13.

See [UPGRADE-1.1.md](UPGRADE-1.1.md) for scheduler/queue steps and the host checklist.

## Ecosystem pairing

| Package | Tag | Constraint |
| --- | --- | --- |
| `dbflowlabs/core` | `1.1.0` | — |
| `dbflowlabs/filament` | `1.1.0` | `^1.1` on core |
| `dbflowlabs/filament-pro` | `1.1.0` | `^1.1` on core + filament |

## Compare

https://github.com/dbflow-labs/dbflow-core/compare/1.0.0...1.1.0
