<?php

namespace Ldiebold\Isolate\Contracts;

interface Lock
{
    /**
     * Run $critical under the lock and return its value. Degrades with a
     * warning (still running $critical) when the lock cannot be acquired.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $critical
     * @return TReturn
     */
    public function get(callable $critical): mixed;
}
