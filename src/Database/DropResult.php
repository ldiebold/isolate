<?php

namespace Ldiebold\Isolate\Database;

/**
 * Result of attempting to drop a database: the outcome (dropped / missing /
 * skipped), the database name and an optional explanatory message used for
 * graceful-degradation warnings.
 */
readonly class DropResult
{
    public function __construct(
        public DropOutcome $outcome,
        public string $database,
        public ?string $message = null,
    ) {}

    public static function dropped(string $database, ?string $message = null): self
    {
        return new self(DropOutcome::Dropped, $database, $message);
    }

    public static function missing(string $database, ?string $message = null): self
    {
        return new self(DropOutcome::Missing, $database, $message);
    }

    public static function skipped(string $database, ?string $message = null): self
    {
        return new self(DropOutcome::Skipped, $database, $message);
    }

    public function wasDropped(): bool
    {
        return $this->outcome === DropOutcome::Dropped;
    }
}
