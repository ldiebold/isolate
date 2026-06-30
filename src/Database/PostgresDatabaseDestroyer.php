<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Ldiebold\Isolate\Contracts\DatabaseDestroyer;
use Throwable;

/**
 * Drops a per-instance PostgreSQL database. DROP DATABASE cannot run in a
 * transaction and Postgres refuses it while sessions are connected, so existence
 * is probed first, lingering backends are terminated (best effort), and the drop
 * runs on the autocommit maintenance connection.
 */
class PostgresDatabaseDestroyer implements DatabaseDestroyer
{
    public function __construct(
        protected ConnectionResolverInterface $connections,
        protected DatabaseInspector $inspector,
    ) {}

    public function supports(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    public function destroy(ConnectionConfig $maintenance, string $database): DropResult
    {
        try {
            if (! $this->inspector->exists($maintenance->name, $database)) {
                return DropResult::missing($database);
            }

            $connection = $this->connections->connection($maintenance->name);

            $this->terminateBackends($connection, $database);

            $quoted = '"'.str_replace('"', '""', $database).'"';
            $connection->statement("DROP DATABASE IF EXISTS {$quoted}");

            return DropResult::dropped($database);
        } catch (Throwable $e) {
            return DropResult::skipped($database, 'Could not drop PostgreSQL database: '.$e->getMessage());
        }
    }

    /**
     * Terminate other sessions connected to the target so DROP DATABASE can
     * proceed. Best effort: a missing privilege here must not abort the drop.
     */
    protected function terminateBackends(ConnectionInterface $connection, string $database): void
    {
        try {
            $connection->select(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$database],
            );
        } catch (Throwable) {
            // Non-fatal: without privilege to terminate we still attempt the drop.
        }
    }
}
