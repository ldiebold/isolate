<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Support\SqlitePath;
use Throwable;

/**
 * Single source of truth for "does this database exist?" across sqlite (file
 * presence), pgsql (pg_database) and mysql/mariadb (information_schema). Shared by
 * the collision detector, teardown discovery and the destroyers so the create,
 * detect and teardown paths stay in lock-step. A failed catalog probe yields
 * false, since it cannot confirm existence.
 */
class DatabaseInspector
{
    public function __construct(
        protected Repository $config,
        protected ConnectionResolverInterface $connections,
        protected Filesystem $files,
        protected string $databasePath,
    ) {}

    public function exists(string $connection, string $database): bool
    {
        $driver = (string) $this->config->get("database.connections.{$connection}.driver");

        return match ($driver) {
            'sqlite' => $this->sqliteExists($database),
            'pgsql' => $this->catalogExists($connection, 'SELECT 1 FROM pg_database WHERE datname = ?', $database),
            'mysql', 'mariadb' => $this->catalogExists(
                $connection,
                'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                $database,
            ),
            default => false,
        };
    }

    protected function sqliteExists(string $database): bool
    {
        if ($database === '' || $database === ':memory:') {
            return false;
        }

        return $this->files->exists(SqlitePath::absolute($database, $this->databasePath));
    }

    protected function catalogExists(string $connection, string $sql, string $database): bool
    {
        try {
            return $this->connections->connection($connection)->selectOne($sql, [$database]) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
