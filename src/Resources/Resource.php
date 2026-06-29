<?php

namespace Ldiebold\Isolate\Resources;

use Ldiebold\Isolate\Exceptions\InvalidConfigurationException;

/**
 * Base for the resource taxonomy. A resource is a pure declaration parsed from
 * config/isolate.php; the Resolver turns it into env values for an instance.
 */
abstract class Resource
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(public readonly array $definition) {}

    /**
     * Build the correct resource subclass from a config definition.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function make(array $definition): self
    {
        return match ($definition['type'] ?? 'port') {
            'port' => new PortResource($definition),
            'name' => new NameResource($definition),
            'derived' => new DerivedResource($definition),
            default => throw new InvalidConfigurationException(
                "Unknown isolate resource type [{$definition['type']}]."
            ),
        };
    }

    /**
     * The primary (first) env key for an env declaration, which may be a single
     * key or an ordered list of keys that share one resolved value.
     *
     * @param  string|array<int, string>  $env
     */
    public static function firstEnvKey(array|string $env): string
    {
        return is_array($env) ? (string) ($env[0] ?? '') : $env;
    }

    /**
     * Every env key this resource writes (a resource may write several).
     *
     * @return array<int, string>
     */
    public function envKeys(): array
    {
        $env = $this->definition['env'] ?? [];

        return is_array($env) ? array_values($env) : [(string) $env];
    }

    public function primaryEnvKey(): string
    {
        return static::firstEnvKey($this->definition['env'] ?? []);
    }

    public function activeWhen(): mixed
    {
        return $this->definition['active_when'] ?? 'always';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->definition[$key] ?? $default;
    }
}
