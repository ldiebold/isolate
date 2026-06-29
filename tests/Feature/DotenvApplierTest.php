<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Appliers\DotenvApplier;
use Ldiebold\Isolate\Env\LineDotenvWriter;
use Ldiebold\Isolate\IsolationPlan;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->envPath = $this->dir.'/.env';
    $this->examplePath = $this->dir.'/.env.example';

    $this->applier = new DotenvApplier(
        new LineDotenvWriter,
        $this->files,
        $this->envPath,
        $this->examplePath,
    );
});

afterEach(function () {
    $this->files->deleteDirectory($this->dir);
});

it('updates an existing .env, preserving comments', function () {
    $this->files->put($this->envPath, "# config\nSERVER_PORT=8000\nAPP_URL=http://localhost:8000\n");

    $result = $this->applier->apply(new IsolationPlan(7, [
        'SERVER_PORT' => '8007',
        'APP_URL' => 'http://localhost:8007',
    ]));

    $contents = $this->files->get($this->envPath);

    expect($contents)
        ->toContain("# config\n")
        ->toContain('SERVER_PORT=8007')
        ->toContain('APP_URL=http://localhost:8007')
        ->and($result->changes)->not->toBeEmpty();
});

it('seeds from .env.example when no .env exists', function () {
    $this->files->put($this->examplePath, "SERVER_PORT=8000\nREDIS_PREFIX=fuellox\n");

    $result = $this->applier->apply(new IsolationPlan(7, [
        'SERVER_PORT' => '8007',
        'REDIS_PREFIX' => 'fuellox_7',
    ]));

    expect($this->files->exists($this->envPath))->toBeTrue()
        ->and($this->files->get($this->envPath))->toContain('SERVER_PORT=8007')
        ->and($result->changes)->toContain('Seeded .env from .env.example');
});

it('creates a fresh .env when neither file exists', function () {
    $this->applier->apply(new IsolationPlan(7, ['SERVER_PORT' => '8007']));

    expect($this->files->get($this->envPath))->toContain('SERVER_PORT=8007');
});

it('is idempotent: re-applying the same plan reports no value changes', function () {
    $this->files->put($this->envPath, "SERVER_PORT=8007\n");

    $result = $this->applier->apply(new IsolationPlan(7, ['SERVER_PORT' => '8007']));

    expect($result->changes)->toBe([]);
});
