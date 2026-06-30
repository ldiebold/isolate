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
    protected MaintenanceConnectionFactory $maintenance;

    /**
     * @param  array<int, DatabaseCreator>  $creators
     */
    public function __construct(
        protected Repository $config,
        protected array $creators,
    ) {
        $this->maintenance = new MaintenanceConnectionFactory($config);
    }

    public function create(string $connectionName, string $database): CreateResult
    {
        $driver = (string) $this->config->get("database.connections.{$connectionName}.driver", '');

        $creator = $this->creatorFor($driver);

        if ($creator === null) {
            return CreateResult::skipped(
                $database,
                "No database creator supports the [{$driver}] driver; skipped."
            );
        }

        return $creator->ensureExists($this->maintenance->for($connectionName), $database);
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
}
