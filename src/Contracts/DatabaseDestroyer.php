<?php

namespace Ldiebold\Isolate\Contracts;

use Ldiebold\Isolate\Database\ConnectionConfig;
use Ldiebold\Isolate\Database\DropResult;

interface DatabaseDestroyer
{
    /**
     * Whether this destroyer handles the given database driver (mysql, pgsql, sqlite).
     */
    public function supports(string $driver): bool;

    /**
     * Drop $database if it exists, using the maintenance connection. Reports a
     * `missing` result when there is nothing to drop, and must degrade gracefully
     * (return a skipped result) rather than throw on recoverable failures such as
     * a missing privilege or an unreachable server.
     */
    public function destroy(ConnectionConfig $maintenance, string $database): DropResult;
}
