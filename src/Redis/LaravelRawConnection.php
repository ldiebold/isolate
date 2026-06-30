<?php

namespace Ldiebold\Isolate\Redis;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Ldiebold\Isolate\Contracts\RawConnection;
use Throwable;

/**
 * Adapts a prefix-stripped Laravel Redis connection to the small surface the
 * flusher needs, hiding the two client-specific quirks: SCAN's return shape
 * (phpredis returns [cursor, keys] and a single false once an empty scan is
 * exhausted) and UNLINK-vs-DEL availability.
 *
 * For phpredis, SCAN goes through the wrapper's scan() method, which translates
 * the [match/count] options array into the native signature; command('scan')
 * would forward straight to the raw client and bypass that translation. Predis
 * accepts the options array directly, so it is driven through command().
 */
class LaravelRawConnection implements RawConnection
{
    public function __construct(protected Connection $connection) {}

    public function scan(int|string $cursor, string $match, int $count): array
    {
        $options = ['match' => $match, 'count' => $count];

        if ($this->connection instanceof PhpRedisConnection) {
            // phpredis treats an initial cursor of 0 as "iteration complete"; the
            // first scan must start from null. Non-zero cursors it hands back are
            // passed through unchanged.
            $iterator = ($cursor === 0 || $cursor === '0') ? null : $cursor;

            /** @var mixed $result */
            $result = $this->connection->scan($iterator, $options);
        } else {
            /** @var mixed $result */
            $result = $this->connection->command('scan', [$cursor, $options]);
        }

        if (! is_array($result)) {
            // phpredis returns false once iteration is exhausted with no keys.
            return [0, []];
        }

        $next = $result[0] ?? 0;
        $keys = $result[1] ?? [];

        return [
            is_int($next) || is_string($next) ? $next : 0,
            array_values(array_filter((array) $keys, 'is_string')),
        ];
    }

    public function delete(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $keys = array_values($keys);

        try {
            return (int) $this->connection->command('unlink', $keys);
        } catch (Throwable) {
            return (int) $this->connection->command('del', $keys);
        }
    }
}
