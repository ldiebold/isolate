<?php

namespace Ldiebold\Isolate\Contracts;

interface PackageDetector
{
    /**
     * True when the given Composer package (e.g. "laravel/reverb") is installed.
     */
    public function installed(string $package): bool;
}
