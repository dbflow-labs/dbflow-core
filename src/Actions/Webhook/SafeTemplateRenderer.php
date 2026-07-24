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

namespace DbflowLabs\Core\Actions\Webhook;

use InvalidArgumentException;

final class SafeTemplateRenderer
{
    private const ALLOWED_PATTERN = '/\{\{\s*(model|context|workflow|secret)\.([a-zA-Z0-9_.]+)\s*\}\}/';

    /**
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $secrets
     */
    public function render(
        string $template,
        array $model,
        array $context,
        int|string $instanceId,
        array $secrets = [],
    ): string {
        if (preg_match_all('/\{\{[^}]+\}\}/', $template, $allMatches) === false) {
            return $template;
        }

        foreach ($allMatches[0] as $token) {
            if (! preg_match(self::ALLOWED_PATTERN, $token, $matches)) {
                throw new InvalidArgumentException("Template token [{$token}] is not allowed.");
            }

            $namespace = $matches[1];
            $path = $matches[2];
            $replacement = match ($namespace) {
                'model' => $this->resolvePath($model, $path),
                'context' => $this->resolvePath($context, $path),
                'workflow' => $path === 'instance_id' ? (string) $instanceId : throw new InvalidArgumentException("Workflow path [{$path}] is not allowed."),
                'secret' => $secrets[$path] ?? throw new InvalidArgumentException("Secret [{$path}] is not available."),
            };

            $template = str_replace($token, (string) $replacement, $template);
        }

        return $template;
    }

  /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePath(array $data, string $path): string
    {
        $segments = explode('.', $path);
        $cursor = $data;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                throw new InvalidArgumentException("Template path [{$path}] could not be resolved.");
            }

            $cursor = $cursor[$segment];
        }

        if (is_scalar($cursor) || $cursor === null) {
            return (string) $cursor;
        }

        throw new InvalidArgumentException("Template path [{$path}] must resolve to a scalar value.");
    }
}
