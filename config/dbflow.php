<?php

declare(strict_types=1);

return [
    'enabled' => env('DBFLOW_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Workflow Binding Mode
    |--------------------------------------------------------------------------
    |
    | Controls how workflows attach to host business models:
    | - "code": explicit manual trigger via HasWorkflow::startWorkflow()
    | - "ui":   polymorphic zero-code auto-start on model creation
    |
    */
    'binding_mode' => env('DBFLOW_BINDING_MODE', 'code'),

    'auth' => [
        'model' => env('DBFLOW_AUTH_MODEL'),
        'guard' => env('DBFLOW_AUTH_GUARD'),
        'table' => env('DBFLOW_AUTH_TABLE'),
        'connection' => env('DBFLOW_AUTH_CONNECTION'),
        'resolver' => DbflowLabs\Core\Support\ConfigUserResolver::class,
    ],

    'expression' => [
        'strict' => env('DBFLOW_EXPRESSION_STRICT', false),
    ],

    'visual_builder_enabled' => env('DBFLOW_VISUAL_BUILDER_ENABLED', false),

    'reassignment' => [
        'require_reason' => env('DBFLOW_REASSIGNMENT_REQUIRE_REASON', false),
    ],

    'delegation' => [
        'max_duration_days' => (int) env('DBFLOW_DELEGATION_MAX_DURATION_DAYS', 365),
        'max_cycle_depth' => (int) env('DBFLOW_DELEGATION_MAX_CYCLE_DEPTH', 16),
        'migration_batch_size' => (int) env('DBFLOW_DELEGATION_MIGRATION_BATCH_SIZE', 100),
        'require_reason' => env('DBFLOW_DELEGATION_REQUIRE_REASON', false),
        'allow_self_service' => env('DBFLOW_DELEGATION_ALLOW_SELF_SERVICE', true),
        'allow_admin_create_for_others' => env('DBFLOW_DELEGATION_ALLOW_ADMIN_CREATE_FOR_OTHERS', true),
    ],

    'sla' => [
        'min_duration_seconds' => (int) env('DBFLOW_SLA_MIN_DURATION_SECONDS', 60),
        'max_duration_seconds' => (int) env('DBFLOW_SLA_MAX_DURATION_SECONDS', 31536000),
        'max_reminder_count' => (int) env('DBFLOW_SLA_MAX_REMINDER_COUNT', 10),
        'max_attempts' => (int) env('DBFLOW_SLA_MAX_ATTEMPTS', 3),
        'max_backoff_seconds' => (int) env('DBFLOW_SLA_MAX_BACKOFF_SECONDS', 86400),
        'default_backoff_seconds' => [60, 300, 900],
        'dispatch_batch_size' => (int) env('DBFLOW_SLA_DISPATCH_BATCH_SIZE', 100),
        'recovery_batch_size' => (int) env('DBFLOW_SLA_RECOVERY_BATCH_SIZE', 100),
        'stale_processing_threshold_seconds' => (int) env('DBFLOW_SLA_STALE_THRESHOLD_SECONDS', 900),
        'default_notification_channel' => env('DBFLOW_SLA_DEFAULT_CHANNEL', 'event'),
        'allowed_notification_channels' => ['event'],
        'require_registered_escalation_resolver' => env('DBFLOW_SLA_REQUIRE_REGISTERED_ESCALATION_RESOLVER', true),
        'max_error_length' => (int) env('DBFLOW_SLA_MAX_ERROR_LENGTH', 1000),
    ],

    'actions' => [
        'max_attempts' => (int) env('DBFLOW_ACTIONS_MAX_ATTEMPTS', 3),
        'default_backoff_seconds' => (int) env('DBFLOW_ACTIONS_DEFAULT_BACKOFF_SECONDS', 60),
        'max_backoff_seconds' => (int) env('DBFLOW_ACTIONS_MAX_BACKOFF_SECONDS', 86400),
        'dispatch_batch_size' => (int) env('DBFLOW_ACTIONS_DISPATCH_BATCH_SIZE', 100),
        'recovery_batch_size' => (int) env('DBFLOW_ACTIONS_RECOVERY_BATCH_SIZE', 100),
        'stale_processing_threshold_seconds' => (int) env('DBFLOW_ACTIONS_STALE_THRESHOLD_SECONDS', 900),
        'max_error_length' => (int) env('DBFLOW_ACTIONS_MAX_ERROR_LENGTH', 1000),
    ],

    'webhook' => [
        'timeout_seconds' => (int) env('DBFLOW_WEBHOOK_TIMEOUT_SECONDS', 30),
        'max_request_body_length' => (int) env('DBFLOW_WEBHOOK_MAX_REQUEST_BODY_LENGTH', 65536),
        'max_response_body_length' => (int) env('DBFLOW_WEBHOOK_MAX_RESPONSE_BODY_LENGTH', 4096),
        'max_header_count' => (int) env('DBFLOW_WEBHOOK_MAX_HEADER_COUNT', 32),
        'max_header_value_length' => (int) env('DBFLOW_WEBHOOK_MAX_HEADER_VALUE_LENGTH', 8192),
        'deny_private_ips' => env('DBFLOW_WEBHOOK_DENY_PRIVATE_IPS', true),
        'require_https' => env('DBFLOW_WEBHOOK_REQUIRE_HTTPS', false),
        'follow_redirects' => env('DBFLOW_WEBHOOK_FOLLOW_REDIRECTS', false),
        'max_redirects' => (int) env('DBFLOW_WEBHOOK_MAX_REDIRECTS', 3),
        'host_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DBFLOW_WEBHOOK_HOST_ALLOWLIST', '')),
        ))),
        'allowed_schemes' => ['https', 'http'],
        'idempotency_header' => 'X-DBFlow-Idempotency-Key',
        'timestamp_header' => 'X-DBFlow-Timestamp',
        'signature_header' => 'X-DBFlow-Signature',
        'disallowed_headers' => [
            'host',
            'content-length',
            'transfer-encoding',
            'connection',
            'proxy-connection',
            'proxy-authorization',
            'cookie',
            'set-cookie',
            'x-dbflow-idempotency-key',
            'x-dbflow-timestamp',
            'x-dbflow-signature',
        ],
        'redacted_header_keys' => [
            'authorization',
            'cookie',
            'set-cookie',
            'token',
            'access_token',
            'refresh_token',
            'secret',
            'password',
            'api_key',
            'access_key',
            'client_secret',
            'signature',
            'x-dbflow-signature',
        ],
    ],
];
