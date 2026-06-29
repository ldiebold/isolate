<?php

namespace Ldiebold\Isolate\CollisionDetectors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\SideEffect;
use Ldiebold\Isolate\SideEffectKind;
use Ldiebold\Isolate\Support\SqlitePath;
use Throwable;

/**
 * Reports a conflict when a candidate's database already exists. A failed probe
 * yields no conflict, since it cannot confirm one.
 */
class DatabaseCollisionDetector implements CollisionDetector
{
    public function __construct(
        protected Repository $config,
        protected ConnectionResolverInterface $connections,
        protected Filesystem $files,
        protected string $databasePath,
    ) {}

    public function conflicts(IsolationPlan $plan): iterable
    {
        foreach ($plan->sideEffects as $effect) {
            $conflict = match ($effect->kind) {
                SideEffectKind::CreateDatabase => $this->databaseConflict($effect),
            };

            if ($conflict !== null) {
                yield $conflict;
            }
        }
    }

    protected function databaseConflict(SideEffect $effect): ?Conflict
    {
        $connection = (string) $effect->get('connection');
        $database = (string) $effect->get('database');

        if (! $this->exists($connection, $database)) {
            return null;
        }

        return Conflict::database(
            (string) $effect->get('env'),
            $database,
            "Database [{$database}] already exists for connection [{$connection}]."
        );
    }

    protected function exists(string $connection, string $database): bool
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
