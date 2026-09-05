<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use Throwable;

final class SqliteTransaction
{
    /** Acquire the write lock before reading the state used to authorize a change. */
    public static function write(PDO $pdo, callable $operation): mixed
    {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $rollbackError) {
                // SQLite can already have rolled back after an I/O error.
            }
            throw $error;
        }
    }
}
