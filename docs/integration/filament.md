# Filament integration contract (Core ↔ `dbflowlabs/filament`)

> Status: **0.9.x-beta** — public contract documented before API freeze at `1.0.0-rc.1`.  
> Audience: maintainers of `dbflowlabs/filament`, host applications, and third-party inbox integrations.

Core owns workflow **runtime semantics**. Filament owns **UI and panel wiring**. This document is the cross-package contract: what Filament may rely on, what it must not bypass, and how versions align.

---

## Version matrix

| `dbflowlabs/filament` | Requires `dbflowlabs/core` | Notes |
| --- | --- | --- |
| `0.9.x-beta` | `^0.9.0-beta` | Ecosystem alignment; prerelease pins recommended |
| `1.0.0-rc.x` | `^1.0.0-rc.1` | API freeze RC; Core `1.0.0-rc.1` pairs with Filament `1.0.0-rc.2+` for timeout editor |
| `1.0.0` | `^1.0.0` | Stable pair released together |

During alpha (`0.3`–`0.5`), Filament may lag Core feature tags. From **0.9-beta** onward, integration releases should be coordinated and documented in both CHANGELOGs.

---

## Runtime actions (Filament MUST use Core entry points)

All state-changing operations go through `DBFlow` (or the underlying Actions resolved from the container). Filament **must not** write directly to `dbflow_*` tables for approve, reject, cancel, reassign, or start.

| User action | Core API | Preconditions |
| --- | --- | --- |
| Start workflow | `DBFlow::start($workflowKey, $workflowable, $startedBy, $metadata = [])` | Host authorization; published definition |
| Approve task | `DBFlow::approve($task, $actor, $comment = null)` | Actor is current pending assignee (host may add policy) |
| Reject task | `DBFlow::reject($task, $actor, $comment, $strategy, $targetNodeKey = null)` | Same as approve |
| Cancel instance | `DBFlow::cancel($instance, $actor, $comment = null)` | Host authorization only (Core has no cancel policy) |
| Reassign task | `DBFlow::reassign($task, $fromActor, $toUserId, $comment = null)` | `fromActor` must be the pending assignee being replaced |

`DBFLOW_ENABLED=false` disables runtime APIs and `dbflow:process-timeouts`. Definition sync/validate remain available (see README).

Filament may inject `ApproveTask` / `RejectTask` via the container **only** when those classes are the same bindings used by `DBFlow` — prefer `DBFlow::approve()` / `reject()` for a single public contract.

---

## Pending task queries (Filament SHOULD use Core query service)

### `WorkflowTaskQueryService`

Registered in the container as a singleton. Resolve with:

```php
app(\DbflowLabs\Core\Services\WorkflowTaskQueryService::class);
```

**Public methods (frozen from 0.9-beta; semver MAJOR before change):**

| Method | Signature | Behavior |
| --- | --- | --- |
| `getPendingTasksForUser` | `(string $userId, int $perPage = 10): LengthAwarePaginator` | Paginated pending assignments for inbox UIs |
| `pendingAssignmentsQueryForUser` | `(string $userId): Builder` | Same filters and eager loads as above; for custom paginators (e.g. Filament tables) |
| `countPendingTasksForUser` | `(string $userId): int` | Badge / notification count; single COUNT query |

**Filtering rules (both methods):**

- `workflow_task_assignments.assignee_user_id = $userId`
- Assignment `status = pending`
- Parent `workflow_tasks.status = pending` (excludes stale rows after multi-approver completion)

**Pagination:** default `10` items per page; recommended maximum `50`.

**Ordering:** `created_at` descending (newest first).

### Required eager-load graph

`getPendingTasksForUser()` always eager-loads:

```text
workflowTask
workflowTask.workflowInstance
workflowTask.workflowInstance.workflow
workflowTask.workflowInstance.workflowVersion
workflowTask.workflowInstance.workflowable
```

Integrators may rely on these relations being loaded **without extra queries** after pagination. Do not depend on undeclared relations.

Filament `MyWorkflowTasksQuery` (or successors) should either delegate to `WorkflowTaskQueryService` or match this filter + eager-load set exactly. Missing `workflowVersion` breaks canvas/timeline presenters that read published definition metadata.

---

## Business record links — `WorkflowRouteResolvable`

Host workflowable models may implement:

```php
use DbflowLabs\Core\Contracts\WorkflowRouteResolvable;

final class PurchaseRequest extends Model implements WorkflowRouteResolvable
{
    public function getWorkflowShowUrl(): ?string
    {
        return PurchaseRequestResource::getUrl('view', ['record' => $this]);
    }
}
```

| Rule | Detail |
| --- | --- |
| Return value | Full URL string, or `null` when no detail route exists |
| Caller responsibility | Inbox widgets, mail, and notifications must degrade gracefully when `null` |
| Core responsibility | Core does not generate Filament URLs; only documents the host contract |

Filament inbox columns and notification templates should resolve the workflowable from `assignment → task → instance → workflowable` and call `getWorkflowShowUrl()` when the model implements the interface.

---

## Laravel events (`DbflowLabs\Core\Events\*`)

All events use `Dispatchable` and `SerializesModels`. Public constructor properties are stable across 0.9 → 1.0.

| Event | Public properties | When dispatched |
| --- | --- | --- |
| `WorkflowStarted` | `WorkflowInstance $instance` | After instance enters `running` |
| `WorkflowCompleted` | `WorkflowInstance $instance` | Terminal approve |
| `WorkflowRejected` | `WorkflowInstance $instance` | Terminal reject (instance level) |
| `WorkflowCancelled` | `WorkflowInstance $instance` | After cancel |
| `TaskCreated` | `WorkflowTask $task`, `WorkflowInstance $instance` | New approval task |
| `TaskApproved` | `WorkflowTask $task`, `WorkflowInstance $instance`, `mixed $actor = null`, `?string $comment = null` | Assignment approved |
| `TaskRejected` | `WorkflowTask $task`, `WorkflowInstance $instance`, `mixed $actor = null`, `?string $comment = null` | User/system reject |
| `TaskReassigned` | `WorkflowTask $task`, `WorkflowInstance $instance`, `WorkflowTaskAssignment $previousAssignment`, `WorkflowTaskAssignment $newAssignment`, `mixed $actor = null`, `?string $comment = null` | Successful reassign |
| `TaskTimedOut` | `WorkflowTask $task`, `WorkflowInstance $instance`, `array $payload = []` | First timeout audit per task |
| `ActionFailed` | `WorkflowInstance $instance`, `ActionNode $node`, `Throwable $exception` | Action node handler threw (added post-1.0.0-rc.1, additive) |

`ActionFailed` fires whenever an `ActionHandler::execute()` call throws. By default the workflow keeps
traversing after logging `WorkflowLogEvent::ActionFailed` and dispatching this event (fire-and-forget
actions must not silently block approvals). Set `config.stop_on_error: true` on the action node to make
traversal abort with `ActionExecutionFailedException` instead.

### `TaskTimedOut` payload keys

| Key | Type | Description |
| --- | --- | --- |
| `node_key` | `string` | Approval node key |
| `due_at` | `string` | ISO 8601 due timestamp |
| `due_in` | `string` | Configured ISO 8601 duration |
| `on_timeout` | `string\|null` | e.g. `reject_end`; `null` when omitted (audit-only) |

`TaskTimedOut` is **independent** of `TaskRejected`. Listeners that notify assignees should subscribe to both when timeout auto-reject is configured.

---

## Definition editor — approval timeouts (Filament `1.0.0-rc.2+`)

Standard and Pro canvas editors may expose optional approval fields that map to Core `config.timeout`:

| Field | Core key | Notes |
| --- | --- | --- |
| Deadline duration | `timeout.due_in` | ISO 8601 duration (for example `P1D`, `PT24H`) |
| Overdue action | `timeout.on_timeout` | `reject_end` for auto-reject; omit for audit-only |

Hosts must schedule `php artisan dbflow:process-timeouts` when definitions use deadlines. Filament does not run the command.

---

## Filament package rules

1. **No direct runtime writes** to `dbflow_*` tables (reads for admin resources may exist; runtime transitions must use Core).
2. **Pending inbox** consumes `WorkflowTaskQueryService` or a documented adapter with identical semantics.
3. **Timeline / audit UI** reads `workflow_logs` or Core presenters — do not reconstruct state from assignments alone.
4. **Permissions** remain in Filament (`WorkflowFilamentPermissions`); Core does not ship RBAC.
5. **Reassign and cancel** UI in Filament must call `DBFlow::reassign()` / `DBFlow::cancel()` once exposed in the panel.

---

## Related documents

- [acceptance-checklist.md](acceptance-checklist.md) — cross-repo integration verification
- [README.md](../../README.md) — Runtime API and host checklist
- [`dbflowlabs/filament` repository](https://github.com/dbflow-labs/dbflow-filament) — standard panel integration

---

## Change policy (0.9-beta → 1.0)

| Change type | Allowed in 0.9 MINOR | Requires |
| --- | --- | --- |
| New optional event property | Yes | CHANGELOG + this doc |
| New `WorkflowTaskQueryService` method | Yes | MINOR bump + doc |
| Rename/remove public method or event property | No | Wait for 2.0 or pre-1.0 breaking window with migration guide |
| Change eager-load graph | No | MAJOR or explicit beta breaking note |

Silent breaking changes are not permitted after `0.9.0-beta.1`.
