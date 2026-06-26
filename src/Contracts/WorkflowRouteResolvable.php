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

namespace DbflowLabs\Core\Contracts;

/**
 * Host models implement this interface to expose their Filament detail page URL to WorkflowRouteResolver.
 *
 * When a host model (e.g. ExampleWorkflowableModel) implements this interface,
 * DBFlow can generate links to the correct detail page in Todo widgets, notifications, and emails
 * without hard-coding host-system route names inside the engine.
 */
interface WorkflowRouteResolvable
{
    /**
     * Return the full detail page URL for this record in the host system UI.
     * Returns null when the route does not exist; callers must handle degradation.
     */
    public function getWorkflowShowUrl(): ?string;
}
