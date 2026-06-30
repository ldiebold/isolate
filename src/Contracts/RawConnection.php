<?php

namespace Ldiebold\Isolate\Contracts;

/**
 * The minimal, prefix-free surface the keyspace flusher needs from a Redis
 * connection. Implementations hide the two client-specific quirks: SCAN's
 * return shape and UNLINK-vs-DEL availability. Operates on ABSOLUTE keys (no
 * client prefix) so deletes are never double-prefixed.
 */
interface RawConnection
{
    /**
     * Run one SCAN step over keys matching $match, returning the next cursor and
     * the batch of keys. The cursor is 0 / "0" once iteration is complete.
     *
     * @return array{0: int|string, 1: array<int, string>}
     */
    public function scan(int|string $cursor, string $match, int $count): array;

    /**
     * Delete the given absolute keys, returning the number removed. Uses UNLINK
     * where available and falls back to DEL.
     *
     * @param  array<int, string>  $keys
     */
    public function delete(array $keys): int;
}
