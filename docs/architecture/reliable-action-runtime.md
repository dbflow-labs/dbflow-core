# Reliable Action Runtime (Stage 1.1-D)

Stage 1.1-D introduces a queued, ledger-backed action runtime alongside unchanged `legacy_sync` behavior.

## Modes

| Mode | Traversal | Execution record | Handler runs |
| --- | --- | --- | --- |
| `legacy_sync` (default) | Synchronous in workflow transaction | None | Inside traversal transaction |
| `reliable_blocking` | Stops at action node until success/skip | `dbflow_workflow_action_executions` | After commit, outside DB transaction |
| `reliable_non_blocking` | Continues immediately after queueing | Same ledger | After commit; instance may already be terminal |

`legacy_sync` remains the default when `execution_mode` is omitted.

## Ledger

### `dbflow_workflow_action_executions`

| Column | Description |
| --- | --- |
| `workflow_instance_id`, `workflow_task_id`, `node_key` | Identity |
| `action_key` | Reliable handler key |
| `execution_mode` | `reliable_blocking` / `reliable_non_blocking` |
| `status` | `queued`, `running`, `succeeded`, `failed`, `exhausted`, `cancelled`, `skipped` |
| `logical_execution_key` | Unique idempotency key (`instance:{id}:node:{key}:visit:{n}`) |
| `visit_sequence` | Per-instance visit counter stored in instance metadata |
| `attempts`, `max_attempts`, `next_attempt_at` | Retry state |
| `node_snapshot`, `payload_snapshot`, `result_metadata` | Safe audit snapshots |
| `workflow_advanced_at` | Set once when blocking execution advances the graph |

**Unique:** `logical_execution_key`

### `dbflow_workflow_action_attempts`

Per-attempt evidence (`attempt_number`, redacted request/response metadata, errors).

## Runtime flow

1. `WorkflowNodeTraverser` creates a ledger row via `ActionExecutionInitializer`.
2. `DispatchActionExecutions` claims queued rows and dispatches `ProcessActionExecutionJob` (`$tries = 1`).
3. `ProcessActionExecution` runs the handler **outside** claim/update transactions.
4. Blocking success/skip calls `AdvanceWorkflowFromAction` (idempotent via `workflow_advanced_at`).
5. `dbflow:actions-recover` returns stale `running` rows to `queued`.

## Events

`ActionExecutionQueued`, `Started`, `Succeeded`, `Failed`, `Exhausted`, `RetryScheduled`, `ManuallyRetried`, `Skipped`, `Cancelled`, `Recovered`.

## Commands

```bash
php artisan dbflow:actions-dispatch
php artisan dbflow:actions-recover
php artisan dbflow:diagnostics
```

## Capability gates

Publishing requires `reliable_action`. `outbound_webhook` action key additionally requires `outbound_webhook`.

`stop_on_error` cannot be combined with reliable modes.
