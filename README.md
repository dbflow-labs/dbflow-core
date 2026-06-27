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
- [Assignee Types (Runtime)](#assignee-types-runtime)
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
| **Stability** | `Alpha (v0.1.x)` |
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
- A `users` table, or equivalent host table, for actor and assignee references

> [!WARNING]
> **Alpha compatibility note:** Actor and assignee database columns currently use unsigned `bigint` foreign keys mapping to `users.id`. Applications using UUID or ULID primary keys for users should plan a compatibility migration or customize their mappings before production deployment.

## Installation

### Packagist Installation

```bash
composer require dbflowlabs/core:0.1.0-alpha.1
```

Until a stable `1.0.0` release, Packagist may only publish prerelease tags. If Composer reports that no **stable** version matches `minimum-stability`, pin an explicit alpha tag (as above) or temporarily allow prereleases in the host `composer.json`.

### Local Path Repository

For local development, add a Composer path repository inside your host application's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../dbflow-core",
      "options": {
        "symlink": true
      }
    }
  ],
  "minimum-stability": "dev",
  "prefer-stable": true,
  "require": {
    "dbflowlabs/core": "*@dev"
  }
}
```

Then install the package:

```bash
composer require dbflowlabs/core:*@dev
```

Releases are tagged on GitHub, for example:

```text
v0.1.0-alpha.1
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
DBFLOW_AUTH_GUARD=web
```

`ConfigUserResolver` supports integer and string primary keys at runtime.

> [!WARNING]
> `DBFLOW_ENABLED` does not fully disable Laravel package discovery or `DBFlowServiceProvider` registration yet during the alpha cycle. Host applications that need a hard off-switch should guard their own registration calls (definition providers, assignee resolvers, and sync commands) with `config('dbflow.enabled')`.

## Minimal Usage

Code-first integration follows four steps: **register → sync → attach model → run**.

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

Call `SyncWorkflowDefinitions` from a deploy hook, host Artisan command, or one-time setup task:

```php
use DbflowLabs\Core\Actions\SyncWorkflowDefinitions;

/** @var array{created: list<string>, updated: list<string>, unchanged: list<string>} $summary */
$summary = app(SyncWorkflowDefinitions::class)->handle();
```

Core does not ship a first-party `dbflow:sync` Artisan command during alpha. Host applications should expose their own command (for example `app:dbflow-sync-workflows`) that wraps the action above.

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
);
```

### 5. Approve a Task

Approve a workflow task:

```php
DBFlow::approve(
    $task,
    auth()->user(),
    'Approved.',
);
```

Reject and cancel entry points are also available on `DbflowLabs\Core\DBFlow` (`reject()`, `cancel()`).

## Assignee Types (Runtime)

Approval nodes declare assignees under `config.assignees`. The schema lists four types; **open-core runtime support differs**:

| `assignees.type` | Supported at runtime (alpha) | Notes |
| --- | --- | --- |
| `user` | Yes | Single user id in `value` (string or int). |
| `callback` | Yes | `callback` (or `value`) must match a key registered via `DBFlow::registerAssigneeResolver()`. |
| `permission` | Yes* | `value` is a **resolver registry key**, not a framework permission string. Register an `AssigneeResolver` under that same key. |
| `role` | **No** | Accepted in the schema for forward compatibility, but **rejected by validators** for code sync and unsupported by `AssigneeResolverRegistry`. Use `callback` and resolve roles in the host. |

Examples:

```php
// Fixed user
'assignees' => ['type' => 'user', 'value' => '1'],

// Host-registered resolver (roles, departments, dynamic rules)
'assignees' => ['type' => 'callback', 'callback' => 'finance_team'],

// Resolver key alias (register AssigneeResolver under "approve_refunds")
'assignees' => ['type' => 'permission', 'value' => 'approve_refunds'],
```

`WorkflowDefinitionSchema::runtimeSupportedAssigneeTypes()` is the canonical list for code-first definitions.

## Host Integration Checklist

1. `composer require dbflowlabs/core:0.1.0-alpha.1` (or pin the latest alpha tag).
2. `php artisan vendor:publish --tag=dbflow-config` and set `DBFLOW_AUTH_*`.
3. `php artisan migrate` (migrations load from the package; publishing optional).
4. Implement `WorkflowDefinitionProvider`(s) and register them in a host service provider.
5. Register `AssigneeResolver`(s) for every `callback` / `permission` key used in definitions.
6. Run `SyncWorkflowDefinitions` (host Artisan command or deploy hook).
7. Add `HasWorkflow` (+ `Workflowable` / `WorkflowContextInterface` as needed) to host models.
8. Call `DBFlow::start()` / `approve()` / `reject()` from host business actions.
9. Optionally install `dbflowlabs/filament` for admin UI (Core has no UI).

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

- Pin exact tags, such as `v0.1.0-alpha.1`
- Review release notes before upgrading
- Test workflow definitions and runtime transitions in a staging environment
- Avoid relying on undocumented internal classes

## Support

For architecture alignment or integration questions, open a GitHub Issue or contact:

- **Email:** [hello@dbflow.dev](mailto:hello@dbflow.dev)
- **Website:** [dbflow.dev](https://dbflow.dev)

## License

DBFlow Core is open-sourced software licensed under the [MIT license](LICENSE).
