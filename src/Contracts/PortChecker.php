<?php

namespace Ldiebold\Isolate\Contracts;

interface PortChecker
{
    /**
     * True when anything is already bound / listening on the given port.
     */
    public function inUse(int $port): bool;
}
