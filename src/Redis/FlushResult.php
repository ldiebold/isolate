<?php

namespace Ldiebold\Isolate\Redis;

/**
 * Result of attempting to flush a Redis keyspace (prefix): the outcome
 * (flushed / empty / skipped), the prefix, how many keys were removed and an
 * optional explanatory message used for graceful-degradation warnings. Mirrors
 * the database-side DropResult.
 */
readonly class FlushResult
{
    public function __construct(
        public FlushOutcome $outcome,
        public string $prefix,
        public int $keyCount = 0,
        public ?string $message = null,
    ) {}

    public static function flushed(string $prefix, int $keyCount, ?string $message = null): self
    {
        return new self(FlushOutcome::Flushed, $prefix, $keyCount, $message);
    }

    public static function empty(string $prefix, ?string $message = null): self
    {
        return new self(FlushOutcome::Empty, $prefix, 0, $message);
    }

    public static function skipped(string $prefix, ?string $message = null): self
    {
        return new self(FlushOutcome::Skipped, $prefix, 0, $message);
    }

    public function wasFlushed(): bool
    {
        return $this->outcome === FlushOutcome::Flushed;
    }
}
