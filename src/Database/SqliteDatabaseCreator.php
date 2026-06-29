<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Contracts\DatabaseCreator;
use Ldiebold\Isolate\Support\SqlitePath;
use Throwable;

/**
 * Creates a per-instance SQLite database by touching the file. Relative paths
 * resolve against database_path(); :memory: is skipped (it cannot be isolated).
 */
class SqliteDatabaseCreator implements DatabaseCreator
{
    public function __construct(
        protected Filesystem $files,
        protected string $databasePath,
    ) {}

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function ensureExists(ConnectionConfig $maintenance, string $database): CreateResult
    {
        if ($database === '' || $database === ':memory:') {
            return CreateResult::skipped($database, 'In-memory SQLite cannot be isolated; skipped.');
        }

        $path = SqlitePath::absolute($database, $this->databasePath);

        if ($this->files->exists($path)) {
            return CreateResult::existed($path);
        }

        try {
            $directory = dirname($path);

            if (! $this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
            }

            $this->files->put($path, '');
        } catch (Throwable $e) {
            return CreateResult::skipped($path, 'Could not create SQLite database: '.$e->getMessage());
        }

        return CreateResult::created($path);
    }
}
