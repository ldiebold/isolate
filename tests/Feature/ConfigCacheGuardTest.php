<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Support\ConfigCacheGuard;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->cachePath = app()->getCachedConfigPath();
    $this->files->ensureDirectoryExists(dirname($this->cachePath));
});

afterEach(function () {
    if (is_file($this->cachePath)) {
        @unlink($this->cachePath);
    }
});

it('proceeds without re-exec when no cached config exists', function () {
    if (is_file($this->cachePath)) {
        unlink($this->cachePath);
    }

    $guard = new ConfigCacheGuard(app(), fn (array $argv): int => 1);

    expect($guard->freshen())->toBeNull();
});

it('clears a cached config and re-execs exactly once', function () {
    $this->files->put($this->cachePath, '<?php return [];');

    $calls = 0;
    $guard = new ConfigCacheGuard(app(), function (array $argv) use (&$calls): int {
        $calls++;

        return 7;
    });

    expect($guard->freshen())->toBe(7)
        ->and($calls)->toBe(1)
        ->and($this->files->exists($this->cachePath))->toBeFalse()
        ->and($guard->freshen())->toBeNull();
});
