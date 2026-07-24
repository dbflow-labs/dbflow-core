# SLA Runtime

## Status

Accepted (Stage 1.1-C implementation and focused regression suites green).

## Purpose

Stage 1.1-C adds fixed-duration approval SLA policies with persisted event scheduling, pre-due reminders, idempotent overdue detection, and basic escalation. The runtime is Core-owned, queue-safe, and independent from Filament/Pro UI.

## Confirmed Legacy Timeout Baseline

Evidence: `src/Actions/ProcessTaskTimeouts.php`, `src/Console/Commands/ProcessTaskTimeoutsCommand.php`, `tests/Feature/ProcessTaskTimeoutsTest.php`.

| Aspect | v1.0 behavior |
| --- | --- |
| Definition | Approval nodes only; `config.timeout.due_in` (ISO 8601); optional `config.timeout.on_timeout: reject_end` |
| Task `due_at` | Materialized at task creation via `TimeoutDueAtResolver` (`StartWorkflow`, `ApproveTask`, `RejectTask` rollback) |
| Processor | Artisan `dbflow:process-timeouts`; synchronous action; no queue job; no execution ledger |
| Overdue without `on_timeout` | Writes `TaskTimedOut` log + Laravel event; task stays `pending` |
| `reject_end` | Invokes `RejectTask` with `RejectStrategy::End`, null actor, reason `Task timed out.` |
| Idempotency | `TaskTimedOut` log written once; `reject_end` only runs while task is still `pending` |

## SLA vs Legacy Timeout

| Path | Marker | Processor |
| --- | --- | --- |
| Legacy timeout only | `sla_policy_source` is `null` | `dbflow:process-timeouts` (unchanged) |
| v1.1 SLA | `sla_policy_source = v1.1_sla` | `dbflow:sla-dispatch` + `ProcessSlaEventJob` |

Tasks with SLA are excluded from `ProcessTaskTimeouts` to prevent duplicate processing. Legacy `reject_end` is not reinterpreted as SLA escalation.

When both `config.timeout` and `config.sla` are present, publication fails unless they are provably equivalent (same duration, no `on_timeout`, no conflicting overdue auto-actions).

## Fixed-duration SLA Definition

Approval-node additive config:

```json
{
  "sla": {
    "due_after": "PT24H",
    "reminders": [
      { "before_due": "PT2H", "channel": "event" }
    ],
    "overdue": {
      "notify": true,
      "channel": "event",
      "escalation": {
        "type": "reassign",
        "target": { "resolver": "permission", "value": "workflow_admin" }
      }
    },
    "retry": {
      "max_attempts": 3,
      "backoff_seconds": [60, 300, 900]
    }
  }
}
```

Requires `sla` runtime capability for publication. Automatic approve and new automatic reject modes are rejected.

## Duration Semantics

- Elapsed-time only via `SlaDuration` (`src/Sla/SlaDuration.php`).
- `PT24H` = 24 elapsed hours; `P1D` normalized to 24 hours; `P1W` = 7 fixed days.
- Months (`M`) and years (`Y`) rejected.
- Zero, negative, and malformed durations rejected.
- Configurable `dbflow.sla.min_duration_seconds` and `max_duration_seconds`.

## UTC and Display Timezones

All persisted timestamps (`due_at`, `overdue_at`, `scheduled_at`) are absolute UTC. Task reference time for materialization is task creation time (`created_at`).

## Task Policy Snapshot

On SLA task creation:

1. Normalize SLA from immutable workflow version node config.
2. Compute `due_at` once from `due_after`.
3. Persist `sla_policy_snapshot` (JSON) and `sla_policy_source = v1.1_sla`.
4. Materialize SLA events in the same transaction.

Subsequent definition changes do not alter bound tasks.

## Due-date Materialization

`TaskSlaInitializer` sets `due_at` from `sla.due_after` at task creation. Legacy tasks continue using `timeout.due_in` via `TimeoutDueAtResolver`.

## SLA Event Types

| Type | Purpose |
| --- | --- |
| `reminder` | Pre-due notification |
| `overdue` | Mark `overdue_at`; dispatch overdue audit |
| `escalation` | Notify, reassign, or custom handler |

## SLA Event State Machine

`pending` → `processing` → `completed` | `failed` | `cancelled`

Failed events with remaining attempts return to `pending` with `next_attempt_at`. `completed` means the handler accepted processing per contract (not third-party delivery proof).

## Event Idempotency

Unique `idempotency_key` per logical event:

- `task:{id}:reminder:{sequence}`
- `task:{id}:overdue`
- `task:{id}:escalation:{sequence}`

## Reminder Semantics

Scheduled at `reference_time + (due_after - before_due)`. Terminal tasks cancel reminders without notifying. Handlers run outside claim transactions.

## Notification Delivery Contract

`SlaNotificationHandler` receives immutable `SlaNotificationContext`. Built-in `event` channel dispatches Laravel domain events after successful handoff. Hosts register custom channels via `SlaNotificationHandlerRegistry`.

## Overdue Semantics

When due and task is actionable: set `overdue_at` once, complete overdue event, append workflow log, dispatch `TaskBecameOverdue`. Does not approve, reject, or cancel the task.

## Basic Escalation

Supported types: `notify`, `reassign`, registered `custom` handler key. No topology mutation, multi-level chains, or automatic approve/reject.

## Reassignment-based Escalation

Uses `ReassignTask::handleEscalation()` with `AssignmentSource::Escalation` and SLA idempotency key. Same-target escalation completes as controlled no-op.

## Legacy reject_end Compatibility

Unchanged. Only tasks without `sla_policy_source` are processed by `dbflow:process-timeouts`. SLA definitions must not add new automatic reject modes.

## Task Completion and Event Cancellation

`CancelTaskSlaEvents` cancels pending/retryable failed/processing-race events when tasks become terminal (approve, reject, cancel, skip, workflow cancel, legacy `reject_end`).

## Queue and Dispatcher Architecture

`dbflow:sla-dispatch` claims due events transactionally, then dispatches `ProcessSlaEventJob` after commit. Handler side effects occur in the job outside the claim transaction.

## Retry and Backoff

Per-event `attempts` with bounded `max_attempts` from policy snapshot or config defaults. Deterministic `backoff_seconds` array. Laravel queue retries are disabled on `ProcessSlaEventJob` (`$tries = 1`); SLA attempts are authoritative.

## Stale Processing Recovery

`dbflow:sla-recover` reclaims events in `processing` older than `stale_processing_threshold_seconds`, respecting attempt limits and terminal task cancellation.

## Audit Events

Workflow logs and Laravel events for scheduling, reminders, overdue, escalation, recovery, and cancellation. Payloads exclude secrets and full context bags.

## Database Schema

Migration `2026_07_23_100200_add_sla_fields_to_dbflow_workflow_tasks.php`:

- `overdue_at`, `sla_policy_snapshot`, `sla_policy_source`

Migration `2026_07_23_100300_create_dbflow_workflow_sla_events_table.php`:

- Append-only `dbflow_workflow_sla_events` with status, scheduling, retry, and unique `idempotency_key`.

## Runtime Capability

`RuntimeCapability::Sla` enabled via `RuntimeCapabilityRegistry::registerStage11CDefaults()` after runtime completion.

## Backward Compatibility

- v1.0 definitions without SLA unchanged.
- Historical `due_at` not recalculated.
- No fabricated historical SLA events.
- `V10CompatibilityTest`, `Stage11AContractTest`, `Stage11BDelegationTest` remain green.

## Operational Requirements

Host must schedule:

- `php artisan dbflow:sla-dispatch` (e.g. every minute)
- `php artisan dbflow:sla-recover` (e.g. every five minutes)
- Legacy: `php artisan dbflow:process-timeouts` for non-SLA tasks

Queue worker required for `ProcessSlaEventJob`.

## Security

Handlers receive sanitized context only. Errors truncated to `dbflow.sla.max_error_length`. No host model binding in Core contracts.

## Failure Handling

Non-retryable handler results mark event `failed` permanently. Retryable results schedule `next_attempt_at`. Exhausted attempts remain `failed` with diagnostics metadata.

## Non-goals

Business-day calendars, holidays, shifts, multi-level escalation chains, automatic approve on timeout, new automatic reject (except legacy `reject_end`), Reliable Action ledger, webhooks, Filament/Pro SLA UI.

## Test Matrix

See `tests/Feature/Stage11CSlaTest.php`, `tests/Unit/SlaDurationTest.php`, and legacy `ProcessTaskTimeoutsTest.php`.

## Open Questions

- P2: Unified diagnostics command surface (partial via `SlaDiagnostics` service).
- P2: MySQL/PostgreSQL row-lock concurrency proofs beyond SQLite portable tests.
