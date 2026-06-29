<?php

namespace Ldiebold\Isolate\Support;

/**
 * Wraps the configured Chrome ERR_UNSAFE_PORT set for O(1) membership checks.
 */
class RestrictedPorts
{
    /**
     * @var array<int, bool>
     */
    protected array $set;

    /**
     * @param  array<int, int>  $ports
     */
    public function __construct(array $ports)
    {
        $this->set = array_fill_keys($ports, true);
    }

    public function isRestricted(int $port): bool
    {
        return isset($this->set[$port]);
    }
}
