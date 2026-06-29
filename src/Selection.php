<?php

namespace Ldiebold\Isolate;

/**
 * The outcome of choosing an instance number: the number, whether it came from
 * this app's own recorded claim, and any non-fatal conflicts to warn about.
 */
readonly class Selection
{
    /**
     * @param  array<int, Conflict>  $conflicts
     */
    public function __construct(
        public int $number,
        public bool $isSelf,
        public array $conflicts = [],
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
