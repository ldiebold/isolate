<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\Teardown\TeardownRequest;
use Ldiebold\Isolate\Teardown\TeardownStatus;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_planner_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->dbPath = $this->dir.'/database.sqlite';

    config()->set('database.default', 'isolate_sqlite');
    config()->set('database.connections.isolate_sqlite', ['driver' => 'sqlite', 'database' => $this->dbPath]);
    config()->set('isolate.max_instances', 50);
    config()->set('isolate.resources', [
        ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
    ]);

    $this->plan = fn (TeardownRequest $request) => app(Isolate::class)->teardownPlanner()->plan($request);
    $this->dbFile = fn (int $n): string => $this->dir."/database_{$n}.sqlite";

    // Adds an active REDIS_PREFIX keyspace resource alongside the database one,
    // so the planner has redis siblings to derive.
    $this->useRedisPrefix = function (): void {
        config()->set('database.redis.options.prefix', 'fuellox-database-');
        config()->set('isolate.resources', [
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'keyspace' => 'redis', 'active_when' => ['config' => 'database.redis.options.prefix']],
        ]);
    };
});

afterEach(function () {
    unset($_SERVER['ISOLATE_NUMBER']);
    $this->files->deleteDirectory($this->dir);
});

it('marks an existing non-active instance as will-drop', function () {
    $this->files->put(($this->dbFile)(1), '');

    $targets = ($this->plan)(new TeardownRequest(number: 1));

    expect($targets)->toHaveCount(1)
        ->and($targets[0]->number)->toBe(1)
        ->and($targets[0]->status)->toBe(TeardownStatus::WillDrop);
});

it('protects vanilla (instance 0)', function () {
    $this->files->put($this->dbPath, '');

    expect(($this->plan)(new TeardownRequest(number: 0))[0]->status)->toBe(TeardownStatus::Vanilla);
});

it('protects the active instance unless forced', function () {
    $_SERVER['ISOLATE_NUMBER'] = '3';
    $this->files->put(($this->dbFile)(3), '');

    expect(($this->plan)(new TeardownRequest(number: 3))[0]->status)->toBe(TeardownStatus::ActiveProtected)
        ->and(($this->plan)(new TeardownRequest(number: 3, force: true))[0]->status)->toBe(TeardownStatus::WillDrop);
});

it('marks a missing database as missing', function () {
    expect(($this->plan)(new TeardownRequest(number: 9))[0]->status)->toBe(TeardownStatus::Missing);
});

it('carries the resolved plan so the env map is available to listeners', function () {
    $this->files->put(($this->dbFile)(4), '');

    $target = ($this->plan)(new TeardownRequest(number: 4))[0];

    expect($target->plan->get('DB_DATABASE'))->toContain('database_4.sqlite');
});

it('--all collects existing non-active, non-vanilla instances', function () {
    $_SERVER['ISOLATE_NUMBER'] = '3';
    $this->files->put($this->dbPath, '');
    $this->files->put(($this->dbFile)(1), '');
    $this->files->put(($this->dbFile)(2), '');
    $this->files->put(($this->dbFile)(3), '');

    $targets = collect(($this->plan)(new TeardownRequest(all: true)));

    expect($targets->where('status', TeardownStatus::WillDrop)->pluck('number')->all())->toBe([1, 2])
        ->and($targets->where('status', TeardownStatus::ActiveProtected)->pluck('number')->all())->toBe([3]);
});

it('--all respects the scan limit', function () {
    $this->files->put(($this->dbFile)(1), '');
    $this->files->put(($this->dbFile)(2), '');

    $targets = ($this->plan)(new TeardownRequest(all: true, limit: 2));

    expect(collect($targets)->pluck('number')->all())->toBe([1]);
});

it('emits a will-flush redis sibling with a padded prefix for an existing instance', function () {
    ($this->useRedisPrefix)();
    $this->files->put(($this->dbFile)(7), '');

    $planner = app(Isolate::class)->teardownPlanner();
    $redis = $planner->planRedis($planner->plan(new TeardownRequest(number: 7)));

    expect($redis)->toHaveCount(1)
        ->and($redis[0]->number)->toBe(7)
        ->and($redis[0]->env)->toBe('REDIS_PREFIX')
        ->and($redis[0]->prefix)->toBe('fuellox-database-07')
        ->and($redis[0]->status)->toBe(TeardownStatus::WillDrop)
        ->and($redis[0]->willFlush())->toBeTrue()
        ->and($redis[0]->plan->get('DB_DATABASE'))->toContain('database_7.sqlite');
});

it('protects the redis prefix for vanilla (instance 0)', function () {
    ($this->useRedisPrefix)();
    $this->files->put($this->dbPath, '');

    $planner = app(Isolate::class)->teardownPlanner();
    $redis = $planner->planRedis($planner->plan(new TeardownRequest(number: 0)));

    expect($redis[0]->status)->toBe(TeardownStatus::Vanilla)
        ->and($redis[0]->willFlush())->toBeFalse()
        ->and($redis[0]->prefix)->toBe('fuellox-database-');
});

it('protects the active instance redis prefix unless forced', function () {
    $_SERVER['ISOLATE_NUMBER'] = '3';
    ($this->useRedisPrefix)();
    $this->files->put(($this->dbFile)(3), '');

    $planner = app(Isolate::class)->teardownPlanner();

    $protected = $planner->planRedis($planner->plan(new TeardownRequest(number: 3)));
    $forced = $planner->planRedis($planner->plan(new TeardownRequest(number: 3, force: true)));

    expect($protected[0]->status)->toBe(TeardownStatus::ActiveProtected)
        ->and($forced[0]->status)->toBe(TeardownStatus::WillDrop);
});

it('still schedules a redis flush when the database is already gone (orphan cleanup)', function () {
    ($this->useRedisPrefix)();
    // No database file for instance 8.

    $planner = app(Isolate::class)->teardownPlanner();
    $databases = $planner->plan(new TeardownRequest(number: 8));
    $redis = $planner->planRedis($databases);

    expect($databases[0]->status)->toBe(TeardownStatus::Missing)
        ->and($redis[0]->status)->toBe(TeardownStatus::WillDrop)
        ->and($redis[0]->willFlush())->toBeTrue();
});

it('emits no redis siblings when no keyspace resource is active', function () {
    $this->files->put(($this->dbFile)(1), '');

    $planner = app(Isolate::class)->teardownPlanner();

    expect($planner->planRedis($planner->plan(new TeardownRequest(number: 1))))->toBe([]);
});

it('emits redis siblings for each instance collected by --all', function () {
    $_SERVER['ISOLATE_NUMBER'] = '3';
    ($this->useRedisPrefix)();
    $this->files->put($this->dbPath, '');
    $this->files->put(($this->dbFile)(1), '');
    $this->files->put(($this->dbFile)(2), '');
    $this->files->put(($this->dbFile)(3), '');

    $planner = app(Isolate::class)->teardownPlanner();
    $redis = collect($planner->planRedis($planner->plan(new TeardownRequest(all: true))));

    expect($redis->where('status', TeardownStatus::WillDrop)->pluck('prefix')->all())
        ->toBe(['fuellox-database-01', 'fuellox-database-02'])
        ->and($redis->where('status', TeardownStatus::ActiveProtected)->pluck('number')->all())
        ->toBe([3]);
});
