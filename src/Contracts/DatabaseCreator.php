<?php

namespace Ldiebold\Isolate\Contracts;

use Ldiebold\Isolate\Database\ConnectionConfig;
use Ldiebold\Isolate\Database\CreateResult;

interface DatabaseCreator
{
    /**
     * Whether this creator handles the given database driver (mysql, pgsql, sqlite).
     */
    public function supports(string $driver): bool;

    /**
     * Idempotently ensure $database exists, using the maintenance connection.
     * Must degrade gracefully (return a skipped result) rather than throw on
     * recoverable failures such as a missing CREATE privilege.
     */
    public function ensureExists(ConnectionConfig $maintenance, string $database): CreateResult;
}
