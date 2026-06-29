<?php

namespace Ldiebold\Isolate\Support;

use Composer\InstalledVersions;
use Ldiebold\Isolate\Contracts\PackageDetector;

/**
 * Detects installed Composer packages via the runtime InstalledVersions map.
 */
class ComposerPackageDetector implements PackageDetector
{
    public function installed(string $package): bool
    {
        return InstalledVersions::isInstalled($package);
    }
}
