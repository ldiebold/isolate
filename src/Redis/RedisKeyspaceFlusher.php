<?php

namespace Ldiebold\Isolate\Redis;

use Ldiebold\Isolate\Contracts\KeyspaceFlusher;
use Ldiebold\Isolate\Contracts\RawConnection;
use Throwable;

/**
 * Flushes one Redis connection's keys for a per-instance prefix with a cursor
 * SCAN loop over absolute keys (MATCH <prefix>*), deleting matches in batches.
 *
 * Hard safety guards (belt-and-suspenders beyond the planner): refuses an
 * empty/whitespace prefix or a prefix equal to a protected vanilla/base value —
 * either would match every instance's keys. Degrades gracefully: returns a
 * skipped result instead of throwing when Redis is unreachable.
 */
class RedisKeyspaceFlusher implements KeyspaceFlusher
{
    /**
     * @param  array<int, string>  $protectedPrefixes  Base/vanilla prefixes that must never be flushed.
     */
    public function __construct(
        protected RawRedisConnectionFactory $connections,
        protected array $protectedPrefixes = [],
        protected int $batch = 1000,
    ) {}

    public function flush(string $connectionName, string $prefix): FlushResult
    {
        if (($refusal = $this->refusal($prefix)) !== null) {
            return FlushResult::skipped($prefix, $refusal);
        }

        $connection = $this->connections->for($connectionName);

        if ($connection === null) {
            return FlushResult::skipped($prefix, "Redis connection [{$connectionName}] is unavailable; skipped.");
        }

        try {
            $removed = $this->sweep($connection, $prefix, delete: true);
        } catch (Throwable $e) {
            return FlushResult::skipped(
                $prefix,
                "Could not flush Redis prefix [{$prefix}] on [{$connectionName}]: ".$e->getMessage(),
            );
        }

        return $removed === 0
            ? FlushResult::empty($prefix)
            : FlushResult::flushed($prefix, $removed);
    }

    public function count(string $connectionName, string $prefix): ?int
    {
        if ($this->refusal($prefix) !== null) {
            return null;
        }

        $connection = $this->connections->for($connectionName);

        if ($connection === null) {
            return null;
        }

        try {
            return $this->sweep($connection, $prefix, delete: false);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cursor SCAN loop over MATCH <prefix>*; deletes matches (or merely counts
     * them). Returns the number of keys removed (or seen, when counting).
     */
    protected function sweep(RawConnection $connection, string $prefix, bool $delete): int
    {
        $match = $prefix.'*';
        $cursor = 0;
        $total = 0;

        do {
            [$cursor, $keys] = $connection->scan($cursor, $match, $this->batch);

            if ($keys !== []) {
                $total += $delete ? $connection->delete($keys) : count($keys);
            }
        } while ((string) $cursor !== '0');

        return $total;
    }

    protected function refusal(string $prefix): ?string
    {
        if (trim($prefix) === '') {
            return 'Refusing to flush an empty or whitespace Redis prefix.';
        }

        if (in_array($prefix, $this->protectedPrefixes, true)) {
            return "Refusing to flush the base Redis prefix [{$prefix}] (would match every instance's keys).";
        }

        return null;
    }
}
