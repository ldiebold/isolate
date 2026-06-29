<?php

it('treats always and null as active', function () {
    expect(activator()->isActive('always'))->toBeTrue()
        ->and(activator()->isActive(null))->toBeTrue();
});

it('activates on a present env var', function () {
    $reader = fn (string $key): mixed => $key === 'VITE_PORT' ? '5173' : null;

    expect(activator(env: $reader)->isActive(['env' => 'VITE_PORT']))->toBeTrue()
        ->and(activator(env: $reader)->isActive(['env' => 'MISSING']))->toBeFalse();
});

it('activates on a filled config path', function () {
    $config = ['mail' => ['driver' => 'smtp']];

    expect(activator($config)->isActive(['config' => 'mail.driver']))->toBeTrue()
        ->and(activator($config)->isActive(['config' => 'mail.host']))->toBeFalse();
});

it('resolves the {default} token against the default connection', function () {
    $config = [
        'database' => [
            'default' => 'pgsql',
            'connections' => ['pgsql' => ['database' => 'forge']],
        ],
    ];

    expect(activator($config)->isActive(['config' => 'database.connections.{default}.database']))->toBeTrue();
});

it('activates on an installed composer package', function () {
    expect(activator(packages: ['laravel/reverb'])->isActive(['package' => 'laravel/reverb']))->toBeTrue()
        ->and(activator()->isActive(['package' => 'laravel/reverb']))->toBeFalse();
});

it('supports any and all composite predicates', function () {
    $config = ['a' => 1];

    expect(activator($config)->isActive(['any' => [['config' => 'missing'], ['config' => 'a']]]))->toBeTrue()
        ->and(activator($config)->isActive(['all' => [['config' => 'a'], 'always']]))->toBeTrue()
        ->and(activator($config)->isActive(['all' => [['config' => 'a'], ['config' => 'missing']]]))->toBeFalse()
        ->and(activator($config)->isActive(['all' => []]))->toBeFalse();
});

it('treats an unknown predicate as inactive', function () {
    expect(activator()->isActive(['unknown' => 'x']))->toBeFalse();
});
