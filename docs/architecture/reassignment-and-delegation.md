# Reassignment and Delegation Runtime

## Status

Accepted (Stage 1.1-B implementation and suites green).

## Purpose

Harden the existing `ReassignTask` path and add a Core-owned, time-bounded Delegation runtime with provenance, concurrency safety, and v1.0 compatibility.

## Confirmed v1.0 Assignment Model

Evidence: `database/migrations/2026_06_18_100000_create_dbflow_workflow_tables.php`, `src/Actions/StartWorkflow.php`, `src/Actions/ReassignTask.php`.

- One task has many assignment rows.
- Unique `(workflow_task_id, assignee_user_id)`.
- Reassignment marks the source row `reassigned` and appends a new pending row.
- Provenance previously lived mainly in workflow log payloads.

## Reassignment Definition

Task-specific transfer of the current effective assignee via existing `ReassignTask`. Does not change node, restart the task, or rewrite completed approvals.

## Delegation Definition

A time-bounded rule that maps an original responsible actor to a delegate for future matching approvals. Not an approval, not a permanent role grant, and not a definition mutation.

## Reassignment vs Delegation

| | Reassignment | Delegation |
| --- | --- | --- |
| Scope | one pending task | future matching tasks |
| Persistence | assignment rows + provenance | `dbflow_workflow_delegations` |
| Mutation path | `ReassignTask` only | Create/RevokeDelegation + resolver |

## Responsibility vs Effective Actor

- On initial materialization (direct or delegated): `assignee_user_id` stores the original responsible actor and remains the uniqueness key with `workflow_task_id`.
- `effective_assignee_user_id` stores who may act (nullable; falls back to `assignee_user_id`).
- `original_assignee_user_id` preserves original responsibility across reassignment.
- After `ReassignTask`, a new pending row is appended with `assignee_user_id = target` (v1.0-compatible) while `original_assignee_user_id` retains the original responsibility for audit.
- On delegated rows, both the original responsible actor (`assignee_user_id`) and the effective delegate may act.
- On reassigned or escalated rows, only the effective actor may act; `original_assignee_user_id` is provenance-only.
- Multiple original responsibilities may share one effective actor without collapsing votes.

## Delegation Lifecycle

Computed from `starts_at`, `ends_at`, `revoked_at`:

- `revoked` if `revoked_at` set
- `scheduled` if now < starts_at
- `active` if starts_at <= now < ends_at
- `expired` if now >= ends_at

Rules are immutable after creation; change requires revoke + recreate.

## Delegation Time Semantics

Half-open UTC intervals: `starts_at <= t < ends_at`.

## Delegation Scope

- Global: workflow_key null, node_key null
- Workflow: workflow_key set, node_key null
- Node: workflow_key and node_key set
- Invalid: node_key without workflow_key

## Scope Precedence

1. workflow + node
2. workflow
3. global

Ambiguous same-level matches fail safely.

## Overlap Rules

Same delegator + same specificity + overlapping half-open intervals → rejected. Different specificity may coexist.

## Cycle Prevention

Direct and multi-hop cycles over intersecting time and compatible scope are rejected up to a configured depth; depth overflow fails closed.

## New Task Assignment Resolution

Direct-only: resolve original candidates, then optionally apply one matching active delegation. Never recurse into the delegate’s own rules.

## Existing Pending Task Migration

Not automatic. Explicit `MigratePendingTasksToDelegate` invokes `ReassignTask` per task with bounded batching and partial-failure summaries.

## Any / All / Sequential Approval Semantics

Preserve original responsibility counts. Effective actors may act on currently actionable represented assignments only; sequential gating remains sequence-based.

## Multiple Responsibilities Delegated to One Actor

Preserve one assignment row per original responsibility. Do not merge votes.

## Reassignment Concurrency

Task + assignment `lockForUpdate`, post-lock state recheck, terminal-task rejection.

## Delegation Concurrency

Create/revoke under transactions with locking sufficient to prevent conflicting overlapping inserts.

## Idempotency

Optional `reassignment_operation_key` on the newly created assignment row with a task-scoped unique index.

## Audit Events

DelegationCreated, DelegationRevoked, TaskAssignedViaDelegation, TaskReassigned (existing), PendingTasksMigrationCompleted + matching workflow log events.

## Database Schema

Additive:

- `dbflow_workflow_delegations`
- assignment provenance columns (`assignment_source`, `original_assignee_user_id`, `effective_assignee_user_id`, `delegation_id`, `previous_assignment_id`, `reassignment_operation_key`)

## Backward Compatibility

v1.0 rows with null provenance normalize to direct + assignee fallback. Existing `DBFlow::reassign()` signature preserved with optional additive parameters.

## Runtime Capability

`delegation` enabled only after runtime completion. SLA / reliable_action / outbound_webhook remain disabled.

## Security and Authorization

Assignment-driven eligibility remains Core-owned. Additive config gates self-service vs admin delegation without binding Spatie/App\User/DBErp.

## Failure Handling

Structured exceptions; migration continues after per-task failures.

## Non-goals

SLA, calendars, multi-hop execution, Filament/Pro management UI, Reliable Actions, Webhooks.

## Test Matrix

Lifecycle, scope, overlap, cycles, assignment materialization, Any/All/Sequential, reassignment idempotency, migration, revocation, V10 fixtures.

## Open Questions

None blocking Stage 1.1-C. Concurrent overlapping `CreateDelegation` retries InnoDB deadlocks with consistent lock ordering.
