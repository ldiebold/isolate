<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Support\PhpDirectoryCopier;
use Ldiebold\Isolate\Support\SystemDirectoryCopier;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_copier_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->source = $this->dir.'/source';
    $this->dest = $this->dir.'/dest';
});

afterEach(function () {
    $this->files->deleteDirectory($this->dir);
});

dataset('copiers', [
    'php' => fn () => new PhpDirectoryCopier(new Filesystem),
    'system' => fn () => new SystemDirectoryCopier(new Filesystem, new PhpDirectoryCopier(new Filesystem)),
]);

it('copies a nested tree of files', function ($copier) {
    $this->files->makeDirectory($this->source.'/nested/deep', 0755, true);
    $this->files->put($this->source.'/root.txt', 'root');
    $this->files->put($this->source.'/nested/leaf.txt', 'leaf');
    $this->files->put($this->source.'/nested/deep/pearl.txt', 'pearl');

    $copier->copy($this->source, $this->dest);

    expect($this->files->get($this->dest.'/root.txt'))->toBe('root')
        ->and($this->files->get($this->dest.'/nested/leaf.txt'))->toBe('leaf')
        ->and($this->files->get($this->dest.'/nested/deep/pearl.txt'))->toBe('pearl');
})->with('copiers');

it('preserves symlinks rather than following them', function ($copier) {
    $this->files->makeDirectory($this->source, 0755, true);
    $this->files->put($this->source.'/real.txt', 'real');
    symlink('real.txt', $this->source.'/link.txt');

    $copier->copy($this->source, $this->dest);

    expect(is_link($this->dest.'/link.txt'))->toBeTrue()
        ->and(readlink($this->dest.'/link.txt'))->toBe('real.txt');
})->with('copiers');

it('throws and leaves nothing behind when the source is missing', function ($copier) {
    expect(fn () => $copier->copy($this->source.'/missing', $this->dest))
        ->toThrow(RuntimeException::class);

    // Neither the destination nor a leftover temp sibling should remain.
    expect($this->files->glob($this->dir.'/dest*'))->toBe([]);
})->with('copiers');

it('lands atomically: the destination only appears once complete', function ($copier) {
    $this->files->makeDirectory($this->source, 0755, true);
    $this->files->put($this->source.'/a.txt', 'a');

    $copier->copy($this->source, $this->dest);

    expect($this->files->isDirectory($this->dest))->toBeTrue()
        ->and($this->files->glob($this->dir.'/*.isolate-tmp-*'))->toBe([]);
})->with('copiers');
