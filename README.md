# DBFlow Core

[![Tests](https://github.com/dbflow-labs/dbflow-core/actions/workflows/tests.yml/badge.svg)](https://github.com/dbflow-labs/dbflow-core/actions)
[![Latest Release](https://img.shields.io/github/v/release/dbflow-labs/dbflow-core?include_prereleases)](https://github.com/dbflow-labs/dbflow-core/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4-777bb4.svg)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-13.x-ff2d20.svg)](composer.json)

**Model-first workflow runtime for Laravel applications.**

DBFlow Core lets you add approval workflows, tasks, transitions, rejection flows, and audit logs to any Eloquent model without building a heavy BPM system from scratch.

It is the open-source runtime foundation of the DBFlow ecosystem. Host-specific business adapters, Filament UI packages, and the visual workflow Builder are distributed separately.

> [!WARNING]
> DBFlow Core is currently in alpha. Public APIs and database schema details may change before v1.0.0. Pin exact tags for production experiments.

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
| **Stability** | `Alpha (v0.3.x)` |
| **Author** | Baron Wang <hello@dbflow.dev> |
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
- **Extension points** — assignee resolvers, workflow hooks, condition handling, and action handlers.

> [!NOTE]
> Core focuses entirely on the workflow runtime engine. It contains no frontend assets, Filament resources, visual canvas, or host-specific business models.

## Requirements

- PHP `^8.3`
- Laravel `13.x` / Illuminate `^13.0` components
- SQLite, MySQL, or PostgreSQL
- A host user model, usually `App\Models\User`
- A user table (or equivalent) for actor and assignee references; UUID/ULID primary keys are supported in v0.3+

## Installation

### Packagist Installation

```bash
composer require dbflowlabs/core:0.9.0-beta.1
```

Until a stable `1.0.0` release, Packagist may only publish prerelease tags. If Composer reports that no **stable** version matches `minimum-stability`, pin an explicit alpha tag (as above) or temporarily allow prereleases in the host `composer.json`.

Releases are tagged on GitHub, for example:

```text
v0.9.0-beta.1
```

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
        'resolver' => DbflowLabs\Core\Support\ConfigUserResolver::class,
    ],
    'visual_builder_enabled' => env('DBFLOW_VISUAL_BUILDER_ENABLED', false),
];
```

**Host example** — typical Laravel app after publish (you may add `env()` fallbacks in *your* copy only):

```php
'auth' => [
    'model' => env('DBFLOW_AUTH_MODEL', 'App\\Models\\User'),
    'guard' => env('DBFLOW_AUTH_GUARD', 'web'),
    'resolver' => DbflowLabs\Core\Support\ConfigUserResolver::class,
],
```

Recommended `.env` for that host example:

```env
DBFLOW_ENABLED=true
DBFLOW_BINDING_MODE=code
DBFLOW_AUTH_MODEL=App\Models\User
DBFLOW_AUTH_MODEL=App\\Models\\User
DBFLOW_AUTH_TABLE=users
DBFLOW_AUTH_GUARD=web
DBFLOW_EXPRESSION_STRICT=false
```

`ConfigUserResolver` supports integer and string primary keys at runtime. User references are stored as strings in `dbflow_*` tables.

> [!NOTE]
> Set `DBFLOW_ENABLED=false` to disable the workflow **runtime**. When disabled, `DBFlow::start()` / `approve()` / `reject()` / `cancel()` / `reassign()` throw `WorkflowNotAvailableException`, `dbflow:process-timeouts` fails, and runtime action bindings (`StartWorkflow`, etc.) are not registered. Definition-management bindings remain available (`registerDefinitionProvider`, `registerAssigneeResolver`, etc.), migrations still load, and `php artisan dbflow:sync` / `dbflow:validate` remain registered so hosts can sync or validate definitions before re-enabling.

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

Process overdue approval tasks (schedule via cron, for example every 5 minutes):

```bash
php artisan dbflow:process-timeouts
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

When `binding_mode` is `ui`, matching published workflows with a `model_type` binding may auto-start on `Model::created`. ERP-style hosts usually keep `code` and trigger from business actions (submit, confirm, etc.).

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
> **Metadata contract (alpha):** Core persists the entire `$metadata` array on the workflow instance. It does not assign special meaning to keys such as `submit_comment` — naming conventions are **host-defined**. For condition nodes, prefer `WorkflowContextInterface::getWorkflowVariables()`; otherwise pass `metadata['variables']`.

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

### 8. Task Timeouts

Approval nodes may declare an optional timeout in workflow definition `config`:

```json
{
  "timeout": {
    "due_in": "P3D",
    "on_timeout": "reject_end"
  }
}
```

- `due_in` — ISO 8601 duration (for example `PT24H`, `P3D`). When a task is created, Core writes `workflow_tasks.due_at`.
- `on_timeout` — optional. Only `reject_end` is supported in the MVP. When omitted, overdue tasks are **logged only** and remain `pending` (assignees may still approve manually).

Schedule the command:

```bash
php artisan dbflow:process-timeouts
```

**What Core does:**

- Writes `WorkflowLogEvent::TaskTimedOut` once per overdue task and dispatches `TaskTimedOut`
- When `on_timeout: reject_end`, auto-rejects with `RejectStrategy::End` (system actor is `null` in audit logs)

**What Core does *not* do:**

- No reminders before due date
- No escalation chains or `auto_approve`
- No silent auto-reject when `on_timeout` is omitted

### 9. Query Workflow State on Models

Models using `HasWorkflow` can inspect runtime state without raw SQL:

```php
$order->hasRunningWorkflow('refund_approval');
$order->runningWorkflowInstance('refund_approval');
$order->completedWorkflowInstance('refund_approval');
$order->workflowLogs('refund_approval');
```

Use these helpers in host guards (for example, disable a Filament **Confirm** action while `hasRunningWorkflow()` is true).

## Runtime API Summary

Use `DbflowLabs\Core\DBFlow` as the single runtime entry point during alpha.

| Method | Purpose | Returns |
| --- | --- | --- |
| `start($workflowKey, $workflowable, $startedBy = null, $metadata = [])` | Create a running instance | `WorkflowInstance` |
| `approve($task, $actor = null, $comment = null)` | Approve a pending task | `WorkflowInstance` |
| `reject($task, $actor = null, $comment = null, $strategy, $targetNodeKey = null)` | Reject a pending task | `WorkflowInstance` |
| `cancel($instance, $actor = null, $comment = null)` | Cancel a running instance | `WorkflowInstance` |
| `reassign($task, $fromActor, $toUserId, $comment = null)` | Reassign a pending assignment to another user | `WorkflowInstance` |
| `registerDefinitionProvider($registry, $provider)` | Boot-time code definition registration | `void` |
| `registerAssigneeResolver($registry, $key, $resolver)` | Boot-time assignee resolver registration | `void` |
| `registerWorkflowHooks($registry, $workflowKey, $hooks)` | Boot-time lifecycle hooks | `void` |
| `registerTaskHooks($registry, $workflowKey, $hooks)` | Boot-time task-level hooks | `void` |

Registration helpers are usually called from a host service provider. Runtime actions (`start` / `approve` / `reject` / `cancel` / `reassign`) are usually called from host services, controllers, or UI actions.

## Assignee Types (Runtime)

Approval nodes declare assignees under `config.assignees`. The schema lists four types; **open-core runtime support differs**:

| `assignees.type` | Supported at runtime (alpha) | Notes |
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

1. `composer require dbflowlabs/core:0.9.0-beta.1` (or pin the latest alpha tag).
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

### Filament integration contract (0.9-beta+)

Cross-package contracts for pending-task queries, runtime actions, events, and version alignment are documented in:

- [`docs/integration/filament.md`](docs/integration/filament.md) — public API surface for `dbflowlabs/filament` integrators
- [`docs/integration/acceptance-checklist.md`](docs/integration/acceptance-checklist.md) — release verification checklist

Target version pairing: `dbflowlabs/filament` `0.9.x-beta` requires `dbflowlabs/core` `^0.9.0-beta`; stable `1.0.0` pairs require `^1.0.0` on both packages.

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

The CI pipeline validates the package against PHP 8.3 and 8.4 with PHPUnit and architecture compliance checks.

## Versioning

DBFlow Core is currently in active alpha development.

Until a stable `1.0.0` release is reached, public APIs and schema definitions may change as the runtime contract stabilizes.

Recommended production usage during alpha:

- Pin exact tags, such as `v0.9.0-beta.1`
- Review release notes before upgrading
- Test workflow definitions and runtime transitions in a staging environment
- Avoid relying on undocumented internal classes

## Support

For architecture alignment or integration questions, open a GitHub Issue or contact:

- **Email:** [hello@dbflow.dev](mailto:hello@dbflow.dev)
- **Website:** [dbflow.dev](https://dbflow.dev)

## License

DBFlow Core is open-sourced software licensed under the [MIT license](LICENSE).
