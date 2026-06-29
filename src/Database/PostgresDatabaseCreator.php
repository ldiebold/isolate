<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Database\ConnectionResolverInterface;
use Ldiebold\Isolate\Contracts\DatabaseCreator;
use Throwable;

/**
 * Creates a per-instance PostgreSQL database. CREATE DATABASE cannot run inside
 * a transaction and has no IF NOT EXISTS, so existence is gated on a pg_database
 * lookup and the statement runs on the autocommit maintenance connection.
 */
class PostgresDatabaseCreator implements DatabaseCreator
{
    public function __construct(protected ConnectionResolverInterface $connections) {}

    public function supports(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    public function ensureExists(ConnectionConfig $maintenance, string $database): CreateResult
    {
        try {
            $connection = $this->connections->connection($maintenance->name);

            $exists = $connection->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$database]);

            if ($exists !== null) {
                return CreateResult::existed($database);
            }

            $quoted = '"'.str_replace('"', '""', $database).'"';
            $connection->statement("CREATE DATABASE {$quoted}");

            return CreateResult::created($database);
        } catch (Throwable $e) {
            return CreateResult::skipped($database, 'Could not create PostgreSQL database: '.$e->getMessage());
        }
    }
}
