<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Support\WorktreeLocator;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_wtcmd_'.uniqid();
    $this->origin = $this->dir.'/origin';
    $this->files->makeDirectory($this->origin, 0755, true);
    $this->envPath = $this->dir.'/.env';

    config()->set('app.url', 'http://localhost:8000');
    config()->set('isolate.max_instances', 50);
    config()->set('isolate.env_path', $this->envPath);
    config()->set('isolate.env_example_path', $this->dir.'/.env.example');
    config()->set('isolate.lock_path', $this->dir.'/isolate.lock');
    config()->set('isolate.resources', [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
    ]);
    // A unique sentinel keeps the copy destination clear of any real skeleton file.
    config()->set('isolate.worktree.copy', ['isolate_sentinel.txt']);

    $this->files->put($this->envPath, "SERVER_PORT=8000\n");
    $this->files->put($this->origin.'/isolate_sentinel.txt', 'from-origin');

    $origin = $this->origin;
    $base = app()->basePath();
    $this->sentinel = app()->basePath('isolate_sentinel.txt');

    // Treat the app's base path as a linked worktree whose origin is our temp dir.
    app()->bind(WorktreeLocator::class, fn () => new WorktreeLocator($base, fn (array $args): ?string => match (implode(' ', $args)) {
        'rev-parse --absolute-git-dir' => $origin.'/.git/worktrees/w',
        'rev-parse --path-format=absolute --git-common-dir' => $origin.'/.git',
        'worktree list --porcelain' => "worktree {$origin}\n\nworktree {$base}\n",
        default => null,
    }));
});

afterEach(function () {
    $this->files->deleteDirectory($this->dir);
    @unlink($this->sentinel);

    $cache = app()->getCachedConfigPath();
    if (is_file($cache)) {
        @unlink($cache);
    }
});

it('hydrates worktree files from the origin during a real isolate run', function () {
    $this->artisan('isolate', ['--number' => '2'])
        ->expectsOutputToContain('Hydrated worktree from '.$this->origin)
        ->assertSuccessful();

    expect($this->files->get($this->sentinel))->toBe('from-origin')
        ->and($this->files->get($this->envPath))->toContain('SERVER_PORT=8002');
});

it('skips hydration with --no-copy', function () {
    $this->artisan('isolate', ['--number' => '2', '--no-copy' => true])->assertSuccessful();

    expect($this->files->exists($this->sentinel))->toBeFalse()
        ->and($this->files->get($this->envPath))->toContain('SERVER_PORT=8002');
});
