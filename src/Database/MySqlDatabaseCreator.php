<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Database\ConnectionResolverInterface;
use Ldiebold\Isolate\Contracts\DatabaseCreator;
use Throwable;

/**
 * Creates a per-instance MySQL/MariaDB database. Existence is checked against
 * information_schema first so the outcome (created vs existed) is accurate.
 */
class MySqlDatabaseCreator implements DatabaseCreator
{
    public function __construct(protected ConnectionResolverInterface $connections) {}

    public function supports(string $driver): bool
    {
        return $driver === 'mysql' || $driver === 'mariadb';
    }

    public function ensureExists(ConnectionConfig $maintenance, string $database): CreateResult
    {
        try {
            $connection = $this->connections->connection($maintenance->name);

            $exists = $connection->selectOne(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$database],
            );

            if ($exists !== null) {
                return CreateResult::existed($database);
            }

            $quoted = '`'.str_replace('`', '``', $database).'`';
            $connection->statement("CREATE DATABASE IF NOT EXISTS {$quoted}");

            return CreateResult::created($database);
        } catch (Throwable $e) {
            return CreateResult::skipped($database, 'Could not create MySQL database: '.$e->getMessage());
        }
    }
}
