<?php

namespace Ldiebold\Isolate\Database;

/**
 * Result of ensuring a database exists: the outcome (created / existed /
 * skipped), the database name and an optional explanatory message used for
 * graceful-degradation warnings.
 */
readonly class CreateResult
{
    public function __construct(
        public CreateOutcome $outcome,
        public string $database,
        public ?string $message = null,
    ) {}

    public static function created(string $database, ?string $message = null): self
    {
        return new self(CreateOutcome::Created, $database, $message);
    }

    public static function existed(string $database, ?string $message = null): self
    {
        return new self(CreateOutcome::Existed, $database, $message);
    }

    public static function skipped(string $database, ?string $message = null): self
    {
        return new self(CreateOutcome::Skipped, $database, $message);
    }

    public function wasCreated(): bool
    {
        return $this->outcome === CreateOutcome::Created;
    }
}
