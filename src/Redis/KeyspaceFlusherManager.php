<?php

namespace Ldiebold\Isolate\Redis;

use Illuminate\Contracts\Config\Repository;
use Ldiebold\Isolate\Contracts\KeyspaceFlusher;

/**
 * Flushes a per-instance prefix across every distinct configured Redis
 * connection — deduped by host:port:db, since the `cache` connection often uses
 * a different database than `default` and that is where orphaned cache keys
 * live — and aggregates the per-connection results into one FlushResult for the
 * prefix. Mirrors DatabaseDestroyerManager.
 */
class KeyspaceFlusherManager
{
    public function __construct(
        protected Repository $config,
        protected KeyspaceFlusher $flusher,
    ) {}

    public function flush(string $prefix): FlushResult
    {
        $removed = 0;
        $flushedAny = false;
        $reachable = false;
        $warnings = [];

        foreach ($this->connectionNames() as $name) {
            $result = $this->flusher->flush($name, $prefix);

            if ($result->outcome === FlushOutcome::Flushed) {
                $flushedAny = true;
                $reachable = true;
                $removed += $result->keyCount;
            } elseif ($result->outcome === FlushOutcome::Empty) {
                $reachable = true;
            } elseif ($result->message !== null) {
                $warnings[] = $result->message;
            }
        }

        $message = $warnings === [] ? null : implode(' ', $warnings);

        if ($flushedAny) {
            return FlushResult::flushed($prefix, $removed, $message);
        }

        if ($reachable) {
            return FlushResult::empty($prefix, $message);
        }

        return FlushResult::skipped($prefix, $message ?? "No reachable Redis connection for prefix [{$prefix}].");
    }

    public function count(string $prefix): ?int
    {
        $total = null;

        foreach ($this->connectionNames() as $name) {
            $count = $this->flusher->count($name, $prefix);

            if ($count !== null) {
                $total = ($total ?? 0) + $count;
            }
        }

        return $total;
    }

    /**
     * Distinct first-class Redis connection names (excluding the reserved
     * client/options/clusters keys), deduped by host:port:db so the same
     * physical database is never flushed — or counted — twice.
     *
     * @return array<int, string>
     */
    protected function connectionNames(): array
    {
        /** @var array<string, mixed> $redis */
        $redis = (array) $this->config->get('database.redis', []);

        $names = [];
        $seen = [];

        foreach ($redis as $name => $connection) {
            if (in_array($name, ['client', 'options', 'clusters'], true) || ! is_array($connection)) {
                continue;
            }

            $key = $this->dedupeKey($connection);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = (string) $name;
        }

        return $names;
    }

    /**
     * Identity of the physical Redis database a connection points at, so two
     * aliases for the same host:port:db are flushed only once.
     *
     * @param  array<array-key, mixed>  $connection
     */
    protected function dedupeKey(array $connection): string
    {
        $part = static fn (string $key): string => isset($connection[$key]) && is_scalar($connection[$key])
            ? (string) $connection[$key]
            : '';

        $database = $part('database') !== '' ? $part('database') : $part('url');

        return $part('host').':'.$part('port').':'.$database;
    }
}
