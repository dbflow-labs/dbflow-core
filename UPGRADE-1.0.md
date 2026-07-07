# Upgrading to DBFlow Core 1.0

This guide summarizes breaking and behavioral changes from early alpha releases through **stable `1.0.0`**. For full release notes, see [CHANGELOG.md](CHANGELOG.md).

## Stable 1.0.0

`1.0.0` is the first stable release. The runtime public API has been frozen since `1.0.0-rc.1`; upgrading from the RC tag requires **no breaking code changes** for hosts on the documented contract.

```bash
composer require dbflowlabs/core:^1.0
```

Pair with `dbflowlabs/filament:^1.0` and `dbflowlabs/filament-pro:^1.0` when using the Filament ecosystem packages.

Additive changes since `1.0.0-rc.1` (non-breaking):

- `ActionFailed` event and `WorkflowLogEvent::ActionFailed` when action node handlers throw
- Optional action node `fail_on_exception` to abort traversal via `ActionExecutionFailedException`
- Sequential approval/rejection edge-case fixes and stricter condition expression evaluation

## Recommended upgrade path

Upgrade in order when jumping multiple prereleases:

1. `0.2.0-alpha.1` — `DBFLOW_ENABLED` runtime gate
2. `0.3.0-alpha.1` — string user IDs, Laravel events, `dbflow:sync` / `dbflow:validate`
3. `0.3.1-alpha.1` — cancel audit logs, `DBFLOW_ENABLED=false` artisan availability
4. `0.4.0-alpha.1` — `DBFlow::reassign()` and `TaskHooks::onReassigned()`
5. `0.5.0-alpha.1` — approval timeouts and `dbflow:process-timeouts`
6. `0.9.0-beta.1` — Filament integration contract (`WorkflowTaskQueryService`)
7. `1.0.0-rc.1` — API freeze (see [API stability](#api-stability-at-100))
8. `1.0.0` — first stable release (no breaking changes from RC)

For new projects or RC adopters ready to move to stable:

```bash
composer require dbflowlabs/core:^1.0
```

Pair with `dbflowlabs/filament:^1.0` when using the Filament adapter.

## API stability at 1.0.0

From `1.0.0-rc.1` (unchanged at stable `1.0.0`), the following surfaces are **frozen** until the next major:

| Surface | Location |
| --- | --- |
| Runtime facade | `DBFlow::start()`, `approve()`, `reject()`, `cancel()`, `reassign()` |
| Registration API | `DBFlow::register*` methods |
| Task hooks | `TaskHooks` interface |
| Pending-task queries | `WorkflowTaskQueryService` public methods |
| Integration events | `DbflowLabs\Core\Events\*` constructor properties (see `EcosystemContractTest`) |
| Database schema | No breaking column/type changes without a new major |

**Not part of the stable public API** (marked `@internal`):

- Draft / builder management actions (`CreateWorkflowDraft`, `PublishWorkflowDraft`, `SyncWorkflowDefinitions`, etc.)
- Host applications should use Filament Builder or artisan commands rather than binding these actions directly.

## Breaking changes by release

### 0.4.0-alpha.1

- **`TaskHooks`:** implementers must add `onReassigned(WorkflowTask $task, WorkflowInstance $instance, mixed $actor, string $toUserId): void`.
- **Assignments:** treat `reassigned` as a terminal assignment status in custom queries.

### 0.3.0-alpha.1

- **User ID columns:** `started_by_user_id`, `assignee_user_id`, and `actor_user_id` are `VARCHAR(64)` strings without foreign keys to `users`.
- **Model casts:** user id attributes are cast to `string`, not `integer`.
- **Migration required:** run `php artisan migrate` after upgrading.
- **Host config:** set `DBFLOW_AUTH_MODEL` (and optionally `DBFLOW_AUTH_TABLE` / `dbflow.auth.*`).
- **Sync command:** replace host-specific definition sync with `php artisan dbflow:sync`.

### 0.2.0-alpha.1

- **`DBFLOW_ENABLED`:** when `config('dbflow.enabled')` is `false`, runtime APIs throw `WorkflowNotAvailableException`; `HasWorkflow` does not auto-start workflows.
- **Validator namespace:** use `DbflowLabs\Core\Validation\WorkflowDefinitionValidator` instead of `Services\WorkflowDefinitionValidator` (removed).

### 0.3.1-alpha.1 (behavioral, not schema-breaking)

- **`DBFLOW_ENABLED=false`:** `dbflow:sync` and `dbflow:validate` remain available; only runtime actions are blocked.
- **`CancelWorkflow`:** writes per-task `TaskCancelled` audit entries before the instance-level cancel log.

## Non-breaking additions (adopt when ready)

### 0.5.0-alpha.1

- Approval node `config.timeout.due_in` and `php artisan dbflow:process-timeouts`.
- Schedule the timeout command when using approval deadlines.
- **Filament / Pro:** from `dbflowlabs/filament:1.0.0-rc.2` and matching Pro worktrees, the standard form and Pro canvas editors round-trip `timeout` configuration without data loss.

### 0.9.0-beta.1

- `WorkflowTaskQueryService::pendingAssignmentsQueryForUser()` for Filament tables.
- Eager-load `workflowTask.workflowInstance.workflowable` on pending-task queries.
- Integration contract: `docs/integration/filament.md`.

## Host integration checklist (1.0)

- [ ] Pin `dbflowlabs/core:^1.0` (and matching Filament tag if applicable).
- [ ] Run migrations on a staging database.
- [ ] Update custom `TaskHooks` classes with `onReassigned()` if not already done.
- [ ] Route runtime actions through `DBFlow::` facade methods, not direct action instantiation.
- [ ] Use `string` user IDs in assignee and actor resolution.
- [ ] Schedule `dbflow:process-timeouts` when using approval timeouts.
- [ ] Read `docs/integration/filament.md` for adapter integration.

## Support

- [CHANGELOG.md](CHANGELOG.md) — detailed release history
- [GitHub Issues](https://github.com/dbflow-labs/dbflow-core/issues) — bug reports and questions
