# DBFlow Core

**Model-first workflow runtime for Laravel applications.**

DBFlow Core lets you add approval workflows, tasks, transitions, rejection flows, and audit logs to any Eloquent model without building a heavy BPM system from scratch.

It is the open-source runtime foundation of the DBFlow ecosystem. Host-specific business adapters, Filament UI packages, and the visual workflow Builder are distributed separately.

## Package Overview


| Item                      | Value                                                                                          |
| ------------------------- | ---------------------------------------------------------------------------------------------- |
| **Package name**          | `dbflowlabs/core`                                                                              |
| **Namespace**             | `DbflowLabs\Core`                                                                              |
| **License**               | [MIT](https://chatgpt.com/g/g-p-6a336005778c8191841e2be1fea679a9-dbflowgong-zuo-liu/c/LICENSE) |
| **Repository**            | [github.com/dbflow-labs/dbflow-core](https://github.com/dbflow-labs/dbflow-core)               |
| **Default branch**        | `main`                                                                                         |
| **Stability**             | `Alpha (v0.1.x)`                                                                               |
| **Author**                | Baron Wang [hello@dbflow.dev](mailto:hello@dbflow.dev)                                         |
| **Laravel compatibility** | `13.x`                                                                                         |
| **PHP requirements**      | `8.3`, `8.4`                                                                                   |


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

When the package is available on Packagist:

```bash
composer require dbflowlabs/core
```

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

During local package development, migrations may also be loaded directly through Laravel's `loadMigrationsFrom()` behavior.

## Configuration

Example `config/dbflow.php`:

```php
return [
    'enabled' => env('DBFLOW_ENABLED', true),

    'auth' => [
        'model' => env('DBFLOW_AUTH_MODEL', 'App\Models\User'),
        'guard' => env('DBFLOW_AUTH_GUARD', 'web'),
        'resolver' => DbflowLabs\Core\Support\ConfigUserResolver::class,
    ],

    'visual_builder_enabled' => env('DBFLOW_VISUAL_BUILDER_ENABLED', false),
];
```

Set the host user model explicitly:

```env
DBFLOW_AUTH_MODEL=App\Models\User
```

`ConfigUserResolver` supports integer and string primary keys at runtime.

> [!NOTE]  
> `DBFLOW_ENABLED` does not fully disable service provider registration yet during the alpha cycle.

## Minimal Usage

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

### 2. Start a Workflow

Start a workflow for an Eloquent model:

```php
use DbflowLabs\Core\DBFlow;

$instance = DBFlow::start(
    'refund_approval',
    $refundRequest,
    auth()->user(),
);
```

### 3. Approve a Task

Approve a workflow task:

```php
DBFlow::approve(
    $task,
    auth()->user(),
    'Approved.',
);
```

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


| Package                   | Role                                             | License           |
| ------------------------- | ------------------------------------------------ | ----------------- |
| `dbflowlabs/core`         | Runtime engine                                   | MIT               |
| `dbflowlabs/filament`     | Standard Filament UI integration                 | MIT / open-source |
| `dbflowlabs/filament-pro` | Visual workflow Builder and advanced UI features | Commercial        |


Core runs the workflow. Filament packages provide user interfaces. Host applications provide business adapters.

## Development

Install dependencies:

```bash
composer install
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
- **Website:** [dbflow.dev](https://dbflow.dev/)

## License

DBFlow Core is open-sourced software licensed under the [MIT license](https://chatgpt.com/g/g-p-6a336005778c8191841e2be1fea679a9-dbflowgong-zuo-liu/c/LICENSE).