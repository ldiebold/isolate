<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\DerivedResolver;

final class StaticDerivedResolver implements DerivedResolver
{
    /**
     * @param  array<string, string>  $env
     */
    public function resolve(array $env, int $number): string
    {
        return 'resolved-'.$number;
    }
}
