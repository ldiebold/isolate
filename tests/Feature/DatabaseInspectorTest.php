<?php

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Database\DatabaseInspector;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->base = sys_get_temp_dir().'/isolate_inspect_'.uniqid();
    $this->files->makeDirectory($this->base);

    $config = new Repository(['database' => ['connections' => ['sqlite' => ['driver' => 'sqlite']]]]);
    $this->inspector = new DatabaseInspector($config, app('db'), $this->files, $this->base);
});

afterEach(function () {
    $this->files->deleteDirectory($this->base);
});

it('detects an existing sqlite file', function () {
    $this->files->put($this->base.'/forge_7.sqlite', '');

    expect($this->inspector->exists('sqlite', 'forge_7.sqlite'))->toBeTrue();
});

it('reports a missing sqlite file as absent', function () {
    expect($this->inspector->exists('sqlite', 'forge_9.sqlite'))->toBeFalse();
});

it('treats in-memory and empty sqlite names as absent', function () {
    expect($this->inspector->exists('sqlite', ':memory:'))->toBeFalse()
        ->and($this->inspector->exists('sqlite', ''))->toBeFalse();
});

it('returns false for an unknown driver', function () {
    $config = new Repository(['database' => ['connections' => ['mongo' => ['driver' => 'mongo']]]]);

    $inspector = new DatabaseInspector($config, app('db'), $this->files, $this->base);

    expect($inspector->exists('mongo', 'whatever'))->toBeFalse();
});
