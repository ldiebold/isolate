<?php

namespace Ldiebold\Isolate\Support;

/**
 * Resolves a SQLite database value (which may be relative) to an absolute path
 * against database_path(), consistently for the creator and the detector.
 */
class SqlitePath
{
    public static function absolute(string $database, string $databasePath): string
    {
        if (self::isAbsolute($database)) {
            return $database;
        }

        return rtrim($databasePath, '/').'/'.ltrim($database, '/');
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
