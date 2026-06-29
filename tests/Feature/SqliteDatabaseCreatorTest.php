<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Database\ConnectionConfig;
use Ldiebold\Isolate\Database\CreateOutcome;
use Ldiebold\Isolate\Database\SqliteDatabaseCreator;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->base = sys_get_temp_dir().'/isolate_sqlite_'.uniqid();
    $this->files->makeDirectory($this->base);
    $this->creator = new SqliteDatabaseCreator($this->files, $this->base);
    $this->maintenance = new ConnectionConfig('sqlite', 'sqlite', []);
});

afterEach(function () {
    $this->files->deleteDirectory($this->base);
});

it('creates a sqlite file and is idempotent on re-run', function () {
    $first = $this->creator->ensureExists($this->maintenance, 'database_7.sqlite');

    expect($first->outcome)->toBe(CreateOutcome::Created)
        ->and($this->files->exists($this->base.'/database_7.sqlite'))->toBeTrue();

    $second = $this->creator->ensureExists($this->maintenance, 'database_7.sqlite');

    expect($second->outcome)->toBe(CreateOutcome::Existed);
});

it('resolves a relative path under the database path and creates missing parents', function () {
    $result = $this->creator->ensureExists($this->maintenance, 'nested/dir/db_3.sqlite');

    expect($result->outcome)->toBe(CreateOutcome::Created)
        ->and($this->files->exists($this->base.'/nested/dir/db_3.sqlite'))->toBeTrue();
});

it('honours an absolute path', function () {
    $absolute = $this->base.'/abs.sqlite';

    $result = $this->creator->ensureExists($this->maintenance, $absolute);

    expect($result->outcome)->toBe(CreateOutcome::Created)
        ->and($this->files->exists($absolute))->toBeTrue();
});

it('skips an in-memory database with a warning message', function () {
    $result = $this->creator->ensureExists($this->maintenance, ':memory:');

    expect($result->outcome)->toBe(CreateOutcome::Skipped)
        ->and($result->message)->not->toBeNull();
});
