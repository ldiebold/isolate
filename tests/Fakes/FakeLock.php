<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\Lock;

final class FakeLock implements Lock
{
    public bool $used = false;

    public function get(callable $critical): mixed
    {
        $this->used = true;

        return $critical();
    }
}
