<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\RawConnection;

/**
 * In-memory RawConnection for unit tests. Matches keys by a literal glob prefix
 * (the trailing `*`), records deletions, and counts scan calls. With a positive
 * $pageSize it pages results so the flusher's cursor loop can be exercised
 * without a real Redis server.
 */
final class FakeRawConnection implements RawConnection
{
    /**
     * @var array<int, string>
     */
    public array $deleted = [];

    public int $scans = 0;

    /**
     * @param  array<int, string>  $keys  Absolute keys present in this fake keyspace.
     * @param  int  $pageSize  0 returns every match in one page; > 0 pages by offset.
     */
    public function __construct(
        private array $keys = [],
        private int $pageSize = 0,
    ) {}

    public function scan(int|string $cursor, string $match, int $count): array
    {
        $this->scans++;

        $prefix = rtrim($match, '*');
        $matches = array_values(array_filter(
            $this->keys,
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));

        if ($this->pageSize <= 0) {
            return [0, $matches];
        }

        $offset = (int) $cursor;
        $page = array_slice($matches, $offset, $this->pageSize);
        $next = ($offset + $this->pageSize) >= count($matches) ? 0 : $offset + $this->pageSize;

        return [$next, array_values($page)];
    }

    public function delete(array $keys): int
    {
        $removed = 0;

        foreach ($keys as $key) {
            $index = array_search($key, $this->keys, true);

            if ($index !== false) {
                unset($this->keys[$index]);
                $this->deleted[] = $key;
                $removed++;
            }
        }

        $this->keys = array_values($this->keys);

        return $removed;
    }
}
