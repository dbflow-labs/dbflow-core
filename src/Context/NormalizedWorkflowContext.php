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

namespace DbflowLabs\Core\Context;

use DbflowLabs\Core\Enums\ContextNamespace;
use DbflowLabs\Core\Exceptions\InvalidWorkflowContextException;

/**
 * Immutable normalized context bag with fixed namespaces.
 *
 * @phpstan-type NamespaceBag array<string, mixed>
 */
final class NormalizedWorkflowContext
{
    /**
     * @param  array{
     *     model: NamespaceBag,
     *     starter: NamespaceBag,
     *     actor: NamespaceBag,
     *     context: NamespaceBag,
     *     workflow: NamespaceBag,
     *     task: NamespaceBag
     * }  $namespaces
     */
    public function __construct(
        private readonly array $namespaces,
    ) {
        foreach (ContextNamespace::values() as $namespace) {
            if (! array_key_exists($namespace, $this->namespaces) || ! is_array($this->namespaces[$namespace])) {
                throw new InvalidWorkflowContextException(
                    "Normalized context must include array namespace [{$namespace}].",
                );
            }
        }
    }

    /**
     * @return array{
     *     model: array<string, mixed>,
     *     starter: array<string, mixed>,
     *     actor: array<string, mixed>,
     *     context: array<string, mixed>,
     *     workflow: array<string, mixed>,
     *     task: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return $this->namespaces;
    }

    /**
     * @return array<string, mixed>
     */
    public function namespace(ContextNamespace $namespace): array
    {
        return $this->namespaces[$namespace->value];
    }

    /**
     * Flat legacy-compatible bag for ExpressionLanguage consumers.
     * Prefer namespaced path resolution for new code.
     *
     * @return array<string, mixed>
     */
    public function legacyVariables(): array
    {
        return $this->namespaces[ContextNamespace::Context->value];
    }
}
