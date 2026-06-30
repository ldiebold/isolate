<?php

use Illuminate\Config\Repository;
use Ldiebold\Isolate\Database\MaintenanceConnectionFactory;

it('swaps pgsql to the postgres maintenance database', function () {
    $config = new Repository(['database' => ['connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'app']]]]);

    $maintenance = (new MaintenanceConnectionFactory($config))->for('pgsql');

    expect($maintenance->name)->toBe('__isolate_maintenance_pgsql')
        ->and($maintenance->driver)->toBe('pgsql')
        ->and($maintenance->get('database'))->toBe('postgres')
        ->and($config->get('database.connections.__isolate_maintenance_pgsql.database'))->toBe('postgres');
});

it('swaps mysql and mariadb to a null maintenance database', function () {
    $config = new Repository(['database' => ['connections' => [
        'mysql' => ['driver' => 'mysql', 'database' => 'app'],
        'mariadb' => ['driver' => 'mariadb', 'database' => 'app'],
    ]]]);

    $factory = new MaintenanceConnectionFactory($config);

    expect($factory->for('mysql')->get('database'))->toBeNull()
        ->and($factory->for('mariadb')->get('database'))->toBeNull();
});

it('leaves the sqlite database path unchanged', function () {
    $config = new Repository(['database' => ['connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => '/tmp/db.sqlite']]]]);

    $maintenance = (new MaintenanceConnectionFactory($config))->for('sqlite');

    expect($maintenance->get('database'))->toBe('/tmp/db.sqlite')
        ->and($maintenance->driver)->toBe('sqlite');
});
