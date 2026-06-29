<?php

namespace Ldiebold\Isolate\Resources;

/**
 * A port resource: every env key it owns resolves to base + n. May be flagged
 * browser_facing so the number selector keeps it off Chrome-restricted ports.
 */
class PortResource extends Resource
{
    public function base(): int
    {
        return (int) ($this->definition['base'] ?? 0);
    }

    public function browserFacing(): bool
    {
        return (bool) ($this->definition['browser_facing'] ?? false);
    }

    public function portFor(int $n): int
    {
        return $this->base() + $n;
    }
}
