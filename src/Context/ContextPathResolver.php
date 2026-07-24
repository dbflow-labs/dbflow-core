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

use DbflowLabs\Core\Enums\ContextAccessPurpose;
use DbflowLabs\Core\Enums\ContextNamespace;
use DbflowLabs\Core\Exceptions\ContextPathException;

/**
 * Resolves approved dot-paths from normalized context arrays only.
 */
final class ContextPathResolver
{
    private const PATH_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-zA-Z0-9_]+)*$/';

    /**
     * @param  array<string, bool>  $sensitivePaths
     */
    public function __construct(
        private readonly array $sensitivePaths = [],
    ) {}

    public function withFieldCatalog(FieldCatalog $catalog): self
    {
        return new self($catalog->sensitivePathMap());
    }

    public function resolve(
        NormalizedWorkflowContext $context,
        string $path,
        ContextAccessPurpose $purpose = ContextAccessPurpose::Condition,
    ): ContextPathResolution {
        $this->assertValidPath($path);

        if (isset($this->sensitivePaths[$path]) && ! $purpose->allowsSensitiveValues()) {
            throw new ContextPathException(
                $path,
                ContextPathException::CODE_SENSITIVE,
                "Sensitive path [{$path}] is not allowed for purpose [{$purpose->value}].",
            );
        }

        $segments = explode('.', $path);
        $root = array_shift($segments);

        if ($root === null || ContextNamespace::tryFrom($root) === null) {
            throw new ContextPathException(
                $path,
                ContextPathException::CODE_PROHIBITED,
                "Path [{$path}] must begin with an allowed context namespace.",
            );
        }

        $cursor = $context->toArray()[$root];

        foreach ($segments as $segment) {
            if ($this->isProhibitedSegment($segment)) {
                throw new ContextPathException(
                    $path,
                    ContextPathException::CODE_PROHIBITED,
                    "Path segment [{$segment}] is prohibited.",
                );
            }

            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return ContextPathResolution::missing();
            }

            $cursor = $cursor[$segment];
        }

        if ($cursor === null) {
            return ContextPathResolution::presentNull();
        }

        if (is_object($cursor) || is_resource($cursor)) {
            throw new ContextPathException(
                $path,
                ContextPathException::CODE_PROHIBITED,
                "Path [{$path}] resolved to a prohibited value type.",
            );
        }

        return ContextPathResolution::found($cursor);
    }

    private function assertValidPath(string $path): void
    {
        if ($path === '' || ! preg_match(self::PATH_PATTERN, $path)) {
            throw new ContextPathException(
                $path,
                ContextPathException::CODE_INVALID,
                "Context path [{$path}] has invalid syntax.",
            );
        }

        if (str_contains($path, '..') || str_contains($path, '::')) {
            throw new ContextPathException(
                $path,
                ContextPathException::CODE_INVALID,
                "Context path [{$path}] contains prohibited traversal tokens.",
            );
        }
    }

    private function isProhibitedSegment(string $segment): bool
    {
        $lower = strtolower($segment);

        return in_array($lower, [
            '__construct',
            '__destruct',
            '__call',
            '__callstatic',
            '__get',
            '__set',
            '__isset',
            '__unset',
            '__invoke',
            'getenv',
            'env',
            'app',
            'container',
        ], true);
    }
}
