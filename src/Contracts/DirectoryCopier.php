<?php

namespace Ldiebold\Isolate\Contracts;

interface DirectoryCopier
{
    /**
     * Recursively copy the directory tree at $source into $destination,
     * preserving symlinks.
     *
     * Implementations must be atomic: the copy lands in a temporary sibling and
     * is only moved into $destination once complete, so an interrupted or failed
     * copy never leaves a half-populated directory in place. On failure a
     * RuntimeException is thrown and $destination is not created.
     */
    public function copy(string $source, string $destination): void;
}
