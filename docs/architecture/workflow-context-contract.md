# Workflow Context Contract

## Purpose

Provide a backward-compatible, namespaced workflow context model for condition evaluation, future Action templates, audit display, and UI preview—without breaking existing `WorkflowContextInterface` hosts.

## Compatibility with WorkflowContextInterface

`DbflowLabs\Core\Contracts\WorkflowContextInterface::getWorkflowVariables(): array` remains unchanged.

Stage 1.1-A adds:

- `WorkflowContextNormalizer` — adapts legacy flat variables into namespaces
- `NormalizedWorkflowContext` — immutable namespaced bag
- `ContextPathResolver` — safe dot-path access
- optional `FieldCatalogProvider` / `FieldCatalog`

Existing providers continue to work unchanged. Their variables map into the `context` namespace.

## Namespace Definitions

| Namespace | Meaning |
| --- | --- |
| `model` | Explicitly mapped workflowable fields only |
| `starter` | Normalized starter identity metadata |
| `actor` | Current actor or system actor |
| `context` | Caller/business variables (legacy home) |
| `workflow` | System workflow metadata |
| `task` | Optional task/node metadata |

## Merge Precedence

1. Core-provided `workflow` / `task` / identity metadata win.
2. Legacy `getWorkflowVariables()` populate `context`.
3. Optional user overrides may extend `context` / `model` / identity bags.
4. User overrides cannot redefine system namespaces (`workflow`, `task`) or inject namespace-shaped collisions.

## Snapshot / Live Semantics

- Default: **snapshot** (v1.0-compatible).
- Future definitions may set `context_policy.data_source = live`.
- Live requires an explicit `LiveContextProvider` and the `live_context` capability.
- Stage 1.1-A validates the field and rejects publication when the capability is missing.

## Field Catalog

Optional. Exposes path, type, label, nullable, sensitive, allowed operators, and optional enum/description/source namespace.

Labels never control runtime behavior.

## Safe Path Access

`ContextPathResolver` resolves `namespace.rest.path` from arrays only.

It distinguishes found / present-null / missing, rejects invalid syntax, prohibited segments, object/method traversal, and never evaluates code, queries the database, reads env, or resolves the container.

## Sensitive Data

Sensitive catalog fields may be used for `condition` and `action_template` purposes, but are blocked for `audit_display` and `ui_preview` unless a later stage explicitly expands policy.

## Examples

```php
$normalized = app(WorkflowContextNormalizer::class)
    ->fromWorkflowContextInterface($workflowable, workflow: [
        'key' => 'expense_approval',
        'instance_id' => 42,
    ]);

$value = app(ContextPathResolver::class)
    ->resolve($normalized, 'context.amount');
```

## Failure Behavior

Invalid context values throw `InvalidWorkflowContextException`.
Unsafe path access throws `ContextPathException` with structured error codes.

## Host Integration

1. Keep implementing `WorkflowContextInterface` for v1.0 compatibility.
2. Optionally implement `FieldCatalogProvider`.
3. Optionally implement `LiveContextProvider` once `live_context` is enabled in a later stage.

## Non-goals

- Automatic Eloquent attribute serialization
- Pro field picker UI
- Live refresh execution runtime
- Persisting closures, models, or services
