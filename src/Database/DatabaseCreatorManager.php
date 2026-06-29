<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Contracts\Config\Repository;
use Ldiebold\Isolate\Contracts\DatabaseCreator;

/**
 * Picks the DatabaseCreator for a connection's driver and hands it a cloned
 * "maintenance" connection so the not-yet-existing target is never connected to.
 */
class DatabaseCreatorManager
{
    /**
     * @param  array<int, DatabaseCreator>  $creators
     */
    public function __construct(
        protected Repository $config,
        protected array $creators,
    ) {}

    public function create(string $connectionName, string $database): CreateResult
    {
        $connectionConfig = (array) $this->config->get("database.connections.{$connectionName}", []);
        $driver = (string) ($connectionConfig['driver'] ?? '');

        $creator = $this->creatorFor($driver);

        if ($creator === null) {
            return CreateResult::skipped(
                $database,
                "No database creator supports the [{$driver}] driver; skipped."
            );
        }

        $maintenance = $this->maintenanceConnection($connectionName, $connectionConfig, $driver);

        return $creator->ensureExists($maintenance, $database);
    }

    protected function creatorFor(string $driver): ?DatabaseCreator
    {
        foreach ($this->creators as $creator) {
            if ($creator->supports($driver)) {
                return $creator;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function maintenanceConnection(string $name, array $config, string $driver): ConnectionConfig
    {
        $maintenanceName = '__isolate_maintenance_'.$name;

        $config['database'] = match ($driver) {
            'pgsql' => 'postgres',
            'mysql', 'mariadb' => null,
            default => $config['database'] ?? null,
        };

        $this->config->set("database.connections.{$maintenanceName}", $config);

        return new ConnectionConfig($maintenanceName, $driver, $config);
    }
}
