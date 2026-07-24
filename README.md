# DBFlow Core

[![Tests](https://github.com/dbflow-labs/dbflow-core/actions/workflows/tests.yml/badge.svg)](https://github.com/dbflow-labs/dbflow-core/actions)
[![Latest Release](https://img.shields.io/github/v/release/dbflow-labs/dbflow-core?include_prereleases)](https://github.com/dbflow-labs/dbflow-core/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4-777bb4.svg)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-13.x-ff2d20.svg)](composer.json)

**Model-first workflow runtime for Laravel applications.**

DBFlow Core lets you add approval workflows, tasks, transitions, rejection flows, and audit logs to any Eloquent model without building a heavy BPM system from scratch.

It is the open-source runtime foundation of the DBFlow ecosystem. Host-specific business adapters, Filament UI packages, and the visual workflow Builder are distributed separately.

> [!NOTE]
> DBFlow Core **1.1.0** extends the stable 1.0 runtime with additive contracts (context, delegation, SLA, reliable actions, outbound webhooks). See [API stability](#api-stability), [UPGRADE-1.1.md](UPGRADE-1.1.md), and [CHANGELOG.md](CHANGELOG.md).

## Contents

- [Package Overview](#package-overview)
- [What Core Provides](#what-core-provides)
- [Requirements](#requirements)
- [Installation](#installation)
- [Laravel Integration](#laravel-integration)
- [Configuration](#configuration)
- [Minimal Usage](#minimal-usage)
- [Runtime API Summary](#runtime-api-summary)
- [Assignee Types (Runtime)](#assignee-types-runtime)
- [Host Responsibilities](#host-responsibilities)
- [Host Integration Checklist](#host-integration-checklist)
- [Package Boundaries](#package-boundaries)
- [DBFlow Ecosystem](#dbflow-ecosystem)
- [Development](#development)
- [API stability](#api-stability)
- [Versioning](#versioning)
- [Support](#support)
- [License](#license)

## Package Overview

| Item | Value |
| --- | --- |
| **Package name** | `dbflowlabs/core` |
| **Namespace** | `DbflowLabs\Core` |
| **License** | [MIT](LICENSE) |
| **Repository** | [github.com/dbflow-labs/dbflow-core](https://github.com/dbflow-labs/dbflow-core) |
| **Default branch** | `main` |
| **Stability** | `Stable (1.1.0)` |
| **Author** | Baron Wang <hello@dbflow.dev> |
| **Documentation** | [dbflow.dev/docs](https://dbflow.dev/docs) |
| **Laravel compatibility** | `13.x` |
| **PHP requirements** | `8.3`, `8.4` |

## What Core Provides

DBFlow Core provides the runtime foundation required for deterministic, schema-driven workflow execution:

- **Workflow definitions** — code-first and array-hydrated workflow schema management.
- **Topology validation** — validation for nodes, transitions, and directed workflow routing.
- **State machine persistence** — workflow instances, task states, assignments, and transition records.
- **Task runtime** — task creation, assignment handling, approval actions, rejection actions, and cancellation.
- **Approval modes** — runtime support for common approval strategies.
- **Transition handling** — controlled movement between workflow nodes.
- **Append-only audit logs** — historical workflow activity records for traceability.
- **Action node failure handling** — `ActionFailed` events and audit entries when handlers throw; optional `stop_on_error` to abort traversal.
- **Workflow Context** — additive context contract and field catalog for definition validation and runtime reads.
- **Delegation** — time-bounded delegation rules, effective-assignee resolution, and pending-task migration.
- **SLA runtime** — fixed-duration policies with reminders, overdue handling, escalation, and queue recovery.
- **Reliable actions** — persisted action executions with blocking/non-blocking modes, retry/skip, and recovery commands.
- **Outbound webhooks** — SSRF/TLS guards, HMAC signing, idempotency keys, and host secret resolution.
- **Extension points** — assignee resolvers, workflow hooks, condition handling, action handlers, and runtime capability gates.

> [!NOTE]
> Core focuses entirely on the workflow runtime engine. It contains no frontend assets, Filament resources, visual canvas, or host-specific business models.

## Requirements

- PHP `^8.3`
- Laravel `13.x` / Illuminate `^13.0` components
- **MySQL `8.0` or later** (production and certification target)
- SQLite (supported for development and package tests only)
- PostgreSQL is not part of the v1.1 stable certification matrix unless separately certified for your deployment
- A host user model, usually `App\Models\User`
- A user table (or equivalent) for actor and assignee references; UUID/ULID primary keys are supported in v0.3+

## Installation

### Packagist Installation

```bash
composer require dbflowlabs/core
```

Releases are tagged on GitHub, for example:

```text
v1.1.0
```

Hosts on `1.0.x` can upgrade with `composer require dbflowlabs/core:^1.1` — see [UPGRADE-1.1.md](UPGRADE-1.1.md).

## Laravel Integration

### Service Provider

The core service provider is registered automatically via Laravel package discovery:

```php
DbflowLabs\Core\Providers\DBFlowServiceProvider::class
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=dbflow-config
```

This publishes:

```text
config/dbflow.php
```

Publishing the configuration file is optional, but recommended when the host application needs to customize authentication, user resolution, or runtime feature flags.

### Publish Migrations

```bash
php artisan vendor:publish --tag=dbflow-migrations
php artisan migrate
```

DBFlow Core creates only `dbflow_*` tables, preserving schema separation from host application tables.

During local package development, migrations may also be loaded directly through Laravel's `loadMigrationsFrom()` behavior. **Publishing migrations is optional** — a host application can run `php artisan migrate` without publishing, as long as the package service provider is registered.

## Configuration

Publish the package config (optional but recommended):

```bash
php artisan vendor:publish --tag=dbflow-config
```

The package ships a **framework-neutral** `config/dbflow.php`. It does not hard-code a host user model or guard — set those in your application `.env` (or override the published file in the host).

Package defaults (illustrative — see the published file for the exact source):

```php
return [
    'enabled' => env('DBFLOW_ENABLED', true),
    'binding_mode' => env('DBFLOW_BINDING_MODE', 'code'),
    'auth' => [
        'model' => env('DBFLOW_AUTH_MODEL'),
        'guard' => env('DBFLOW_AUTH_GUARD'),
        'table' => env('DBFLOW_AUTH_TABLE'),
        'connection' => env('DBFLOW_AUTH_CONNECTION'),
        'resolver' => DbflowLabs\Core\Support\ConfigUserResolver::class,
    ],
    'expression' => [
        'strict' => env('DBFLOW_EXPRESSION_STRICT', false),
    ],
    'visual_builder_enabled' => env('DBFLOW_VISUAL_BUILDER_ENABLED', false),
    // Plus v1.1 sections: reassignment, delegation, sla, actions, webhook
];
```

v1.1 configuration keys are documented in [`docs/reference/v1.1-configuration.md`](docs/reference/v1.1-configuration.md).

**Host example** — typical Laravel app after publish (you may add `env()` fallbacks in *your* copy only):

```php
'auth' => [
    'model' => env('DBFLOW_AUTH_MODEL', 'App\\Models\\User'),
    'guard' => env('DBFLOW_AUTH_GUARD', 'web'),
    'table' => env('DBFLOW_AUTH_TABLE', 'users'),
    'resolver' => DbflowLabs\Core\Support\ConfigUserResolver::class,
],
```

Recommended `.env` for that host example:

```env
DBFLOW_ENABLED=true
DBFLOW_BINDING_MODE=code
DBFLOW_AUTH_MODEL=App\Models\User
DBFLOW_AUTH_TABLE=users
DBFLOW_AUTH_GUARD=web
DBFLOW_EXPRESSION_STRICT=false
```

`ConfigUserResolver` supports integer and string primary keys at runtime. User references are stored as strings in `dbflow_*` tables.

> [!NOTE]
> Set `DBFLOW_ENABLED=false` to disable the workflow **runtime**. When disabled, `DBFlow::start()` / `approve()` / `reject()` / `cancel()` / `reassign()` / delegation APIs throw `WorkflowNotAvailableException`, timeout/SLA/action artisan commands that require the runtime fail, and runtime action bindings (`StartWorkflow`, etc.) are not registered. Definition-management bindings remain available (`registerDefinitionProvider`, `registerAssigneeResolver`, etc.), migrations still load, and `php artisan dbflow:sync` / `dbflow:validate` remain registered so hosts can sync or validate definitions before re-enabling.

## Minimal Usage

Code-first integration: **register → sync → attach model → run → guard host actions**.

### 1. Register Runtime Definitions

Register workflow definitions, assignee resolvers, and hooks during application boot. A host service provider is usually the best place for this.

```php
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;

DBFlow::registerDefinitionProvider(
    app(WorkflowDefinitionRegistry::class),
    $myDefinitionProvider,
);

DBFlow::registerAssigneeResolver(
    app(AssigneeResolverRegistry::class),
    'finance_team',
    $myAssigneeResolver,
);

DBFlow::registerWorkflowHooks(
    app(WorkflowHooksRegistry::class),
    'refund_approval',
    MyRefundHooks::class,
);
```

A `WorkflowDefinitionProvider` returns a validated array definition (nodes, transitions, approval config). See `DbflowLabs\Core\Contracts\WorkflowDefinitionProvider` and package tests under `tests/Feature/SyncWorkflowDefinitionsTest.php`.

### 2. Sync Definitions to the Database

> [!IMPORTANT]
> Registering a provider alone is **not** enough for `DBFlow::start()`. The runtime resolves an **active published** row from `dbflow_workflow_versions`. After registration, sync code-first definitions into `dbflow_*` tables.

Call `SyncWorkflowDefinitions` from a deploy hook, or use the official Artisan command:

```bash
php artisan dbflow:sync
php artisan dbflow:sync --dry-run
php artisan dbflow:sync --workflow=refund_approval
```

Programmatic alternative:

```php
use DbflowLabs\Core\Actions\SyncWorkflowDefinitions;

/** @var array{created: list<string>, updated: list<string>, unchanged: list<string>} $summary */
$summary = app(SyncWorkflowDefinitions::class)->handle();
```

Validate definitions in CI:

```bash
php artisan dbflow:validate --strict
```

Process overdue approval tasks on the **legacy timeout** path (schedule via cron, for example every minute):

```bash
php artisan dbflow:process-timeouts
```

When using v1.1 SLA and reliable actions, also schedule dispatch/recover commands and run a queue worker — see [`docs/operations/queue-and-scheduler.md`](docs/operations/queue-and-scheduler.md). Check readiness with:

```bash
php artisan dbflow:diagnostics
```

Alternative for interactive or UI-owned workflows: `CreateWorkflowDraft` → `PublishWorkflowDraft` (see package actions and Filament packages).

Re-run sync after changing a code-first definition. UI-owned workflows (`source = ui`) are not overwritten as the active version pointer; see `SyncWorkflowDefinitions` source for details.

### 3. Attach Workflows to Host Models

Use the `HasWorkflow` trait on Eloquent models that participate in workflows.

**Recommended for most hosts — implement `Workflowable`** for business key and display metadata used in logs and UI adapters:

```php
use DbflowLabs\Core\Contracts\Workflowable;
use DbflowLabs\Core\Traits\HasWorkflow;

final class RefundRequest extends Model implements Workflowable
{
    use HasWorkflow;

    public function workflowBusinessKey(): ?string
    {
        return $this->reference_no;
    }

    public function workflowDisplayName(): string
    {
        return 'Refund Request';
    }
}
```

**For condition routing — also implement `WorkflowContextInterface`** so condition transitions can read business variables:

```php
use DbflowLabs\Core\Contracts\WorkflowContextInterface;

public function getWorkflowVariables(): array
{
    return [
        'total_amount' => (float) $this->amount,
        'supplier_type' => $this->supplier_type,
    ];
}
```

`WorkflowContextInterface` is separate from `Workflowable`. Models that need condition nodes should implement both (or pass `metadata['variables']` when calling `DBFlow::start()`).

When `binding_mode` is `code` (default), start workflows explicitly:

```php
$instance = $refundRequest->startWorkflow('refund_approval');
// equivalent to DBFlow::start('refund_approval', $refundRequest, auth()->user());
```

When `binding_mode` is `ui`, matching published workflows with a `model_type` binding may auto-start on `Model::created`. ERP-style hosts usually keep `code` and trigger from business actions (submit, confirm, etc.). Auto-start runs inside the `created` event, not the model's own persistence transaction; if a matching workflow fails to start (e.g. a misconfigured assignee resolver), the exception is reported via `report()` and swallowed so the model is still created and other matching workflows still get a chance to start — it does not roll back model creation or block sibling workflows.

### 4. Start a Workflow

Start a workflow for an Eloquent model **after** sync has published the definition:

```php
use DbflowLabs\Core\DBFlow;

$instance = DBFlow::start(
    'refund_approval',
    $refundRequest,
    auth()->user(),
    [
        // Host-defined keys — stored as JSON on dbflow_workflow_instances.metadata
        'submit_comment' => 'Q2 budget exception',
        // Used for condition routing when the model does not implement WorkflowContextInterface
        'variables' => ['priority' => 'high'],
    ],
);
```

> [!NOTE]
> **Metadata contract (stable):** Core persists the entire `$metadata` array on the workflow instance. It does not assign special meaning to keys such as `submit_comment` — naming conventions are **host-defined**. For condition nodes, prefer `WorkflowContextInterface::getWorkflowVariables()`; otherwise pass `metadata['variables']`.

### 5. Approve or Reject a Task

Approve a pending task:

```php
DBFlow::approve(
    $task,
    auth()->user(),
    'Approved.',
);
```

Reject a pending task (default strategy returns flow toward the starter):

```php
use DbflowLabs\Core\Enums\RejectStrategy;

DBFlow::reject(
    $task,
    auth()->user(),
    'Amount exceeds policy.',
    RejectStrategy::Starter,
);
```

### 6. Cancel a Running Workflow

`DBFlow::cancel()` stops a **running** instance — similar in product language to "withdraw", but it is a distinct Core API from approve/reject:

```php
DBFlow::cancel(
    $instance,
    auth()->user(),
    'Withdrawn by submitter.',
);
```

**What Core does:**

- Sets instance status to `cancelled`
- Cancels pending tasks and assignments
- Clears `active_key` so the same workflowable may be started again (if the host allows)
- Writes `WorkflowLogEvent::WorkflowCancelled`, one `WorkflowLogEvent::TaskCancelled` per pending task, and calls `WorkflowHooks::onCancelled`

**What Core does *not* do:**

- **Does not enforce who may cancel.** Core does not check `started_by_user_id` or approver roles. Compare `instance->started_by_user_id` (or your own policy) in the host **before** calling `cancel()`.
- **Does not define post-cancel business rules.** Whether a cancelled workflow blocks `confirm`, `ship`, `post`, etc. is entirely a **host responsibility** (see [Host Responsibilities](#host-responsibilities)).
- **Does not error when called on an already-terminal instance.** `cancel()` on a `cancelled`/`approved`/`rejected` instance is a silent no-op: it returns the instance unchanged and writes no additional log or event (verified by `CancelWorkflowTest::cancel_on_terminal_instance_is_idempotent_and_does_not_duplicate_logs`, part of the frozen 1.0 API). If the host needs to distinguish "I just cancelled it" from "it was already terminal", check `$instance->status->isTerminal()` **before** calling `cancel()`.

| Terminal status | Typical host `canConfirm` strategy (examples only) |
| --- | --- |
| `running` | Block until approved or cancelled |
| `approved` | Allow downstream business action |
| `rejected` | Block until a new `start()` completes successfully |
| `cancelled` | Host choice: allow action, require re-submit, or keep blocked |

### 7. Reassign a Pending Task

`DBFlow::reassign()` replaces a **single pending assignment** with a new assignee. It does not advance the workflow.

```php
DBFlow::reassign(
    $task,
    auth()->user(),
    $replacementUserId,
    'Reassigned while on leave.',
);
```

**What Core does:**

- Marks the actor's pending assignment as `reassigned`
- Creates a new `pending` assignment for `$toUserId` (inherits `sequence` in Sequential mode)
- Writes `WorkflowLogEvent::TaskReassigned`, dispatches `TaskReassigned`, and calls `TaskHooks::onReassigned`

**What Core does *not* do:**

- **Does not enforce admin/delegate policies.** Host must authorize who may reassign before calling `reassign()`.
- **Does not support add-sign / delegate-to-multiple.** Only one assignment is replaced per call.

### 8. Delegation (v1.1)

Create a time-bounded delegation rule, then optionally migrate matching pending tasks:

```php
$delegation = DBFlow::createDelegation(
    $delegator,
    $delegate,
    now(),
    now()->addDays(7),
    auth()->user(),
    workflowKey: 'refund_approval',
);

DBFlow::migratePendingTasksToDelegate($delegation, auth()->user(), dryRun: true);
DBFlow::revokeDelegation($delegation, auth()->user());
```

See [`docs/architecture/reassignment-and-delegation.md`](docs/architecture/reassignment-and-delegation.md).

### 9. Task Timeouts and SLA (v1.1)

**Legacy timeout** (v1.0 path) — approval nodes may declare:

```json
{
  "timeout": {
    "due_in": "P3D",
    "on_timeout": "reject_end"
  }
}
```

- `due_in` — ISO 8601 duration (for example `PT24H`, `P3D`). When a task is created, Core writes `workflow_tasks.due_at`.
- `on_timeout` — optional. Only `reject_end` is supported on this path. When omitted, overdue tasks are **logged only** and remain `pending`.

Schedule:

```bash
php artisan dbflow:process-timeouts
```

**v1.1 SLA** extends approvals with reminders, overdue notifications, and escalation via persisted `WorkflowSlaEvent` rows (`dbflow:sla-dispatch` / `dbflow:sla-recover`). Tasks on the v1.1 SLA path are excluded from `dbflow:process-timeouts`. See [`docs/architecture/sla-runtime.md`](docs/architecture/sla-runtime.md).

### 10. Query Workflow State on Models

Models using `HasWorkflow` can inspect runtime state without raw SQL:

```php
$order->hasRunningWorkflow('refund_approval');
$order->runningWorkflowInstance('refund_approval');
$order->completedWorkflowInstance('refund_approval');
$order->workflowLogs('refund_approval');
```

Use these helpers in host guards (for example, disable a Filament **Confirm** action while `hasRunningWorkflow()` is true).

### 11. Action Node Failures

Action nodes execute registered `ActionHandler` implementations during traversal. When a handler throws:

**Default (fire-and-forget):**

- Core writes `WorkflowLogEvent::ActionFailed` to the audit log
- Core dispatches `DbflowLabs\Core\Events\ActionFailed` with the instance, node, and exception
- Traversal **continues** to the next node so non-critical automations do not block approval flow

**Opt-in abort (`stop_on_error: true`):**

```json
{
  "type": "action",
  "config": {
    "action_key": "post_to_ledger",
    "stop_on_error": true
  }
}
```

- Core still logs and dispatches `ActionFailed`
- Core then throws `ActionExecutionFailedException`, stopping traversal before downstream nodes run
- Use for side effects that must succeed before the workflow advances (for example ERP posting)

Set `DBFLOW_EXPRESSION_STRICT=true` when condition nodes should reject invalid or missing variables instead of treating them as false.

## Runtime API Summary

Use `DbflowLabs\Core\DBFlow` as the single runtime entry point for workflow operations.

| Method | Purpose | Returns |
| --- | --- | --- |
| `start($workflowKey, $workflowable, $startedBy = null, $metadata = [])` | Create a running instance | `WorkflowInstance` |
| `approve($task, $actor = null, $comment = null)` | Approve a pending task | `WorkflowInstance` |
| `reject($task, $actor = null, $comment = null, $strategy, $targetNodeKey = null)` | Reject a pending task | `WorkflowInstance` |
| `cancel($instance, $actor = null, $comment = null)` | Cancel a running instance | `WorkflowInstance` |
| `reassign($task, $fromActor, $toUserId, $comment = null, $idempotencyKey = null, $assignmentId = null)` | Reassign a pending assignment to another user | `WorkflowInstance` |
| `createDelegation(...)` | Create a time-bounded delegation rule | `WorkflowDelegation` |
| `revokeDelegation($delegation, $revokedBy = null, $reason = null)` | Revoke an active delegation | `WorkflowDelegation` |
| `migratePendingTasksToDelegate($delegation, ...)` | Migrate matching pending tasks to the delegate | `array` |
| `registerDefinitionProvider($registry, $provider)` | Boot-time code definition registration | `void` |
| `registerAssigneeResolver($registry, $key, $resolver)` | Boot-time assignee resolver registration | `void` |
| `registerWorkflowHooks($registry, $workflowKey, $hooks)` | Boot-time lifecycle hooks | `void` |
| `registerTaskHooks($registry, $workflowKey, $hooks)` | Boot-time task-level hooks | `void` |

Registration helpers are usually called from a host service provider. Runtime actions (`start` / `approve` / `reject` / `cancel` / `reassign` / delegation APIs) are usually called from host services, controllers, or UI actions.

## Assignee Types (Runtime)

Approval nodes declare assignees under `config.assignees`. The schema lists four types; **open-core runtime support differs**:

| `assignees.type` | Supported at runtime | Notes |
| --- | --- | --- |
| `user` | Yes | Single user id in `value` (string or int). Fine for demos; use `callback` in production. |
| `callback` | Yes | `callback` (or `value`) must match a key registered via `DBFlow::registerAssigneeResolver()`. |
| `permission` (resolver alias) | Yes | `value` is a **resolver registry key**, not a Laravel Gate name or Spatie permission string. |
| `role` | **No** | Listed in the schema for forward compatibility, but **rejected by validators** during code sync. Use `callback` and resolve roles in the host. |

Examples:

```php
// Fixed user (demo / tests)
'assignees' => ['type' => 'user', 'value' => '1'],

// Host-registered resolver (roles, departments, dynamic rules)
'assignees' => ['type' => 'callback', 'callback' => 'finance_team'],

// Resolver key alias — NOT a framework permission string
'assignees' => ['type' => 'permission', 'value' => 'approve_refunds'],
```

```php
// Register the resolver key used above
DBFlow::registerAssigneeResolver(
    app(AssigneeResolverRegistry::class),
    'approve_refunds',
    new ApproveRefundsAssigneeResolver(),
);
```

Anti-pattern:

```php
// Does NOT auto-resolve Laravel / Spatie permissions
'assignees' => ['type' => 'permission', 'value' => 'approve-refunds'],
```

`WorkflowDefinitionSchema::runtimeSupportedAssigneeTypes()` is the canonical list for code-first definitions.

### Assignee Resolution Prerequisites

Before exposing a **Submit for approval** action in your UI, verify:

1. The workflow is **published** (`SyncWorkflowDefinitions` or `PublishWorkflowDraft`) and `is_enabled`
2. Every approval node can resolve to **at least one** assignee user id at runtime
3. Every `callback` / `permission` (resolver alias) key has a matching `AssigneeResolver` registered at boot

If resolution fails or the workflow is missing, `start()` throws (for example `InvalidWorkflowDefinitionException`). Core does **not** fall back to a default approver.

## Host Responsibilities

Core is a runtime engine. The following are **not** provided and must be implemented (or installed via `dbflowlabs/filament`) in the host application:

| Responsibility | Provided by Core? | Typical host implementation |
| --- | --- | --- |
| Submit / start UI | No | Filament Action, API endpoint, or service method calling `DBFlow::start()` |
| Approve / reject UI | No | Task inbox page, or `dbflowlabs/filament` |
| Withdraw / cancel UI | No | Action calling `DBFlow::cancel()` **after host authorization** |
| Business action guards | No | Before `confirm` / `post` / `ship`, check `hasRunningWorkflow()` or latest terminal status |
| Assignee configuration | No | `AssigneeResolver` implementations, deploy-time sync |
| Coexistence with other approval systems | No | Host config to choose one engine per document type |

**UI options:**

- **Minimal:** two host buttons (submit + cancel) on an edit page — Core only, no extra packages.
- **Full inbox:** install [`dbflowlabs/filament`](https://github.com/dbflow-labs/dbflow-filament) for standard Filament task UI.
- **Visual builder:** commercial `dbflowlabs/filament-pro` (see [DBFlow Ecosystem](#dbflow-ecosystem)).

Core does not know about Filament, ERP document types, or plugin mutual-exclusion switches — those remain host concerns.

## Host Integration Checklist

1. `composer require dbflowlabs/core` (pair with `dbflowlabs/filament:^1.0` when using the Filament adapter).
2. `php artisan vendor:publish --tag=dbflow-config` and set `DBFLOW_AUTH_*`.
3. `php artisan migrate` (migrations load from the package; publishing optional).
4. Implement `WorkflowDefinitionProvider`(s) and register them in a host service provider.
5. Register `AssigneeResolver`(s) for every `callback` / `permission` (resolver alias) key used in definitions.
6. Run `php artisan dbflow:sync` (or call `SyncWorkflowDefinitions` from a deploy hook).
7. Add `HasWorkflow` (+ `Workflowable` / `WorkflowContextInterface` as needed) to host models.
8. Implement host UI or services that call `DBFlow::start()` / `approve()` / `reject()` / `cancel()`.
9. Implement business guards (for example, block `confirm` while a workflow is `running`).
10. Optionally install `dbflowlabs/filament` for a standard approval inbox instead of building UI from scratch.

## Package Boundaries

DBFlow Core is intentionally small and runtime-focused.

The following are outside this package:

- Host business adapters, such as ERP-specific approval logic
- Filament resources, panels, widgets, and actions
- Visual workflow Builder UI
- Commercial Builder features
- Premium action handlers
- Enterprise white-label extensions

Unresolved premium action types raise `PremiumFeatureMissingException`.

Premium or host-specific action handlers can be registered through `ActionManager`, or provided by separate extension packages.

## DBFlow Ecosystem

DBFlow is designed as a layered ecosystem:

| Package | Role | License |
| --- | --- | --- |
| `dbflowlabs/core` | Runtime engine | MIT |
| `dbflowlabs/filament` | Standard Filament UI integration | MIT / open-source |
| `dbflowlabs/filament-pro` | Visual workflow Builder and advanced UI features | Commercial |

Core runs the workflow. Filament packages provide user interfaces. Host applications provide business adapters.

### Filament integration contract (1.0+)

Cross-package contracts for pending-task queries, runtime actions, events, and version alignment are documented in:

- [`docs/integration/filament.md`](docs/integration/filament.md) — public API surface for `dbflowlabs/filament` integrators
- [`docs/integration/acceptance-checklist.md`](docs/integration/acceptance-checklist.md) — release verification checklist

Target version pairing for **1.1**:

| Package | Constraint |
| --- | --- |
| `dbflowlabs/core` | `^1.1` |
| `dbflowlabs/filament` | `^1.1` (requires core `^1.1`) |
| `dbflowlabs/filament-pro` | `^1.1` (optional; requires core + filament `^1.1`) |

Hosts remaining on the 1.0 UI packages should stay on `dbflowlabs/core:^1.0` until Filament/Pro are upgraded together.

**Choosing a UI path:**

- Need only **submit / withdraw** on a host edit page? Implement host Filament (or Blade) actions that call `DBFlow::start()` / `cancel()` — Core alone is sufficient.
- Need a **task inbox**, approval history, and standard admin resources? Add `dbflowlabs/filament`.
- Need a **visual workflow designer**? Use `dbflowlabs/filament-pro`.

## Development

Install dependencies:

```bash
composer install
```

Validate the package metadata:

```bash
composer validate --strict --no-check-lock
```

Run the test suite:

```bash
composer test
```

The CI pipeline validates the package against PHP 8.3 and 8.4 with PHPUnit, PHPStan, and coverage gates (`runtime API ≥ 80%`, `src/ ≥ 70%`).

## API stability

From `1.0.0`, these surfaces remain frozen until the next major release:

- `DBFlow::start()`, `approve()`, `reject()`, `cancel()`, `reassign()`
- `DBFlow::register*` boot-time registration methods
- `TaskHooks` interface methods
- `WorkflowTaskQueryService` public query API (see `docs/integration/filament.md`)
- `DbflowLabs\Core\Events\*` event constructor properties

**Additive in `1.1.0`** (compatible with the 1.0 freeze; new public methods and events):

- `DBFlow::createDelegation()`, `revokeDelegation()`, `migratePendingTasksToDelegate()`
- Assignment provenance fields, SLA / reliable-action / webhook contracts documented under `docs/`
- Runtime capability gates (`RuntimeCapability`, `RuntimeCapabilityRegistry`)

Draft and builder management actions are marked `@internal` and are not covered by the stability guarantee. Use artisan commands or the Filament Builder package instead of binding those classes directly.

Automated contract tests: `EcosystemContractTest`, `PublicApiContractTest`, `V10CompatibilityTest`.

## Versioning

DBFlow Core **1.1.0** is the current stable release (built on the frozen 1.0 public API).

- From `1.0.x`: follow [UPGRADE-1.1.md](UPGRADE-1.1.md)
- From `0.x` or RC tags: follow [UPGRADE-1.0.md](UPGRADE-1.0.md), then [UPGRADE-1.1.md](UPGRADE-1.1.md)
- Review [CHANGELOG.md](CHANGELOG.md) before upgrading
- Test workflow definitions and runtime transitions in a staging environment after upgrades
- Avoid relying on `@internal` definition-management actions

## Support

For architecture alignment or integration questions, open a GitHub Issue or contact:

- **Email:** [hello@dbflow.dev](mailto:hello@dbflow.dev)
- **Website:** [dbflow.dev](https://dbflow.dev)

## License

DBFlow Core is open-sourced software licensed under the [MIT license](LICENSE).
