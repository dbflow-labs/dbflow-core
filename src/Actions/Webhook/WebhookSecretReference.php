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

final class WebhookSecretReference
{
    private const SECRET_REFERENCE_PATTERN = '/^\{\{\s*secret\.([a-zA-Z0-9_.]+)\s*\}\}$/';

    private const SECRET_TOKEN_PATTERN = '/\{\{\s*secret\.[a-zA-Z0-9_.]+\s*\}\}/';

    public static function normalize(string $reference): string
    {
        $trimmed = trim($reference);

        if ($trimmed === '') {
            return '';
        }

        if (preg_match(self::SECRET_REFERENCE_PATTERN, $trimmed, $matches) === 1) {
            return $matches[1];
        }

        if (str_contains($trimmed, '{{')) {
            throw new InvalidArgumentException("Secret reference [{$reference}] must use the form {{ secret.key_name }}.");
        }

        return $trimmed;
    }

    public static function containsSecretToken(string $value): bool
    {
        return preg_match(self::SECRET_TOKEN_PATTERN, $value) === 1;
    }
}
