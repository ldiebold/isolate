<?php

namespace Ldiebold\Isolate;

/**
 * The outcome of an isolation run: the chosen number, the applied plan, the
 * accumulated apply result and any non-fatal warnings gathered along the way.
 */
class IsolationResult
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public int $number,
        public IsolationPlan $plan,
        public ApplyResult $apply,
        public array $warnings = [],
    ) {}

    public function isReset(): bool
    {
        return $this->number === 0;
    }
}
