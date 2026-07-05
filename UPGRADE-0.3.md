# Upgrading to DBFlow Core 0.3.0-alpha.1

## 1. Pin the new version

```bash
composer require dbflowlabs/core:0.3.0-alpha.1
```

## 2. Run migrations

```bash
php artisan migrate
```

Migration `2026_07_06_100000_convert_dbflow_user_id_columns_to_string` converts workflow user reference columns to `VARCHAR(64)` and removes foreign keys to `users`.

## 3. Configure auth

```env
DBFLOW_AUTH_MODEL=App\Models\User
DBFLOW_AUTH_TABLE=users
```

`DBFLOW_AUTH_TABLE` is optional when the table can be inferred from the model.

## 4. Replace host sync commands

```bash
php artisan dbflow:sync
php artisan dbflow:validate --strict
```

## 5. Update code assumptions

- `assignee_user_id`, `started_by_user_id`, and `actor_user_id` are **strings** in the database and on Eloquent models.
- Integer user ids still work at runtime but are stored as strings (for example `"1"`).
- UUID/ULID primary keys are now supported without custom migrations.

## 6. Optional: events and task hooks

```php
use Illuminate\Support\Facades\Event;
use DbflowLabs\Core\Events\TaskCreated;

Event::listen(TaskCreated::class, function (TaskCreated $event): void {
    // notify assignees
});

DBFlow::registerTaskHooks(
    app(TaskHooksRegistry::class),
    'refund_approval',
    MyRefundTaskHooks::class,
);
```

## 7. Optional: strict condition expressions

```env
DBFLOW_EXPRESSION_STRICT=true
```

Use in `local` / `staging` or CI via `php artisan dbflow:validate` to catch invalid condition syntax early.
