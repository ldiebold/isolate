<?php

namespace Ldiebold\Isolate\Contracts;

interface DerivedResolver
{
    /**
     * Compute a derived env value from the already-resolved env map.
     *
     * @param  array<string, string>  $env
     */
    public function resolve(array $env, int $number): string;
}
