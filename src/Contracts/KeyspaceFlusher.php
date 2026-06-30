<?php

namespace Ldiebold\Isolate\Contracts;

use Ldiebold\Isolate\Redis\FlushResult;

interface KeyspaceFlusher
{
    /**
     * Remove every key under $prefix on the given Redis connection. Reports an
     * `empty` result when nothing matched, and must degrade gracefully (return a
     * skipped result) rather than throw when Redis is unreachable or the prefix
     * is refused by a safety guard.
     */
    public function flush(string $connectionName, string $prefix): FlushResult;

    /**
     * Count the keys under $prefix without removing anything, for dry-run and
     * pre-flush summaries. Returns null when the count cannot be determined
     * (Redis unreachable) or the prefix is refused.
     */
    public function count(string $connectionName, string $prefix): ?int;
}
