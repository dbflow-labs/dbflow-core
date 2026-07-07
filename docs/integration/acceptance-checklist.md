# 0.9-beta integration acceptance checklist

Use this checklist when validating `dbflowlabs/core` `0.9.x-beta` with `dbflowlabs/filament` `0.9.x-beta` (path or tagged dependencies).

Mark each item in the Filament PR / release notes. Core-only items are verified by Core CI (`EcosystemContractTest`).

---

## Core contract (automated)

- [x] `WorkflowTaskQueryService` public method signatures frozen (`EcosystemContractTest`)
- [x] Documented eager-load paths match `getPendingTasksForUser()` implementation
- [x] All `DbflowLabs\Core\Events\*` classes documented in [filament.md](filament.md)

---

## Filament — pending inbox

- [x] Inbox list uses `WorkflowTaskQueryService` or equivalent filter + eager loads (including `workflowVersion`)
- [x] Badge count uses `countPendingTasksForUser(string $userId)` — available; Filament table uses full list query (badge widgets: host calls service)
- [x] Assignee id passed as `string` (UUID/ULID safe)

---

## Filament — runtime actions

| Path | Core API | Verified |
| --- | --- | --- |
| Approve from inbox | `DBFlow::approve()` or equivalent container binding | [x] |
| Reject from inbox | `DBFlow::reject()` | [x] |
| Cancel from host / instance UI | `DBFlow::cancel()` after host policy | [x] |
| Reassign from inbox or detail | `DBFlow::reassign()` | [x] |
| Submit / start from host resource | `DBFlow::start()` | [ ] (host responsibility) |

- [x] No direct SQL/eloquent updates to `dbflow_workflow_task_assignments.status` for approve/reject/reassign
- [x] `DBFLOW_ENABLED=false` hides or disables runtime actions in UI (consistent with Core)

---

## Filament — links and notifications

- [x] Workflowable link column uses `WorkflowRouteResolvable::getWorkflowShowUrl()` when implemented
- [x] `null` URL degrades without error (text-only subject label)
- [ ] Optional: notification listeners documented for `TaskTimedOut` vs `TaskRejected`

---

## Cross-package CI

- [x] `dbflow-filament`: `composer test` green against Core `0.9.x-beta` (path dependency)
- [x] `dbflow-filament-pro`: `composer test` green (322/322; path dependency to Core/Filament 0.9 worktrees; no Pro code changes required)
- [x] Version constraint updated in Filament `composer.json` to `^0.9.0-beta`
- [x] Both packages CHANGELOG entries for coordinated beta tag

---

## Sign-off

| Role | Name | Date |
| --- | --- | --- |
| Core | | |
| Filament | | |

When all Filament rows are checked, tag `0.9.0-beta.1` on both repositories and link releases in CHANGELOG.

---

## 1.0.0-rc integration (post API freeze)

Use path or tagged dependencies: Core `1.0.0-rc.1`, Filament `1.0.0-rc.2+`, Pro path-linked to both.

### Filament — definition editor (Core 0.5 alignment)

- [x] Standard approval step form round-trips `config.timeout.due_in` and `config.timeout.on_timeout`
- [x] Timeline presents `task_reassigned` and `task_timed_out` audit labels

### Filament — links and notifications (RC follow-up)

- [x] Timeline labels for `task_reassigned` and `task_timed_out`
- [ ] Optional: host notification guide for `TaskTimedOut` vs `TaskRejected` (README / docs site)

### Cross-package CI (RC)

- [x] `dbflow-filament`: `composer test` green (139 tests) on `1.0.0-rc.2` worktree with Core `1.0.0-rc.1`
- [x] `dbflow-filament-pro`: `composer test` green (323 tests) with path-linked Core/Filament RC worktrees; approval timeout round-trip covered
- [x] `docs/integration/filament.md` documents timeout editor fields

### Sign-off (RC)

| Role | Name | Date |
| --- | --- | --- |
| Core | | |
| Filament | | |
| Pro | | |

Pair `dbflowlabs/filament:1.0.0-rc.2` with `dbflowlabs/core:1.0.0-rc.1` until stable `1.0.0`.

---

## 1.0.0 stable integration

Use path or tagged dependencies: Core `1.0.0`, Filament `1.0.0`, Pro `1.0.0`.

### Cross-package CI (stable)

- [x] `dbflow-core`: 152 PHPUnit, PHPStan L4, coverage gates (runtime 82.5% / src 70.1%)
- [x] `dbflow-filament`: `composer test` green (140 tests) with path-linked Core `1.0.0`
- [x] `dbflow-filament-pro`: `composer test` green (323 tests) with path-linked Core + Filament `1.0.0`
- [x] Version constraints updated to `^1.0` on Core / Filament / Pro `composer.json`
- [x] No breaking Filament or Pro code changes required for stable Core

### Sign-off (stable)

| Role | Name | Date |
| --- | --- | --- |
| Core | integration CI | 2026-07-07 |
| Filament | integration CI | 2026-07-07 |
| Pro | integration CI | 2026-07-07 |

Pair `dbflowlabs/filament:1.0.0` with `dbflowlabs/core:1.0.0` and `dbflowlabs/filament-pro:1.0.0`.
