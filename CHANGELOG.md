# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.2.0-alpha.1]: https://github.com/dbflow-labs/dbflow-core/compare/0.1.0-alpha.1...0.2.0-alpha.1
