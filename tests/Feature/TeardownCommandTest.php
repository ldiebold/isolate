<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Ldiebold\Isolate\Events\DatabaseDropped;
use Ldiebold\Isolate\Isolate;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_teardown_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->envPath = $this->dir.'/.env';
    $this->dbPath = $this->dir.'/database.sqlite';

    config()->set('app.url', 'http://localhost:8000');
    config()->set('database.default', 'isolate_sqlite');
    config()->set('database.connections.isolate_sqlite', ['driver' => 'sqlite', 'database' => $this->dbPath]);

    config()->set('isolate.band_size', 100);
    config()->set('isolate.max_instances', 50);
    config()->set('isolate.env_path', $this->envPath);
    config()->set('isolate.env_example_path', $this->dir.'/.env.example');
    config()->set('isolate.lock_path', $this->dir.'/isolate.lock');
    config()->set('isolate.resources', [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
        ['type' => 'derived', 'env' => 'APP_URL', 'rewrite_port_of' => 'APP_URL', 'port_from' => 'SERVER_PORT', 'active_when' => 'always'],
        ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
    ]);

    $this->files->put($this->envPath, "APP_URL=http://localhost:8000\nSERVER_PORT=8000\n");
    $this->dbFile = fn (int $n): string => $this->dir."/database_{$n}.sqlite";
});

afterEach(function () {
    unset($_SERVER['ISOLATE_NUMBER']);
    $this->files->deleteDirectory($this->dir);

    $cache = app()->getCachedConfigPath();
    if (is_file($cache)) {
        @unlink($cache);
    }
});

it('drops a named instance database', function () {
    $this->files->put(($this->dbFile)(1), '');

    $this->artisan('isolate:teardown', ['number' => '1', '--force' => true])->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(1)))->toBeFalse();
});

it('is idempotent: a missing database reports nothing to drop', function () {
    $this->artisan('isolate:teardown', ['number' => '1', '--force' => true])
        ->expectsOutputToContain('does not exist')
        ->assertSuccessful();
});

it('refuses to drop vanilla (instance 0)', function () {
    $this->files->put($this->dbPath, '');

    $this->artisan('isolate:teardown', ['number' => '0', '--force' => true])
        ->expectsOutputToContain('vanilla')
        ->assertSuccessful();

    expect($this->files->exists($this->dbPath))->toBeTrue();
});

it('refuses the active instance without --force', function () {
    $_SERVER['ISOLATE_NUMBER'] = '2';
    $this->files->put(($this->dbFile)(2), '');

    $this->artisan('isolate:teardown', ['number' => '2'])
        ->expectsOutputToContain('active')
        ->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(2)))->toBeTrue();
});

it('drops the active instance with --force and resets .env to vanilla', function () {
    $_SERVER['ISOLATE_NUMBER'] = '2';
    $this->files->put(($this->dbFile)(2), '');
    $this->files->put($this->envPath, "APP_URL=http://localhost:8002\nSERVER_PORT=8002\nISOLATE_NUMBER=2\n");

    $this->artisan('isolate:teardown', ['number' => '2', '--force' => true])->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(2)))->toBeFalse()
        ->and($this->files->get($this->envPath))->toContain('ISOLATE_NUMBER=0')
        ->and($this->files->get($this->envPath))->toContain('SERVER_PORT=8000');
});

it('keeps .env when tearing down the active instance with --keep-env', function () {
    $_SERVER['ISOLATE_NUMBER'] = '2';
    $this->files->put(($this->dbFile)(2), '');
    $this->files->put($this->envPath, "SERVER_PORT=8002\nISOLATE_NUMBER=2\n");

    $this->artisan('isolate:teardown', ['number' => '2', '--force' => true, '--keep-env' => true])->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(2)))->toBeFalse()
        ->and($this->files->get($this->envPath))->toContain('ISOLATE_NUMBER=2');
});

it('--all drops existing non-active, non-vanilla instances only', function () {
    $_SERVER['ISOLATE_NUMBER'] = '3';
    $this->files->put($this->dbPath, '');
    $this->files->put(($this->dbFile)(1), '');
    $this->files->put(($this->dbFile)(2), '');
    $this->files->put(($this->dbFile)(3), '');

    $this->artisan('isolate:teardown', ['--all' => true, '--force' => true])->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(1)))->toBeFalse()
        ->and($this->files->exists(($this->dbFile)(2)))->toBeFalse()
        ->and($this->files->exists(($this->dbFile)(3)))->toBeTrue()
        ->and($this->files->exists($this->dbPath))->toBeTrue();
});

it('--dry-run changes nothing', function () {
    $this->files->put(($this->dbFile)(1), '');

    $this->artisan('isolate:teardown', ['number' => '1', '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(1)))->toBeTrue();
});

it('aborts when the confirmation is declined', function () {
    $this->files->put(($this->dbFile)(1), '');

    $this->artisan('isolate:teardown', ['number' => '1'])
        ->expectsConfirmation('This deletes the data for good. Continue?', 'no')
        ->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(1)))->toBeTrue();
});

it('drops after the confirmation is accepted', function () {
    $this->files->put(($this->dbFile)(1), '');

    $this->artisan('isolate:teardown', ['number' => '1'])
        ->expectsConfirmation('This deletes the data for good. Continue?', 'yes')
        ->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(1)))->toBeFalse();
});

it('bypasses confirmation with --no-interaction', function () {
    $this->files->put(($this->dbFile)(1), '');

    $this->artisan('isolate:teardown', ['number' => '1', '--no-interaction' => true])->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(1)))->toBeFalse();
});

it('fails when neither a number nor --all is given', function () {
    $this->artisan('isolate:teardown')->assertFailed();
});

it('fires the afterDatabaseDropped hook with the resolved plan', function () {
    $this->files->put(($this->dbFile)(1), '');

    $captured = null;
    app(Isolate::class)->afterDatabaseDropped(function ($result, $plan) use (&$captured): void {
        $captured = $plan;
    });

    $this->artisan('isolate:teardown', ['number' => '1', '--force' => true])->assertSuccessful();

    expect($captured)->not->toBeNull()
        ->and($captured->get('DB_DATABASE'))->toContain('database_1.sqlite');
});

it('dispatches the DatabaseDropped event', function () {
    Event::fake([DatabaseDropped::class]);
    $this->files->put(($this->dbFile)(1), '');

    $this->artisan('isolate:teardown', ['number' => '1', '--force' => true])->assertSuccessful();

    Event::assertDispatched(DatabaseDropped::class);
});
