# Action Recovery Operations

## Stale running executions

An Action execution is stale when `status=running` and `processing_started_at` is older than `dbflow.actions.stale_processing_threshold_seconds`.

```bash
php artisan dbflow:actions-recover
```

Outcomes:

- **recovered** → returned to `queued` for `dbflow:actions-dispatch`
- **exhausted** → attempts spent
- **cancelled** → instance no longer running

Recovery preserves `logical_execution_key`. For outbound webhooks, an unknown external outcome may cause a duplicate HTTP request with the same idempotency header.
