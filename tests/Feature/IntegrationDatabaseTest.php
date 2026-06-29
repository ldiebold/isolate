<?php

use Ldiebold\Isolate\Database\CreateOutcome;
use Ldiebold\Isolate\Database\DatabaseCreatorManager;
use Ldiebold\Isolate\Database\MySqlDatabaseCreator;
use Ldiebold\Isolate\Database\PostgresDatabaseCreator;

/*
 * These tests hit a real database server and are skipped unless INTEGRATION_DB
 * is enabled. Configure the server with the ISOLATE_PG_* / ISOLATE_MYSQL_* env
 * vars (host, port, username, password).
 */

$integrationDisabled = fn (): bool => ! filter_var(env('INTEGRATION_DB'), FILTER_VALIDATE_BOOL);

it('creates and re-detects a postgres database', function () {
    config()->set('database.connections.isolate_pg', [
        'driver' => 'pgsql',
        'host' => env('ISOLATE_PG_HOST', '127.0.0.1'),
        'port' => env('ISOLATE_PG_PORT', '5432'),
        'database' => 'postgres',
        'username' => env('ISOLATE_PG_USER', 'postgres'),
        'password' => env('ISOLATE_PG_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]);

    $manager = new DatabaseCreatorManager(app('config'), [new PostgresDatabaseCreator(app('db'))]);
    $database = 'isolate_it_'.uniqid();

    expect($manager->create('isolate_pg', $database)->outcome)->toBe(CreateOutcome::Created)
        ->and($manager->create('isolate_pg', $database)->outcome)->toBe(CreateOutcome::Existed);

    app('db')->connection('__isolate_maintenance_isolate_pg')
        ->statement('DROP DATABASE IF EXISTS "'.$database.'"');
})->skip($integrationDisabled, 'INTEGRATION_DB is disabled');

it('creates and re-detects a mysql database', function () {
    config()->set('database.connections.isolate_mysql', [
        'driver' => 'mysql',
        'host' => env('ISOLATE_MYSQL_HOST', '127.0.0.1'),
        'port' => env('ISOLATE_MYSQL_PORT', '3306'),
        'database' => null,
        'username' => env('ISOLATE_MYSQL_USER', 'root'),
        'password' => env('ISOLATE_MYSQL_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'prefix' => '',
    ]);

    $manager = new DatabaseCreatorManager(app('config'), [new MySqlDatabaseCreator(app('db'))]);
    $database = 'isolate_it_'.uniqid();

    expect($manager->create('isolate_mysql', $database)->outcome)->toBe(CreateOutcome::Created)
        ->and($manager->create('isolate_mysql', $database)->outcome)->toBe(CreateOutcome::Existed);

    app('db')->connection('__isolate_maintenance_isolate_mysql')
        ->statement('DROP DATABASE IF EXISTS `'.$database.'`');
})->skip($integrationDisabled, 'INTEGRATION_DB is disabled');
