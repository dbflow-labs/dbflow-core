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

- [ ] Inbox list uses `WorkflowTaskQueryService` or equivalent filter + eager loads (including `workflowVersion`)
- [ ] Pagination respects `perPage` default 10 / max 50 guidance
- [ ] Badge count uses `countPendingTasksForUser(string $userId)`
- [ ] Assignee id passed as `string` (UUID/ULID safe)

---

## Filament — runtime actions

| Path | Core API | Verified |
| --- | --- | --- |
| Approve from inbox | `DBFlow::approve()` or equivalent container binding | [ ] |
| Reject from inbox | `DBFlow::reject()` | [ ] |
| Cancel from host / instance UI | `DBFlow::cancel()` after host policy | [ ] |
| Reassign from inbox or detail | `DBFlow::reassign()` | [ ] |
| Submit / start from host resource | `DBFlow::start()` | [ ] |

- [ ] No direct SQL/eloquent updates to `dbflow_workflow_task_assignments.status` for approve/reject/reassign
- [ ] `DBFLOW_ENABLED=false` hides or disables runtime actions in UI (consistent with Core)

---

## Filament — links and notifications

- [ ] Workflowable link column uses `WorkflowRouteResolvable::getWorkflowShowUrl()` when implemented
- [ ] `null` URL degrades without error (text-only subject label)
- [ ] Optional: notification listeners documented for `TaskTimedOut` vs `TaskRejected`

---

## Cross-package CI

- [ ] `dbflow-filament`: `composer test` green against Core `0.9.x-beta` (path dependency)
- [ ] `dbflow-filament-pro`: `composer test` green (or N/A with documented exception)
- [ ] Version constraint updated in Filament `composer.json` to `^0.9.0-beta`
- [ ] Both packages CHANGELOG entries for coordinated beta tag

---

## Sign-off

| Role | Name | Date |
| --- | --- | --- |
| Core | | |
| Filament | | |

When all Filament rows are checked, tag `0.9.0-beta.1` on both repositories and link releases in CHANGELOG.
