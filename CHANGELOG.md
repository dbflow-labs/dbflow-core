# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0-alpha.1] - 2026-07-07

### Added

- Approval node `config.timeout.due_in` (ISO 8601 duration) with runtime `workflow_tasks.due_at` assignment on task creation.
- `php artisan dbflow:process-timeouts` to audit overdue tasks and optionally auto-reject when `on_timeout: reject_end`.
- `WorkflowLogEvent::TaskTimedOut` audit logging and `TaskTimedOut` Laravel event.
- `dbflow:validate` checks for invalid timeout configuration.

### Documentation

- README timeout configuration and scheduler guidance.

### Upgrade notes

- Pin `dbflowlabs/core:0.5.0-alpha.1` (or `^0.4.0-alpha.1` if you already allow prereleases).
- Schedule `php artisan dbflow:process-timeouts` (for example every 15 minutes) when using approval timeouts.
- When `DBFLOW_ENABLED=false`, `dbflow:process-timeouts` fails; `dbflow:sync` and `dbflow:validate` remain available.
- Overdue tasks without `on_timeout` are audited only and stay `pending` until manually approved or rejected.

## [0.4.0-alpha.1] - 2026-07-07

### Added

- `DBFlow::reassign($task, $fromActor, $toUserId, $comment = null)` runtime API.
- `ReassignTask` action with `TaskReassigned` Laravel event and `WorkflowLogEvent::TaskReassigned` audit logging.
- `TaskHooks::onReassigned()` extension point.
- `WorkflowTaskAssignmentStatus::Reassigned` assignment status.
- `UserCannotReassignTaskException` for authorization and validation failures.
- Feature tests for Any / All / Sequential reassign flows and disabled-runtime guard.

### Changed

- **BREAKING:** `TaskHooks` implementers must add `onReassigned()` (or extend `NullTaskHooks` patterns).

### Documentation

- README Runtime API and reassign usage section.

### Upgrade notes

- Pin `dbflowlabs/core:0.4.0-alpha.1` (or `^0.3.0-alpha.1` if you already allow prereleases).
- Custom `TaskHooks` classes require a new `onReassigned()` method.
- Queries against `dbflow_workflow_task_assignments` should treat `reassigned` as a terminal assignment status.

## [0.3.1-alpha.1] - 2026-07-07

### Added

- `CancelWorkflow` Feature Tests (state transitions, per-task `TaskCancelled` audit, idempotent cancel).
- Feature tests for `DBFLOW_ENABLED=false`: runtime API exceptions and available `dbflow:sync` / `dbflow:validate`.
- UUID assignee coverage for `WorkflowTaskQueryService`.
- CI outputs PHPUnit line coverage via pcov (no threshold).

### Changed

- **`WorkflowTaskQueryService`:** `getPendingTasksForUser()` and `countPendingTasksForUser()` now accept `string $userId` (aligned with v0.3 user ID columns).
- **`CancelWorkflow`:** writes `WorkflowLogEvent::TaskCancelled` for each pending task before the instance-level `WorkflowCancelled` log.
- **`DBFLOW_ENABLED=false`:** runtime action bindings are skipped, but definition-management bindings, migrations, and `dbflow:sync` / `dbflow:validate` remain available.

### Documentation

- README updated for `DBFLOW_ENABLED` contract and cancel audit behavior.

### Upgrade notes

- Pin `dbflowlabs/core:0.3.1-alpha.1` (or `^0.3.0-alpha.1` if you already allow prereleases).
- If you relied on `DBFLOW_ENABLED=false` blocking `dbflow:sync` / `dbflow:validate`, those commands are now available while the runtime remains disabled.

## [0.3.0-alpha.1] - 2026-07-06

### Added

- `WorkflowNodeTraverser` for shared action-chain execution in `StartWorkflow` and `ApproveTask`.
- Laravel events: `WorkflowStarted`, `WorkflowCompleted`, `WorkflowCancelled`, `WorkflowRejected`, `TaskCreated`, `TaskApproved`, `TaskRejected`.
- Task-level `TaskHooks` contract and `DBFlow::registerTaskHooks()`.
- `php artisan dbflow:sync` (`--dry-run`, `--workflow=`) and `php artisan dbflow:validate` (`--strict`, `--workflow=`, `--source=`).
- `DBFLOW_EXPRESSION_STRICT` and `ExpressionEvaluator::validateSyntax()` for condition expression validation.
- `dbflow.auth.table` and `dbflow.auth.connection` configuration keys.
- `UserResolver::table()` and `DbflowAuth::userTable()`.

### Changed

- **BREAKING:** `started_by_user_id`, `assignee_user_id`, and `actor_user_id` are now `VARCHAR(64)` columns without foreign keys to `users`.
- `draft_updated_by` and `published_by` metadata columns also store string user references.
- Model casts for user id columns changed from `integer` to `string`.
- `SyncWorkflowDefinitions::handle()` accepts optional `$workflowKey` and `$dryRun` parameters.

### Upgrade notes

- Pin `dbflowlabs/core:0.3.0-alpha.1`.
- Run `php artisan migrate` after upgrading.
- Set `DBFLOW_AUTH_MODEL` (and optionally `DBFLOW_AUTH_TABLE`) for your host user model.
- Replace host-specific sync commands with `php artisan dbflow:sync`.
- Code comparing `assignee_user_id` to integer literals should use string ids or resolved actor ids.

## [0.2.0-alpha.1] - 2026-07-06

### Added

- `DbflowRuntime` helper with `isEnabled()` and `ensureEnabled()` for centralized runtime gating.
- Feature tests for Any/All approval modes (`ApprovalMode::Any`, `ApprovalMode::All`).
- Feature tests for `WorkflowTaskQueryService` (pending assignments, counts, eager loading).
- Feature tests for enabled/disabled `DBFLOW_ENABLED` configuration.
- PHPStan static analysis (level 4) in CI via `composer phpstan`.
- Tracked `composer.lock` for reproducible installs.

### Changed

- **`DBFLOW_ENABLED` is now a real runtime switch.** When `config('dbflow.enabled')` is `false`:
  - `DBFlowServiceProvider` skips container bindings and migrations.
  - `DBFlow::start()`, `approve()`, `reject()`, and `cancel()` throw `WorkflowNotAvailableException`.
  - `HasWorkflow` no longer auto-starts workflows on model creation.
- `DBFlowServiceProvider` preserves host `dbflow.enabled` config across `mergeConfigFrom()`.
- README documents runtime API, host responsibilities, and the updated `DBFLOW_ENABLED` behavior.

### Removed

- Deprecated `DbflowLabs\Core\Services\WorkflowDefinitionValidator` wrapper.
  Use `DbflowLabs\Core\Validation\WorkflowDefinitionValidator` instead.

### Fixed

- Garbled comment characters in `ExpressionEvaluator`, `WorkflowTaskQueryService`, and `WorkflowDefinitionDiffBuilder`.

### Upgrade notes

- Pin `dbflowlabs/core:0.2.0-alpha.1` or `^0.2.0-alpha.1`.
- If you previously set `DBFLOW_ENABLED=false` but still called runtime APIs, expect `WorkflowNotAvailableException` after upgrading.
- Replace imports of `Services\WorkflowDefinitionValidator` with `Validation\WorkflowDefinitionValidator`.

[0.4.0-alpha.1]: https://github.com/dbflow-labs/dbflow-core/compare/0.3.1-alpha.1...0.4.0-alpha.1
[0.3.1-alpha.1]: https://github.com/dbflow-labs/dbflow-core/compare/0.3.0-alpha.1...0.3.1-alpha.1
[0.3.0-alpha.1]: https://github.com/dbflow-labs/dbflow-core/compare/0.2.0-alpha.1...0.3.0-alpha.1
[0.2.0-alpha.1]: https://github.com/dbflow-labs/dbflow-core/compare/0.1.0-alpha.1...0.2.0-alpha.1
