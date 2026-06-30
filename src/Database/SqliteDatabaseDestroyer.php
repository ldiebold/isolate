<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Contracts\DatabaseDestroyer;
use Ldiebold\Isolate\Support\SqlitePath;
use Throwable;

/**
 * Drops a per-instance SQLite database by deleting its file, plus the -wal, -shm
 * and -journal sidecars that WAL mode leaves behind (otherwise they orphan).
 * Relative paths resolve against database_path(); :memory: is never touched, a
 * missing file reports `missing`, and the parent directory is left in place.
 */
class SqliteDatabaseDestroyer implements DatabaseDestroyer
{
    /**
     * @var array<int, string>
     */
    protected array $sidecarSuffixes = ['-wal', '-shm', '-journal'];

    public function __construct(
        protected Filesystem $files,
        protected string $databasePath,
    ) {}

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function destroy(ConnectionConfig $maintenance, string $database): DropResult
    {
        if ($database === '' || $database === ':memory:') {
            return DropResult::skipped($database, 'In-memory SQLite has no file to drop; skipped.');
        }

        $path = SqlitePath::absolute($database, $this->databasePath);

        if (! $this->files->exists($path)) {
            return DropResult::missing($path);
        }

        try {
            $this->files->delete($path);
            $this->deleteSidecars($path);
        } catch (Throwable $e) {
            return DropResult::skipped($path, 'Could not drop SQLite database: '.$e->getMessage());
        }

        return DropResult::dropped($path);
    }

    protected function deleteSidecars(string $path): void
    {
        foreach ($this->sidecarSuffixes as $suffix) {
            $sidecar = $path.$suffix;

            if ($this->files->exists($sidecar)) {
                $this->files->delete($sidecar);
            }
        }
    }
}
