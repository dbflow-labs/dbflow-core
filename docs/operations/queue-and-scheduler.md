# Queue and Scheduler Operations

DBFlow v1.1 asynchronous runtimes (SLA and Reliable Actions) require a **queue worker** and a **scheduler** in production. Core cannot verify that these are running; use `php artisan dbflow:diagnostics` for readiness signals only.

## Required Scheduler Entries

Register these commands on a one-minute schedule (adjust frequency per load):

```php
Schedule::command('dbflow:sla-dispatch')->everyMinute();
Schedule::command('dbflow:sla-recover')->everyFiveMinutes();
Schedule::command('dbflow:actions-dispatch')->everyMinute();
Schedule::command('dbflow:actions-recover')->everyFiveMinutes();
Schedule::command('dbflow:process-timeouts')->everyMinute(); // legacy timeout path only
```

## Queue Worker

Run a dedicated worker for the host application's default queue connection:

```bash
php artisan queue:work --tries=1
```

DBFlow jobs (`ProcessSlaEventJob`, `ProcessActionExecutionJob`) use `$tries = 1`. Business retries are handled by the SLA and Action ledgers, not Laravel queue retries.

## Legacy vs v1.1 Paths

| Path | Processor |
| --- | --- |
| Legacy timeout (`sla_policy_source` null) | `dbflow:process-timeouts` |
| v1.1 SLA (`sla_policy_source = v1.1_sla`) | `dbflow:sla-dispatch` + `ProcessSlaEventJob` |
| Reliable Actions | `dbflow:actions-dispatch` + `ProcessActionExecutionJob` |

## Diagnostics

```bash
php artisan dbflow:diagnostics
```

Reports capability state, pending/processing/failed counts, stale processing estimates, migration presence, and queue driver readiness.
