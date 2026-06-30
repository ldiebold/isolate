<?php

use Ldiebold\Isolate\Exceptions\InvalidConfigurationException;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\Support\BandValidator;
use Ldiebold\Isolate\Tests\Fakes\StaticDerivedResolver;

it('resolves ports and the derived url at n = 0 (vanilla)', function () {
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'active_when' => 'always'],
        ['type' => 'derived', 'env' => 'APP_URL', 'rewrite_port_of' => 'APP_URL', 'port_from' => 'SERVER_PORT', 'active_when' => 'always'],
    ]]);

    $plan = $resolver->resolve(0);

    expect($plan->envMap)->toBe([
        'SERVER_PORT' => '8000',
        'APP_URL' => 'http://localhost:8000',
    ]);
});

it('adds the same n to every base at n = 7', function () {
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'active_when' => 'always'],
        ['type' => 'derived', 'env' => 'APP_URL', 'rewrite_port_of' => 'APP_URL', 'port_from' => 'SERVER_PORT', 'active_when' => 'always'],
    ]]);

    $plan = $resolver->resolve(7);

    expect($plan->get('SERVER_PORT'))->toBe('8007')
        ->and($plan->get('APP_URL'))->toBe('http://localhost:8007');
});

it('omits resources whose predicate is inactive', function () {
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'port', 'env' => 'VITE_PORT', 'base' => 8200, 'active_when' => ['env' => 'VITE_PORT']],
    ]], ['env' => fn (string $key): mixed => null]);

    expect($resolver->resolve(3)->has('VITE_PORT'))->toBeFalse();
});

it('activates package resources only when the package is installed, writing every env key', function () {
    $resources = ['isolate.resources' => [
        ['type' => 'port', 'env' => ['REVERB_SERVER_PORT', 'REVERB_PORT'], 'base' => 8100, 'active_when' => ['package' => 'laravel/reverb']],
    ]];

    expect(makeResolver($resources)->resolve(4)->has('REVERB_PORT'))->toBeFalse();

    $plan = makeResolver($resources, ['packages' => ['laravel/reverb']])->resolve(4);

    expect($plan->get('REVERB_SERVER_PORT'))->toBe('8104')
        ->and($plan->get('REVERB_PORT'))->toBe('8104');
});

it('derives a name from the isolate name with no suffix at n = 0', function () {
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'name', 'env' => 'REDIS_PREFIX', 'base_from' => 'isolate.name', 'active_when' => 'always'],
    ]]);

    expect($resolver->resolve(0)->get('REDIS_PREFIX'))->toBe('fuellox')
        ->and($resolver->resolve(7)->get('REDIS_PREFIX'))->toBe('fuellox_7');
});

it('reads a name base from config so n = 0 returns the exact vanilla value', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config)->resolve(0)->get('REDIS_PREFIX'))->toBe('fuellox-database-')
        ->and(makeResolver($config)->resolve(7)->get('REDIS_PREFIX'))->toBe('fuellox-database-7');
});

it('re-derives a config-based name idempotently using the recorded current number', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-7']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config, ['currentNumber' => 7])->resolve(7)->get('REDIS_PREFIX'))
        ->toBe('fuellox-database-7');
});

it('migrates a legacy doubled-separator prefix to the collapsed form', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-_7']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config, ['currentNumber' => 7])->resolve(7)->get('REDIS_PREFIX'))
        ->toBe('fuellox-database-7');
});

it('zero-pads a redis keyspace prefix so instance numbers cannot overlap', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'keyspace' => 'redis', 'active_when' => 'always'],
        ],
    ];

    $seven = makeResolver($config)->resolve(7)->get('REDIS_PREFIX');
    $seventy = makeResolver($config)->resolve(70)->get('REDIS_PREFIX');

    expect($seven)->toBe('fuellox-database-07')
        ->and($seventy)->toBe('fuellox-database-70')
        ->and(str_starts_with($seventy, $seven))->toBeFalse();
});

it('omits the keyspace suffix at n = 0 (vanilla)', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'keyspace' => 'redis', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config)->resolve(0)->get('REDIS_PREFIX'))->toBe('fuellox-database-');
});

it('re-derives a padded keyspace prefix idempotently using the recorded current number', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-07']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'keyspace' => 'redis', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config, ['currentNumber' => 7])->resolve(7)->get('REDIS_PREFIX'))
        ->toBe('fuellox-database-07');
});

it('lists the env keys of active redis keyspace resources', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'keyspace' => 'redis', 'active_when' => 'always'],
            ['type' => 'name', 'env' => 'HORIZON_PREFIX', 'base' => 'horizon-', 'keyspace' => 'redis', 'active_when' => 'always'],
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config)->redisKeyspaceEnvKeys())->toBe(['REDIS_PREFIX', 'HORIZON_PREFIX']);
});

it('lists no keyspace env keys when none are marked', function () {
    $config = [
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config)->redisKeyspaceEnvKeys())->toBe([]);
});

it('does not pad a name resource without a keyspace marker', function () {
    $config = [
        'database' => ['redis' => ['options' => ['prefix' => 'fuellox-database-']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config)->resolve(7)->get('REDIS_PREFIX'))->toBe('fuellox-database-7');
});

it('normalizes a database identifier and emits the create_database side effect', function () {
    $resolver = makeResolver([
        'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'My DB']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
        ],
    ]);

    $plan = $resolver->resolve(7);

    expect($plan->get('DB_DATABASE'))->toBe('my_db_7');

    expect($plan->sideEffects)->toHaveCount(1)
        ->and($plan->sideEffects[0]->get('database'))->toBe('my_db_7')
        ->and($plan->sideEffects[0]->get('connection'))->toBe('pgsql');
});

it('re-derives a database identifier idempotently using the recorded current number', function () {
    $resolver = makeResolver([
        'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'forge_7']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
        ],
    ], ['currentNumber' => 7]);

    expect($resolver->resolve(7)->get('DB_DATABASE'))->toBe('forge_7');
});

it('derives a per-instance sqlite path by inserting the suffix before the extension', function () {
    $config = [
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => 'database/database.sqlite']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config)->resolve(0)->get('DB_DATABASE'))->toBe('database/database.sqlite')
        ->and(makeResolver($config)->resolve(7)->get('DB_DATABASE'))->toBe('database/database_7.sqlite');
});

it('leaves an in-memory sqlite database untouched', function () {
    $config = [
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config)->resolve(7)->get('DB_DATABASE'))->toBe(':memory:');
});

it('re-derives a sqlite path idempotently using the recorded current number', function () {
    $config = [
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => 'database/database_7.sqlite']]],
        'isolate.resources' => [
            ['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'normalize' => 'database_identifier', 'active_when' => 'always'],
        ],
    ];

    expect(makeResolver($config, ['currentNumber' => 7])->resolve(7)->get('DB_DATABASE'))
        ->toBe('database/database_7.sqlite');
});

it('resolves a config class-string derived resolver', function () {
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'derived', 'env' => 'CUSTOM', 'resolver' => StaticDerivedResolver::class, 'active_when' => 'always'],
    ]]);

    expect($resolver->resolve(9)->get('CUSTOM'))->toBe('resolved-9');
});

it('applies runtime closure derived resolvers registered on the manager', function () {
    $resolver = makeResolver(['isolate.resources' => []], [
        'manager' => fn (Isolate $manager) => $manager->derive('CUSTOM', fn (array $env, int $n): string => 'x'.$n),
    ]);

    expect($resolver->resolve(5)->get('CUSTOM'))->toBe('x5');
});

it('lets the manager override a resource definition', function () {
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'active_when' => 'always'],
    ]], [
        'manager' => fn (Isolate $manager) => $manager->resource('SERVER_PORT', ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 9000, 'active_when' => 'always']),
    ]);

    expect($resolver->resolve(0)->get('SERVER_PORT'))->toBe('9000');
});

it('exposes port bases that the band validator can reject for duplicates', function () {
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'port', 'env' => 'A_PORT', 'base' => 8000, 'active_when' => 'always'],
        ['type' => 'port', 'env' => 'B_PORT', 'base' => 8000, 'active_when' => 'always'],
    ]]);

    expect($resolver->portBases())->toBe([8000, 8000]);

    $validator = new BandValidator(100, 50);

    expect(fn () => $validator->validate($resolver->portBases()))
        ->toThrow(InvalidConfigurationException::class);
});
