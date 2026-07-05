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
];
