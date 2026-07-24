# Upgrading to DBFlow Core 1.1

This guide covers upgrading from **stable `1.0.x`** to **`1.1.0`**. For `0.x` / RC → `1.0`, see [UPGRADE-1.0.md](UPGRADE-1.0.md). Full release history: [CHANGELOG.md](CHANGELOG.md).

## Compatibility summary

- **Additive release.** v1.0 definitions remain valid; Core does not inject `schema_version` automatically.
- **Requires Laravel 13** (`illuminate/*` `^13.0`).
- **MySQL 8.0+** remains the production certification target.
- Pair UI packages together: `dbflowlabs/filament:^1.1` and optional `dbflowlabs/filament-pro:^1.1`.

## Install

```bash
composer require dbflowlabs/core:^1.1
php artisan migrate
```

Publish config only if you need to override new v1.1 sections (`delegation`, `sla`, `actions`, `webhook`, `reassignment`):

```bash
php artisan vendor:publish --tag=dbflow-config
```

## Scheduler and queue

If you adopt SLA, reliable actions, or outbound webhooks, register scheduler entries and run a queue worker. See [`docs/operations/queue-and-scheduler.md`](docs/operations/queue-and-scheduler.md).

```php
Schedule::command('dbflow:sla-dispatch')->everyMinute();
Schedule::command('dbflow:sla-recover')->everyFiveMinutes();
Schedule::command('dbflow:actions-dispatch')->everyMinute();
Schedule::command('dbflow:actions-recover')->everyFiveMinutes();
Schedule::command('dbflow:process-timeouts')->everyMinute(); // legacy timeout path only
```

```bash
php artisan queue:work --tries=1
php artisan dbflow:diagnostics
```

Legacy approval timeouts (`timeout.due_in` without v1.1 SLA) continue to use `dbflow:process-timeouts`. Tasks on the v1.1 SLA path are excluded from that command.

## What changed for hosts

| Area | Host impact |
| --- | --- |
| Migrations | Additive tables/columns only (delegations, SLA events, action executions/attempts, assignment provenance, task SLA fields). |
| Public API | New methods: `DBFlow::createDelegation()`, `revokeDelegation()`, `migratePendingTasksToDelegate()`. Existing 1.0 methods unchanged. |
| Reassignment inbox | On reassignment/escalation rows, `original_assignee_user_id` is audit-only; actionable scope follows the effective assignee. Delegation rows still allow original and effective actors. |
| Definitions | Optional `schema_version` `1.1` fields for SLA, reliable actions, webhooks, provenance metadata. |
| Capabilities | Runtime capability gates via `RuntimeCapabilityRegistry`; see architecture docs. |

## Optional adoption checklist

- [ ] Pin `dbflowlabs/core:^1.1` (and matching Filament/Pro tags if applicable).
- [ ] Run migrations on staging.
- [ ] Re-publish or merge `config/dbflow.php` for new sections you will use.
- [ ] Register SLA / action scheduler commands when enabling those features.
- [ ] Confirm queue worker is running (`dbflow:diagnostics`).
- [ ] Review actionable-inbox / authorization code against provenance rules.
- [ ] Keep v1.0 definitions as-is until you intentionally adopt `schema_version` `1.1` fields.

## Documentation map

| Topic | Doc |
| --- | --- |
| Scope | [`docs/releases/v1.1-scope.md`](docs/releases/v1.1-scope.md) |
| Definition schema | [`docs/reference/v1.1-definition-schema.md`](docs/reference/v1.1-definition-schema.md) |
| Configuration | [`docs/reference/v1.1-configuration.md`](docs/reference/v1.1-configuration.md) |
| Delegation | [`docs/architecture/reassignment-and-delegation.md`](docs/architecture/reassignment-and-delegation.md) |
| SLA | [`docs/architecture/sla-runtime.md`](docs/architecture/sla-runtime.md) |
| Reliable actions | [`docs/architecture/reliable-action-runtime.md`](docs/architecture/reliable-action-runtime.md) |
| Webhooks | [`docs/architecture/outbound-webhook-security.md`](docs/architecture/outbound-webhook-security.md) |
| Operations | [`docs/operations/`](docs/operations/) |

## Known limitations

- MySQL/PostgreSQL concurrency proofs are host-operational (not enforced in package SQLite CI).
- Webhook delivery is not externally exactly-once.
- `live_context` evaluation remains disabled.

## Support

- [CHANGELOG.md](CHANGELOG.md)
- [RELEASE-NOTES-1.1.0.md](RELEASE-NOTES-1.1.0.md)
- [GitHub Issues](https://github.com/dbflow-labs/dbflow-core/issues)
