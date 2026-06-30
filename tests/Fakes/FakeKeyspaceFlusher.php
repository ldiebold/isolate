<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\KeyspaceFlusher;
use Ldiebold\Isolate\Redis\FlushResult;

/**
 * Per-connection KeyspaceFlusher fake. Records every flush/count call and
 * returns canned results keyed by connection name (falling back to an empty
 * flush / null count), so the manager and command can be tested without Redis.
 */
final class FakeKeyspaceFlusher implements KeyspaceFlusher
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    public array $flushed = [];

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    public array $counted = [];

    /**
     * @param  array<string, FlushResult>  $results  Result per connection name.
     * @param  array<string, int|null>  $counts  Count per connection name.
     */
    public function __construct(
        private array $results = [],
        private array $counts = [],
    ) {}

    public function flush(string $connectionName, string $prefix): FlushResult
    {
        $this->flushed[] = [$connectionName, $prefix];

        return $this->results[$connectionName] ?? FlushResult::empty($prefix);
    }

    public function count(string $connectionName, string $prefix): ?int
    {
        $this->counted[] = [$connectionName, $prefix];

        return $this->counts[$connectionName] ?? null;
    }
}
