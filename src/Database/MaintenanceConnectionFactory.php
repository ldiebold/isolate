<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Contracts\Config\Repository;

/**
 * Builds the cloned "maintenance" connection used to create or drop a
 * per-instance database without connecting to the (possibly missing) target.
 *
 * The target database is swapped for a server-level default so CREATE/DROP never
 * runs against the database itself: "postgres" for pgsql, null for mysql/mariadb,
 * and the unchanged path for sqlite. These rules are correctness-critical and must
 * be identical for the create and teardown paths, so they live here once.
 */
class MaintenanceConnectionFactory
{
    public function __construct(protected Repository $config) {}

    public function for(string $connectionName): ConnectionConfig
    {
        $config = (array) $this->config->get("database.connections.{$connectionName}", []);
        $driver = (string) ($config['driver'] ?? '');

        $maintenanceName = '__isolate_maintenance_'.$connectionName;

        $config['database'] = match ($driver) {
            'pgsql' => 'postgres',
            'mysql', 'mariadb' => null,
            default => $config['database'] ?? null,
        };

        $this->config->set("database.connections.{$maintenanceName}", $config);

        return new ConnectionConfig($maintenanceName, $driver, $config);
    }
}
