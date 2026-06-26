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

namespace DbflowLabs\Core\Exceptions;

use Illuminate\Database\QueryException;

/**
 * Safely translates database-layer exceptions into domain exceptions, preventing raw QueryException from leaking to the business layer.
 * Only intercepts active_key unique constraint violations; all other database errors are rethrown as-is.
 */
final class WorkflowExceptionTranslator
{
    /**
     * Unique constraint index name; must stay in sync with the migration file.
     * WARNING: If the index name is changed in a migration, this constant must be updated accordingly.
     */
    private const ACTIVE_KEY_INDEX = 'uq_dbflow_workflow_instances_active_key';

    /**
     * Check whether QueryException is an active_key unique constraint violation.
     * If so, throws WorkflowAlreadyRunningException; otherwise rethrows the original exception.
     *
     * @throws WorkflowAlreadyRunningException
     * @throws QueryException
     */
    public static function translateActiveKeyViolation(QueryException $e): never
    {
        if (self::isActiveKeyViolation($e)) {
            throw new WorkflowAlreadyRunningException('A workflow is already running for this record. Do not submit again.');
        }

        throw $e;
    }

    /**
     * Cross-driver detection of active_key column unique constraint violations.
     *
     * - MySQL / MariaDB: SQLSTATE 23000, error message contains index name
     * - PostgreSQL:      SQLSTATE 23505, error message contains index name
     * - SQLite:          SQLSTATE 23000 or native error code 19, message contains column or index name
     */
    private static function isActiveKeyViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = strtolower($e->getMessage());
        $indexName = strtolower(self::ACTIVE_KEY_INDEX);

        $isIntegrityViolation = match ($sqlState) {
            '23000' => true,  // MySQL / MariaDB / SQLite
            '23505' => true,  // PostgreSQL
            default => false,
        };

        if (! $isIntegrityViolation) {
            return false;
        }

        // SQLite native error code 19 (SQLITE_CONSTRAINT) as supplementary check.
        $previousCode = $e->getPrevious()?->getCode();
        $isSqliteConstraint = $previousCode === 19 || $previousCode === '19';

        if ($sqlState === '23000' && ! $isSqliteConstraint) {
            // MySQL / MariaDB: only treat as violation when message contains index or column name.
            return str_contains($message, $indexName)
                || str_contains($message, 'active_key');
        }

        if ($sqlState === '23505') {
            // PostgreSQL: detail message contains constraint name.
            return str_contains($message, $indexName)
                || str_contains($message, 'active_key');
        }

        // SQLite constraint error: message containing column name is sufficient.
        return str_contains($message, 'active_key');
    }
}
