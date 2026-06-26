<?php

/**
 * This file is part of the dbflow-labs/core package.
 *
 * Copyright (c) 2026 Baron Wang <hello@dbflow.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 * @link    https://dbflow.dev
 * @see     https://github.com/dbflow-labs/dbflow-core
 */

declare(strict_types=1);

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Contracts\WorkflowRouteResolvable;
use DbflowLabs\Core\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Resolves a workflowable morph relation to a host detail-page URL.
 *
 * Resolution strategy (priority order):
 * 1. Host model implements WorkflowRouteResolvable â†?call getWorkflowShowUrl()
 * 2. Attempt Filament route naming conventions when resources follow default slug patterns
 * 3. Return null when all strategies fail (caller handles fallback)
 *
 * Design constraints:
 * - Engine-layer service; must not hard-code host business classes or route names.
 * - Safe for widgets, mail, and APIs; no side effects.
 */
final class WorkflowRouteResolver
{
    /**
     * Resolve the host detail-page URL for the workflowable model.
     *
     * @param  string  $panel  Filament panel id; defaults to admin
     */
    public function resolveShowUrl(WorkflowInstance $instance, string $panel = 'admin'): ?string
    {
        $workflowable = $this->loadWorkflowable($instance);

        if ($workflowable === null) {
            return null;
        }

        // Strategy 1: host model implements the contract (highest priority, fully decoupled)
        if ($workflowable instanceof WorkflowRouteResolvable) {
            return $workflowable->getWorkflowShowUrl();
        }

        // Strategy 2: infer URL from Filament resource slug conventions
        return $this->resolveViaFilamentConvention($workflowable, $instance->workflowable_id, $panel);
    }

    /**
     * Load the workflowable model (reuse eager-loaded relation or resolve via morph map).
     */
    private function loadWorkflowable(WorkflowInstance $instance): ?Model
    {
        // Reuse an already-loaded workflowable relation to avoid extra queries
        if ($instance->relationLoaded('workflowable') && $instance->workflowable instanceof Model) {
            return $instance->workflowable;
        }

        $morphType = $instance->workflowable_type;
        $morphId = $instance->workflowable_id;

        if (! is_string($morphType) || $morphType === '' || $morphId === null) {
            return null;
        }

        // Resolve the concrete class via the morph map token when available
        $resolvedClass = Relation::getMorphedModel($morphType) ?? $morphType;

        if (! is_string($resolvedClass) || ! class_exists($resolvedClass)) {
            return null;
        }

        /** @var class-string<Model> $resolvedClass */
        return $resolvedClass::query()->find($morphId);
    }

    /**
     * Derive the Filament view route using standard naming conventions.
     *
     * Convention: route('filament.{panel}.resources.{slug}.view', $id)
     * where slug = Str::plural(Str::kebab(class_basename($model)))
     *
     * Example: ExampleWorkflowableModel â†?example-workflowable-models â†?filament.admin.resources.example-workflowable-models.view
     */
    private function resolveViaFilamentConvention(Model $model, ?int $workflowableId, string $panel): ?string
    {
        if ($workflowableId === null) {
            return null;
        }

        $slug = Str::plural(Str::kebab(class_basename($model)));
        $routeName = "filament.{$panel}.resources.{$slug}.view";

        if (! $this->routeExists($routeName)) {
            return null;
        }

        try {
            return (string) route($routeName, ['record' => $workflowableId]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safely detect whether the route exists before calling route().
     */
    private function routeExists(string $name): bool
    {
        return app('router')->getRoutes()->hasNamedRoute($name);
    }
}
