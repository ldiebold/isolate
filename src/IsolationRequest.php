<?php

namespace Ldiebold\Isolate;

/**
 * What a single isolation run should do: select the next free number, use an
 * explicit one, or reset to vanilla (instance 0).
 */
readonly class IsolationRequest
{
    public function __construct(public ?int $number = null) {}

    /**
     * Auto-select the next free instance number.
     */
    public static function auto(): self
    {
        return new self;
    }

    /**
     * Use an explicit instance number.
     */
    public static function for(int $number): self
    {
        return new self($number);
    }

    /**
     * Return to vanilla (instance 0).
     */
    public static function reset(): self
    {
        return new self(0);
    }
}
