<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\PortChecker;

final class FakePortChecker implements PortChecker
{
    /**
     * @param  array<int, int>  $inUse
     */
    public function __construct(private array $inUse = []) {}

    public function inUse(int $port): bool
    {
        return in_array($port, $this->inUse, true);
    }

    public function markInUse(int $port): void
    {
        $this->inUse[] = $port;
    }
}
