<?php

namespace Ldiebold\Isolate\Contracts;

interface EnvWriter
{
    /**
     * Return $contents with each key in $values replaced (on a `^KEY=` line) or
     * appended. Pure: operates on and returns strings only.
     *
     * @param  array<string, string>  $values
     */
    public function upsert(string $contents, array $values): string;
}
