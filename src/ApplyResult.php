<?php

namespace Ldiebold\Isolate;

/**
 * The outcome of running a single Applier: the human-readable changes it made
 * plus any non-fatal warnings (e.g. a database that could not be created).
 */
class ApplyResult
{
    /**
     * @param  array<int, string>  $changes
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public array $changes = [],
        public array $warnings = [],
    ) {}

    public function addChange(string $change): self
    {
        $this->changes[] = $change;

        return $this;
    }

    public function addWarning(string $warning): self
    {
        $this->warnings[] = $warning;

        return $this;
    }

    public function merge(self $other): self
    {
        $this->changes = [...$this->changes, ...$other->changes];
        $this->warnings = [...$this->warnings, ...$other->warnings];

        return $this;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
