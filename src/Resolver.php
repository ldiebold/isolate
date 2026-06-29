<?php

namespace Ldiebold\Isolate;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Ldiebold\Isolate\Contracts\DerivedResolver;
use Ldiebold\Isolate\Resources\DerivedResource;
use Ldiebold\Isolate\Resources\NameResource;
use Ldiebold\Isolate\Resources\PortResource;
use Ldiebold\Isolate\Resources\Resource;
use Ldiebold\Isolate\Support\ConfigPath;
use Ldiebold\Isolate\Support\DatabaseNameNormalizer;
use Ldiebold\Isolate\Support\NameDeriver;
use Ldiebold\Isolate\Support\ResourceActivator;
use Ldiebold\Isolate\Support\TemplateResolver;

/**
 * Resolves a single instance number into an IsolationPlan.
 */
class Resolver
{
    /**
     * @var array<int, resource>|null
     */
    protected ?array $resources = null;

    /**
     * @param  array<string, string>  $baseline
     */
    public function __construct(
        protected Container $container,
        protected Repository $config,
        protected ResourceActivator $activator,
        protected NameDeriver $nameDeriver,
        protected TemplateResolver $templateResolver,
        protected DatabaseNameNormalizer $normalizer,
        protected Isolate $manager,
        protected ?int $currentNumber = null,
        protected array $baseline = [],
    ) {}

    public function resolve(int $n): IsolationPlan
    {
        $env = [];
        $sideEffects = [];
        $ports = [];

        foreach ($this->ports() as $port) {
            $value = $port->portFor($n);

            foreach ($port->envKeys() as $key) {
                $env[$key] = (string) $value;
                $ports[$key] = $value;
            }
        }

        foreach ($this->names() as $name) {
            [$value, $sideEffect] = $this->resolveName($name, $n);
            $env[$name->primaryEnvKey()] = $value;

            if ($sideEffect !== null) {
                $sideEffects[] = $sideEffect;
            }
        }

        foreach ($this->derived() as $derived) {
            $env[$derived->primaryEnvKey()] = $this->resolveDerived($derived, $n, $env);
        }

        foreach ($this->manager->derivedResolvers() as $key => $resolver) {
            $env[$key] = $this->callDerivedResolver($resolver, $env, $n);
        }

        return new IsolationPlan($n, $env, $sideEffects, $ports);
    }

    /**
     * The numeric bases of every active port resource, for band validation.
     *
     * @return array<int, int>
     */
    public function portBases(): array
    {
        return collect($this->ports())
            ->map(static fn (PortResource $port): int => $port->base())
            ->all();
    }

    /**
     * The resolved port value of each port resource (keyed by its primary env
     * key) for instance n. A resource that writes several env keys for one port
     * is counted once, so the duplicate guard only flags distinct resources that
     * collide.
     *
     * @return array<string, int>
     */
    public function portValues(int $n): array
    {
        $values = [];

        foreach ($this->ports() as $port) {
            $values[$port->primaryEnvKey()] = $port->portFor($n);
        }

        return $values;
    }

    /**
     * Browser-facing port values for instance n, for restricted-port checks.
     *
     * @return array<int, int>
     */
    public function browserFacingPorts(int $n): array
    {
        $ports = [];

        foreach ($this->ports() as $port) {
            if ($port->browserFacing()) {
                $ports[] = $port->portFor($n);
            }
        }

        return $ports;
    }

    /**
     * @return array<int, resource>
     */
    public function resources(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }

        $definitions = [];

        foreach ((array) $this->config->get('isolate.resources', []) as $definition) {
            $definitions[Resource::firstEnvKey($definition['env'] ?? '')] = $definition;
        }

        foreach ($this->manager->resourceOverrides() as $key => $override) {
            $definitions[$key] = array_merge($definitions[$key] ?? [], $override);
        }

        return $this->resources = collect($definitions)
            ->map(static fn (array $definition): Resource => Resource::make($definition))
            ->filter(fn (Resource $resource): bool => $this->activator->isActive($resource->activeWhen()))
            ->values()
            ->all();
    }

    /**
     * @return array<int, PortResource>
     */
    protected function ports(): array
    {
        return collect($this->resources())->whereInstanceOf(PortResource::class)->values()->all();
    }

    /**
     * @return array<int, NameResource>
     */
    protected function names(): array
    {
        return collect($this->resources())->whereInstanceOf(NameResource::class)->values()->all();
    }

    /**
     * @return array<int, DerivedResource>
     */
    protected function derived(): array
    {
        return collect($this->resources())->whereInstanceOf(DerivedResource::class)->values()->all();
    }

    /**
     * @return array{0: string, 1: SideEffect|null}
     */
    protected function resolveName(NameResource $resource, int $n): array
    {
        if ($resource->normalizer() === 'database_identifier') {
            $value = $this->deriveDatabaseValue($this->nameBase($resource), $n);
        } else {
            $base = $this->nameDeriver->stripSuffix($this->nameBase($resource), $this->currentNumber);
            $value = $this->nameDeriver->derive($base, $n);
        }

        $sideEffect = null;

        if ($resource->sideEffect() === SideEffectKind::CreateDatabase->value) {
            $sideEffect = SideEffect::createDatabase([
                'connection' => (string) $this->config->get('database.default'),
                'database' => $value,
                'env' => $resource->primaryEnvKey(),
            ]);
        }

        return [$value, $sideEffect];
    }

    /**
     * Derive a per-instance database value. SQLite values are file paths, so the
     * suffix is inserted before the extension and the path is left intact; every
     * other driver uses a normalized identifier.
     */
    protected function deriveDatabaseValue(string $base, int $n): string
    {
        if ($this->connectionDriver() === 'sqlite') {
            return $this->deriveSqlitePath($base, $n);
        }

        $clean = $this->nameDeriver->stripSuffix($base, $this->currentNumber);

        return $this->normalizer->normalize($this->nameDeriver->derive($clean, $n));
    }

    protected function deriveSqlitePath(string $base, int $n): string
    {
        if ($base === '' || $base === ':memory:') {
            return $base;
        }

        $directory = str_contains($base, '/') ? Str::beforeLast($base, '/').'/' : '';
        $file = Str::afterLast($base, '/');

        $stem = str_contains($file, '.') ? Str::beforeLast($file, '.') : $file;
        $extension = str_contains($file, '.') ? '.'.Str::afterLast($file, '.') : '';

        $stem = $this->nameDeriver->stripSuffix($stem, $this->currentNumber);
        $stem = $this->nameDeriver->derive($stem, $n);

        return $directory.$stem.$extension;
    }

    protected function connectionDriver(): string
    {
        return (string) $this->config->get(
            ConfigPath::resolve('database.connections.{default}.driver', $this->config)
        );
    }

    protected function nameBase(NameResource $resource): string
    {
        if (($literal = $resource->literalBase()) !== null) {
            return $literal;
        }

        if (($path = $resource->configPath()) !== null) {
            $value = (string) $this->config->get(ConfigPath::resolve($path, $this->config));

            if ($value !== '') {
                return $value;
            }
        }

        return $this->nameDeriver->name();
    }

    /**
     * @param  array<string, string>  $env
     */
    protected function resolveDerived(DerivedResource $resource, int $n, array $env): string
    {
        if (($source = $resource->rewritePortOf()) !== null) {
            $portKey = (string) $resource->portFrom();
            $port = (int) ($env[$portKey] ?? 0);
            $current = $this->baseline[$source] ?? 'http://localhost';

            return $this->templateResolver->rewritePort($current, $port);
        }

        if (($template = $resource->template()) !== null) {
            return $this->templateResolver->substitute($template, $env);
        }

        if (($class = $resource->resolverClass()) !== null) {
            return $this->callDerivedResolver($class, $env, $n);
        }

        return $this->baseline[$resource->primaryEnvKey()] ?? '';
    }

    /**
     * @param  Closure(array<string, string>, int): string|class-string  $resolver
     * @param  array<string, string>  $env
     */
    protected function callDerivedResolver(Closure|string $resolver, array $env, int $n): string
    {
        $instance = is_string($resolver) ? $this->container->make($resolver) : $resolver;

        if ($instance instanceof DerivedResolver) {
            return $instance->resolve($env, $n);
        }

        return (string) $instance($env, $n);
    }
}
