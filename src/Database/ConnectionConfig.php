<?php

namespace Ldiebold\Isolate\Database;

/**
 * The cloned "maintenance" connection a DatabaseCreator uses to create the
 * per-instance database without connecting to the (not-yet-existing) target.
 */
readonly class ConnectionConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $name,
        public string $driver,
        public array $config,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
