<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Contracts\PackageDetector;

final class FakePackageDetector implements PackageDetector
{
    /**
     * @param  array<int, string>  $installed
     */
    public function __construct(private array $installed = []) {}

    public function installed(string $package): bool
    {
        return in_array($package, $this->installed, true);
    }
}
