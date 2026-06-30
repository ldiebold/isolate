<?php

namespace Ldiebold\Isolate\Database;

use Illuminate\Contracts\Config\Repository;
use Ldiebold\Isolate\Contracts\DatabaseDestroyer;

/**
 * Picks the DatabaseDestroyer for a connection's driver and hands it a cloned
 * "maintenance" connection so the target is never connected to while it is being
 * dropped. Mirrors DatabaseCreatorManager; each destroyer probes existence itself
 * (via the shared DatabaseInspector) so it can report dropped vs missing.
 */
class DatabaseDestroyerManager
{
    protected MaintenanceConnectionFactory $maintenance;

    /**
     * @param  array<int, DatabaseDestroyer>  $destroyers
     */
    public function __construct(
        protected Repository $config,
        protected array $destroyers,
    ) {
        $this->maintenance = new MaintenanceConnectionFactory($config);
    }

    public function destroy(string $connectionName, string $database): DropResult
    {
        $driver = (string) $this->config->get("database.connections.{$connectionName}.driver", '');

        $destroyer = $this->destroyerFor($driver);

        if ($destroyer === null) {
            return DropResult::skipped(
                $database,
                "No database destroyer supports the [{$driver}] driver; skipped."
            );
        }

        return $destroyer->destroy($this->maintenance->for($connectionName), $database);
    }

    protected function destroyerFor(string $driver): ?DatabaseDestroyer
    {
        foreach ($this->destroyers as $destroyer) {
            if ($destroyer->supports($driver)) {
                return $destroyer;
            }
        }

        return null;
    }
}
