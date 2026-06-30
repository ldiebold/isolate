<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Ldiebold\Isolate\Contracts\KeyspaceFlusher;
use Ldiebold\Isolate\Events\PrefixFlushed;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\Redis\FlushResult;
use Ldiebold\Isolate\Tests\Fakes\FakeKeyspaceFlusher;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_teardown_redis_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->envPath = $this->dir.'/.env';
    $this->dbPath = $this->dir.'/database.sqlite';

    config()->set('app.url', 'http://localhost:8000');
    config()->set('database.default', 'isolate_sqlite');
    config()->set('database.connections.isolate_sqlite', ['driver' => 'sqlite', 'database' => $this->dbPath]);
    config()->set('database.redis', [
        'client' => 'phpredis',
        'options' => ['prefix' => 'fuellox-database-'],
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
    ]);

    config()->set('isolate.band_size', 100);
    config()->set('isolate.max_instances', 50);
    config()->set('isolate.env_path', $this->envPath);
    config()->set('isolate.env_example_path', $this->dir.'/.env.example');
    config()->set('isolate.lock_path', $this->dir.'/isolate.lock');
    config()->set('isolate.resources', [
        ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
        ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'keyspace' => 'redis', 'active_when' => ['config' => 'database.redis.options.prefix']],
    ]);

    $this->files->put($this->envPath, "APP_URL=http://localhost:8000\nSERVER_PORT=8000\n");
    $this->dbFile = fn (int $n): string => $this->dir."/database_{$n}.sqlite";

    // Swap the real per-connection flusher for an in-memory fake; the real
    // KeyspaceFlusherManager still enumerates the (single) configured connection.
    $this->fakeFlusher = function (array $results = [], array $counts = []): FakeKeyspaceFlusher {
        $fake = new FakeKeyspaceFlusher($results, $counts);
        app()->instance(KeyspaceFlusher::class, $fake);

        return $fake;
    };
});

afterEach(function () {
    unset($_SERVER['ISOLATE_NUMBER']);
    $this->files->deleteDirectory($this->dir);

    $cache = app()->getCachedConfigPath();
    if (is_file($cache)) {
        @unlink($cache);
    }
});

it('flushes the instance redis keyspace when tearing down a database', function () {
    $this->files->put(($this->dbFile)(7), '');
    $fake = ($this->fakeFlusher)(results: ['default' => FlushResult::flushed('fuellox-database-07', 5)]);

    $this->artisan('isolate:teardown', ['number' => '7', '--force' => true])
        ->expectsOutputToContain('Flushed 5 Redis key(s) for [fuellox-database-07]')
        ->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(7)))->toBeFalse()
        ->and($fake->flushed)->toBe([['default', 'fuellox-database-07']]);
});

it('does not flush redis with --keep-redis', function () {
    $this->files->put(($this->dbFile)(7), '');
    $fake = ($this->fakeFlusher)(results: ['default' => FlushResult::flushed('fuellox-database-07', 5)]);

    $this->artisan('isolate:teardown', ['number' => '7', '--force' => true, '--keep-redis' => true])
        ->assertSuccessful();

    expect($fake->flushed)->toBe([])
        ->and($this->files->exists(($this->dbFile)(7)))->toBeFalse();
});

it('dry-run counts the redis keyspace without flushing', function () {
    $this->files->put(($this->dbFile)(7), '');
    $fake = ($this->fakeFlusher)(counts: ['default' => 12]);

    $this->artisan('isolate:teardown', ['number' => '7', '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    // The dry run counts the prefix (read-only) and flushes nothing.
    expect($fake->counted)->toBe([['default', 'fuellox-database-07']])
        ->and($fake->flushed)->toBe([])
        ->and($this->files->exists(($this->dbFile)(7)))->toBeTrue();
});

it('flushes orphaned redis keys even when the database is already gone', function () {
    $fake = ($this->fakeFlusher)(results: ['default' => FlushResult::flushed('fuellox-database-08', 3)]);

    $this->artisan('isolate:teardown', ['number' => '8', '--force' => true])
        ->expectsOutputToContain('does not exist')
        ->expectsOutputToContain('Flushed 3 Redis key(s) for [fuellox-database-08]')
        ->assertSuccessful();

    expect($fake->flushed)->toBe([['default', 'fuellox-database-08']]);
});

it('reports when there are no redis keys to flush', function () {
    $this->files->put(($this->dbFile)(7), '');
    $fake = ($this->fakeFlusher)();

    $this->artisan('isolate:teardown', ['number' => '7', '--force' => true])
        ->expectsOutputToContain('No Redis keys to flush for [fuellox-database-07]')
        ->assertSuccessful();

    expect($fake->flushed)->toBe([['default', 'fuellox-database-07']]);
});

it('protects the vanilla redis prefix (instance 0)', function () {
    $this->files->put($this->dbPath, '');
    $fake = ($this->fakeFlusher)();

    $this->artisan('isolate:teardown', ['number' => '0', '--force' => true])
        ->expectsOutputToContain('vanilla')
        ->assertSuccessful();

    expect($fake->flushed)->toBe([]);
});

it('protects the active instance redis without --force', function () {
    $_SERVER['ISOLATE_NUMBER'] = '2';
    $this->files->put(($this->dbFile)(2), '');
    $fake = ($this->fakeFlusher)();

    $this->artisan('isolate:teardown', ['number' => '2'])
        ->expectsOutputToContain('active')
        ->assertSuccessful();

    expect($fake->flushed)->toBe([]);
});

it('flushes the active instance redis with --force and resets .env', function () {
    $_SERVER['ISOLATE_NUMBER'] = '2';
    $this->files->put(($this->dbFile)(2), '');
    $this->files->put($this->envPath, "APP_URL=http://localhost:8002\nSERVER_PORT=8002\nISOLATE_NUMBER=2\n");
    $fake = ($this->fakeFlusher)(results: ['default' => FlushResult::flushed('fuellox-database-02', 4)]);

    $this->artisan('isolate:teardown', ['number' => '2', '--force' => true])
        ->expectsOutputToContain('Flushed 4 Redis key(s) for [fuellox-database-02]')
        ->assertSuccessful();

    expect($fake->flushed)->toBe([['default', 'fuellox-database-02']])
        ->and($this->files->get($this->envPath))->toContain('ISOLATE_NUMBER=0');
});

it('degrades to a warning when the redis flush is skipped', function () {
    $this->files->put(($this->dbFile)(7), '');
    ($this->fakeFlusher)(results: [
        'default' => FlushResult::skipped('fuellox-database-07', 'Redis connection [default] is unavailable; skipped.'),
    ]);

    $this->artisan('isolate:teardown', ['number' => '7', '--force' => true])
        ->expectsOutputToContain('unavailable')
        ->assertSuccessful();

    expect($this->files->exists(($this->dbFile)(7)))->toBeFalse();
});

it('fires the afterPrefixFlushed hook and dispatches the PrefixFlushed event', function () {
    Event::fake([PrefixFlushed::class]);
    $this->files->put(($this->dbFile)(7), '');
    ($this->fakeFlusher)(results: ['default' => FlushResult::flushed('fuellox-database-07', 5)]);

    $captured = null;
    app(Isolate::class)->afterPrefixFlushed(function ($result, $plan) use (&$captured): void {
        $captured = $plan;
    });

    $this->artisan('isolate:teardown', ['number' => '7', '--force' => true])->assertSuccessful();

    expect($captured)->not->toBeNull()
        ->and($captured->get('REDIS_PREFIX'))->toBe('fuellox-database-07');
    Event::assertDispatched(PrefixFlushed::class);
});
