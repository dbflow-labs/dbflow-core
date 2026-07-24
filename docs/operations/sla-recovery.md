# SLA Recovery Operations

## Stale Processing

An SLA event is **stale** when:

- `status = processing`
- `processing_started_at` is older than `dbflow.sla.stale_processing_threshold_seconds` (default 900s)
- `processed_at` and `cancelled_at` are null

## Recovery Command

```bash
php artisan dbflow:sla-recover
php artisan dbflow:sla-recover --limit=50
```

### Outcomes

| Outcome | Behavior |
| --- | --- |
| **recovered** | Event returned to `pending` with `next_attempt_at = now`. **No job is dispatched** — `dbflow:sla-dispatch` reclaims it. |
| **cancelled** | Task is no longer pending; pending SLA events cancelled. |
| **exhausted** | `attempts >= max_attempts`; event marked `failed`. |

## Idempotency

Running `dbflow:sla-recover` repeatedly on the same recovered event is safe. Only stale `processing` events are candidates.

## Manual Follow-up

After recovery, ensure `dbflow:sla-dispatch` and queue workers are running. Failed events (`status = failed`) are not auto-recovered; investigate handler errors in `last_error`.
