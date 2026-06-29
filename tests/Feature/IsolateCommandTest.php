<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Ldiebold\Isolate\Support\ConfigCacheGuard;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_cmd_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->envPath = $this->dir.'/.env';
    $this->dbPath = $this->dir.'/database.sqlite';

    config()->set('app.url', 'http://localhost:8000');
    config()->set('database.default', 'isolate_sqlite');
    config()->set('database.connections.isolate_sqlite', ['driver' => 'sqlite', 'database' => $this->dbPath]);

    config()->set('isolate.band_size', 100);
    config()->set('isolate.max_instances', 50);
    config()->set('isolate.throw_on_conflict', false);
    config()->set('isolate.env_path', $this->envPath);
    config()->set('isolate.env_example_path', $this->dir.'/.env.example');
    config()->set('isolate.lock_path', $this->dir.'/isolate.lock');
    config()->set('isolate.resources', [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
        ['type' => 'derived', 'env' => 'APP_URL', 'rewrite_port_of' => 'APP_URL', 'port_from' => 'SERVER_PORT', 'active_when' => 'always'],
        ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
    ]);

    $this->files->put($this->envPath, "APP_URL=http://localhost:8000\nSERVER_PORT=8000\n");
});

afterEach(function () {
    $this->files->deleteDirectory($this->dir);

    $cache = app()->getCachedConfigPath();
    if (is_file($cache)) {
        @unlink($cache);
    }
});

it('auto-isolates with no flags', function () {
    $this->artisan('isolate')->assertSuccessful();

    expect($this->files->get($this->envPath))->toContain('ISOLATE_NUMBER=');
});

it('isolates to an explicit band with --number', function () {
    $this->artisan('isolate', ['--number' => '3'])->assertSuccessful();

    $env = $this->files->get($this->envPath);

    expect($env)
        ->toContain('ISOLATE_NUMBER=3')
        ->toContain('SERVER_PORT=8003')
        ->toContain('APP_URL=http://localhost:8003')
        ->toContain('database_3.sqlite')
        ->and($this->files->exists($this->dir.'/database_3.sqlite'))->toBeTrue();
});

it('resets to vanilla with --reset', function () {
    $this->artisan('isolate', ['--number' => '4'])->assertSuccessful();
    $this->artisan('isolate', ['--reset' => true])->assertSuccessful();

    expect($this->files->get($this->envPath))
        ->toContain('ISOLATE_NUMBER=0')
        ->toContain('SERVER_PORT=8000');
});

it('prefers --reset over --number', function () {
    $this->artisan('isolate', ['--reset' => true, '--number' => '9'])->assertSuccessful();

    expect($this->files->get($this->envPath))->toContain('ISOLATE_NUMBER=0');
});

it('is idempotent across repeated runs', function () {
    $this->artisan('isolate', ['--number' => '3'])->assertSuccessful();
    $first = $this->files->get($this->envPath);

    $this->artisan('isolate', ['--number' => '3'])->assertSuccessful();
    $second = $this->files->get($this->envPath);

    expect($second)->toBe($first);
});

it('rejects an out-of-range --number', function () {
    $this->artisan('isolate', ['--number' => '999'])->assertFailed();
});

it('warns about a detected conflict on an explicit number by default', function () {
    $this->files->put($this->dir.'/database_3.sqlite', '');

    $this->artisan('isolate', ['--number' => '3'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect($this->files->get($this->envPath))->toContain('ISOLATE_NUMBER=3');
});

it('fails an explicit conflicting selection when throw_on_conflict is true', function () {
    config()->set('isolate.throw_on_conflict', true);
    $this->files->put($this->dir.'/database_3.sqlite', '');

    $this->artisan('isolate', ['--number' => '3'])->assertFailed();
});

it('isolates a multi-key port resource (reverb) without a duplicate-port error', function () {
    config()->set('isolate.resources', [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
        ['type' => 'port', 'env' => ['REVERB_SERVER_PORT', 'REVERB_PORT'], 'base' => 8100, 'browser_facing' => true, 'active_when' => 'always'],
    ]);

    $this->artisan('isolate', ['--number' => '5'])->assertSuccessful();

    expect($this->files->get($this->envPath))
        ->toContain('REVERB_SERVER_PORT=8105')
        ->toContain('REVERB_PORT=8105');
});

it('runs migrations against the new database with --migrate', function () {
    $this->artisan('isolate', ['--number' => '5', '--migrate' => true])->assertSuccessful();

    expect(Schema::connection('isolate_sqlite')->hasTable('migrations'))->toBeTrue();
});

it('fails when the migration step fails', function () {
    // Use a file as the database directory's parent so the connection cannot open.
    $blocker = $this->dir.'/blocker';
    $this->files->put($blocker, '');
    config()->set('database.connections.isolate_sqlite.database', $blocker.'/database.sqlite');

    $this->artisan('isolate', ['--number' => '6', '--migrate' => true])->assertFailed();
});

it('clears a cached config after writing', function () {
    app()->bind(ConfigCacheGuard::class, fn () => new class(app()) extends ConfigCacheGuard
    {
        public function freshen(): ?int
        {
            return null;
        }
    });

    $cachePath = app()->getCachedConfigPath();
    $this->files->ensureDirectoryExists(dirname($cachePath));
    $this->files->put($cachePath, '<?php return [];');
    expect($this->files->exists($cachePath))->toBeTrue();

    $this->artisan('isolate', ['--number' => '2'])->assertSuccessful();

    expect($this->files->exists($cachePath))->toBeFalse();
});

it('short-circuits with the guard exit code when config must be refreshed', function () {
    app()->bind(ConfigCacheGuard::class, fn () => new class(app()) extends ConfigCacheGuard
    {
        public function freshen(): ?int
        {
            return 42;
        }
    });

    $this->artisan('isolate')->assertExitCode(42);

    expect($this->files->get($this->envPath))->not->toContain('ISOLATE_NUMBER');
});
