<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Database\ConnectionConfig;
use Ldiebold\Isolate\Database\DropOutcome;
use Ldiebold\Isolate\Database\SqliteDatabaseDestroyer;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->base = sys_get_temp_dir().'/isolate_sqlite_drop_'.uniqid();
    $this->files->makeDirectory($this->base);
    $this->destroyer = new SqliteDatabaseDestroyer($this->files, $this->base);
    $this->maintenance = new ConnectionConfig('sqlite', 'sqlite', []);
});

afterEach(function () {
    $this->files->deleteDirectory($this->base);
});

it('deletes the sqlite file and reports dropped', function () {
    $this->files->put($this->base.'/database_7.sqlite', '');

    $result = $this->destroyer->destroy($this->maintenance, 'database_7.sqlite');

    expect($result->outcome)->toBe(DropOutcome::Dropped)
        ->and($this->files->exists($this->base.'/database_7.sqlite'))->toBeFalse();
});

it('reports missing when the file is absent', function () {
    expect($this->destroyer->destroy($this->maintenance, 'database_7.sqlite')->outcome)
        ->toBe(DropOutcome::Missing);
});

it('removes the -wal, -shm and -journal sidecars', function () {
    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        $this->files->put($this->base.'/database_7.sqlite'.$suffix, '');
    }

    $this->destroyer->destroy($this->maintenance, 'database_7.sqlite');

    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        expect($this->files->exists($this->base.'/database_7.sqlite'.$suffix))->toBeFalse();
    }
});

it('leaves the parent directory in place', function () {
    $this->files->put($this->base.'/database_7.sqlite', '');

    $this->destroyer->destroy($this->maintenance, 'database_7.sqlite');

    expect($this->files->isDirectory($this->base))->toBeTrue();
});

it('skips an in-memory database with a message', function () {
    $result = $this->destroyer->destroy($this->maintenance, ':memory:');

    expect($result->outcome)->toBe(DropOutcome::Skipped)
        ->and($result->message)->not->toBeNull();
});
