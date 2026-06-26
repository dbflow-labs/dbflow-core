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

namespace DbflowLabs\Core\Definitions\Contracts;

use DbflowLabs\Core\Enums\NodeType;

interface WorkflowNodeInterface
{
    public function key(): string;

    public function name(): string;

    public function type(): NodeType;

    /**
     * Opaque UI payload (canvas coordinates, shapes, lines).
     * The execution engine must never parse or validate this bag.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): void;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
