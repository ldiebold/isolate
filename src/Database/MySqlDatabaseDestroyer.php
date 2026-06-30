<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Database\ConnectionResolverInterface;
use Ldiebold\Isolate\Contracts\DatabaseDestroyer;
use Throwable;

/**
 * Drops a per-instance MySQL/MariaDB database. Existence is probed first (via the
 * shared inspector) so the outcome is accurate; the drop then runs on the
 * autocommit maintenance connection with the identifier backtick-quoted.
 */
class MySqlDatabaseDestroyer implements DatabaseDestroyer
{
    public function __construct(
        protected ConnectionResolverInterface $connections,
        protected DatabaseInspector $inspector,
    ) {}

    public function supports(string $driver): bool
    {
        return $driver === 'mysql' || $driver === 'mariadb';
    }

    public function destroy(ConnectionConfig $maintenance, string $database): DropResult
    {
        try {
            if (! $this->inspector->exists($maintenance->name, $database)) {
                return DropResult::missing($database);
            }

            $quoted = '`'.str_replace('`', '``', $database).'`';
            $this->connections->connection($maintenance->name)->statement("DROP DATABASE IF EXISTS {$quoted}");

            return DropResult::dropped($database);
        } catch (Throwable $e) {
            return DropResult::skipped($database, 'Could not drop MySQL database: '.$e->getMessage());
        }
    }
}
