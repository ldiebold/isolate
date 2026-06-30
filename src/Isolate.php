<?php

namespace Ldiebold\Isolate;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Ldiebold\Isolate\Appliers\DatabaseCreatorApplier;
use Ldiebold\Isolate\Appliers\DotenvApplier;
use Ldiebold\Isolate\Claims\SelfClaimProvider;
use Ldiebold\Isolate\CollisionDetectors\DatabaseCollisionDetector;
use Ldiebold\Isolate\CollisionDetectors\PortCollisionDetector;
use Ldiebold\Isolate\CollisionDetectors\RedisPrefixCollisionDetector;
use Ldiebold\Isolate\Contracts\Applier;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\Contracts\EnvWriter;
use Ldiebold\Isolate\Contracts\KeyspaceFlusher;
use Ldiebold\Isolate\Contracts\Lock;
use Ldiebold\Isolate\Contracts\PackageDetector;
use Ldiebold\Isolate\Contracts\PortChecker;
use Ldiebold\Isolate\Database\CreateResult;
use Ldiebold\Isolate\Database\DatabaseCreatorManager;
use Ldiebold\Isolate\Database\DatabaseDestroyerManager;
use Ldiebold\Isolate\Database\DatabaseInspector;
use Ldiebold\Isolate\Database\DropResult;
use Ldiebold\Isolate\Database\MySqlDatabaseCreator;
use Ldiebold\Isolate\Database\MySqlDatabaseDestroyer;
use Ldiebold\Isolate\Database\PostgresDatabaseCreator;
use Ldiebold\Isolate\Database\PostgresDatabaseDestroyer;
use Ldiebold\Isolate\Database\SqliteDatabaseCreator;
use Ldiebold\Isolate\Database\SqliteDatabaseDestroyer;
use Ldiebold\Isolate\Events\DatabaseCreated;
use Ldiebold\Isolate\Events\DatabaseDropped;
use Ldiebold\Isolate\Events\IsolationApplied;
use Ldiebold\Isolate\Events\PrefixFlushed;
use Ldiebold\Isolate\Exceptions\ConflictException;
use Ldiebold\Isolate\Exceptions\InvalidConfigurationException;
use Ldiebold\Isolate\Exceptions\NoAvailableNumberException;
use Ldiebold\Isolate\Locking\FileLock;
use Ldiebold\Isolate\Redis\FlushResult;
use Ldiebold\Isolate\Redis\KeyspaceFlusherManager;
use Ldiebold\Isolate\Redis\RawRedisConnectionFactory;
use Ldiebold\Isolate\Redis\RedisKeyspaceFlusher;
use Ldiebold\Isolate\Resources\Resource;
use Ldiebold\Isolate\Support\BandValidator;
use Ldiebold\Isolate\Support\DatabaseNameNormalizer;
use Ldiebold\Isolate\Support\NameDeriver;
use Ldiebold\Isolate\Support\ResourceActivator;
use Ldiebold\Isolate\Support\RestrictedPorts;
use Ldiebold\Isolate\Support\SqlitePath;
use Ldiebold\Isolate\Support\TemplateResolver;
use Ldiebold\Isolate\Teardown\TeardownPlanner;

/**
 * The core service behind the Isolate facade. Configuration stays pure data in
 * config/isolate.php; anything involving closures or runtime objects is
 * registered here, typically from a service provider's boot method. It also
 * assembles the runtime collaborators and runs an isolation from end to end.
 */
class Isolate
{
    protected const DEFAULT_BAND_SIZE = 100;

    protected const DEFAULT_MAX_INSTANCES = 50;

    /**
     * @var array<int, Applier|class-string<Applier>>
     */
    protected array $appliers = [];

    /**
     * @var array<int, CollisionDetector|class-string<CollisionDetector>>
     */
    protected array $collisionDetectors = [];

    /**
     * @var array<string, Closure|class-string>
     */
    protected array $derivedResolvers = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $resourceOverrides = [];

    /**
     * @var array<int, Closure|class-string>
     */
    protected array $afterDatabaseCreated = [];

    /**
     * @var array<int, Closure|class-string>
     */
    protected array $afterDatabaseDropped = [];

    /**
     * @var array<int, Closure|class-string>
     */
    protected array $afterPrefixFlushed = [];

    /**
     * @var array<int, Closure|class-string>
     */
    protected array $afterApply = [];

    /**
     * @var array<int, Closure|class-string>
     */
    protected array $restartCallbacks = [];

    public function __construct(protected Application $app) {}

    /**
     * Register (or override) a port resource for the given env key(s).
     *
     * @param  string|array<int, string>  $env
     * @param  array<string, mixed>  $options
     */
    public function port(array|string $env, int $base, array $options = []): static
    {
        return $this->resource(Resource::firstEnvKey($env), array_merge([
            'type' => 'port',
            'env' => $env,
            'base' => $base,
            'active_when' => 'always',
        ], $options));
    }

    /**
     * Register (or override) a name resource for the given env key(s).
     *
     * @param  string|array<int, string>  $env
     * @param  array<string, mixed>  $options
     */
    public function name(array|string $env, array $options = []): static
    {
        return $this->resource(Resource::firstEnvKey($env), array_merge([
            'type' => 'name',
            'env' => $env,
            'base_from' => 'isolate.name',
            'active_when' => 'always',
        ], $options));
    }

    /**
     * Compute a derived env value at runtime from the resolved env map.
     *
     * @param  Closure(array<string, string>, int): string|class-string  $resolver
     */
    public function derive(string $env, Closure|string $resolver): static
    {
        $this->derivedResolvers[$env] = $resolver;

        return $this;
    }

    /**
     * Run a callback after the plan has been applied.
     */
    public function after(Closure|string $callback): static
    {
        $this->afterApply[] = $callback;

        return $this;
    }

    /**
     * Run a callback after a database has been created.
     */
    public function afterDatabaseCreated(Closure|string $callback): static
    {
        $this->afterDatabaseCreated[] = $callback;

        return $this;
    }

    /**
     * Run a callback after a database has been dropped (isolate:teardown).
     */
    public function afterDatabaseDropped(Closure|string $callback): static
    {
        $this->afterDatabaseDropped[] = $callback;

        return $this;
    }

    /**
     * Run a callback after a Redis keyspace has been flushed (isolate:teardown).
     */
    public function afterPrefixFlushed(Closure|string $callback): static
    {
        $this->afterPrefixFlushed[] = $callback;

        return $this;
    }

    /**
     * Register a restart hook fired by "isolate --restart".
     */
    public function restartUsing(Closure|string $callback): static
    {
        $this->restartCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a custom applier (instance or class-string), run in order.
     *
     * @param  Applier|class-string<Applier>  $applier
     */
    public function applier(Applier|string $applier): static
    {
        $this->appliers[] = $applier;

        return $this;
    }

    /**
     * Register a custom collision detector (instance or class-string).
     *
     * @param  CollisionDetector|class-string<CollisionDetector>  $detector
     */
    public function collisionDetector(CollisionDetector|string $detector): static
    {
        $this->collisionDetectors[] = $detector;

        return $this;
    }

    /**
     * Override or add a raw resource definition keyed by its primary env key.
     *
     * @param  array<string, mixed>  $definition
     */
    public function resource(string $env, array $definition): static
    {
        $this->resourceOverrides[$env] = $definition;

        return $this;
    }

    /**
     * Select a number, write the disjoint env, and apply every side effect,
     * serialized under the app-local lock. Returns the run's outcome.
     *
     * @throws ConflictException
     * @throws NoAvailableNumberException
     * @throws InvalidConfigurationException
     */
    public function run(IsolationRequest $request): IsolationResult
    {
        $currentNumber = $this->currentNumber();
        $resolver = $this->resolver($currentNumber);

        $this->bandValidator()->validate($resolver->portBases());

        $selector = $this->numberSelector($resolver, $currentNumber);
        $warnings = [];

        [$number, $plan, $apply] = $this->lock(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        })->get(function () use ($request, $resolver, $selector, &$warnings): array {
            return $this->select($request, $resolver, $selector, $warnings);
        });

        $this->fireAfterApply($plan, $apply);

        return new IsolationResult($number, $plan, $apply, $warnings);
    }

    /**
     * Point the default connection at the plan's database (resolving SQLite
     * paths) and purge it, so a follow-up migrate/seed targets the new database.
     */
    public function pointConnectionAtPlan(IsolationPlan $plan): void
    {
        $database = $plan->get('DB_DATABASE');

        if ($database === null) {
            return;
        }

        $default = (string) $this->config()->get('database.default');
        $driver = (string) $this->config()->get("database.connections.{$default}.driver");

        $value = $driver === 'sqlite'
            ? SqlitePath::absolute($database, $this->databasePath())
            : $database;

        $this->config()->set("database.connections.{$default}.database", $value);

        $this->app->make('db')->purge($default);
    }

    public function currentNumber(): ?int
    {
        // Read straight from the environment, not config, so a stale cached
        // config can never mask the number this checkout last recorded.
        $value = Env::get('ISOLATE_NUMBER');

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    public function maxInstances(): int
    {
        return (int) $this->config()->get('isolate.max_instances', self::DEFAULT_MAX_INSTANCES);
    }

    /**
     * Build the resolver for this checkout's recorded number.
     */
    public function resolver(?int $currentNumber = null): Resolver
    {
        return new Resolver(
            $this->app,
            $this->config(),
            $this->activator(),
            $this->nameDeriver(),
            new TemplateResolver,
            new DatabaseNameNormalizer,
            $this,
            $currentNumber,
            $this->baseline(),
        );
    }

    public function numberSelector(Resolver $resolver, ?int $currentNumber): NumberSelector
    {
        return new NumberSelector(
            $resolver,
            $this->restrictedPorts(),
            new SelfClaimProvider($currentNumber),
            $this->detectors(),
            $this->maxInstances(),
            $this->throwOnConflict(),
        );
    }

    public function bandValidator(): BandValidator
    {
        return new BandValidator($this->bandSize(), $this->maxInstances());
    }

    /**
     * @return array<int, Applier>
     */
    public function registeredAppliers(): array
    {
        return collect($this->appliers)
            ->map(fn (Applier|string $applier): Applier => $this->resolve($applier))
            ->all();
    }

    /**
     * @return array<int, CollisionDetector>
     */
    public function registeredCollisionDetectors(): array
    {
        return collect($this->collisionDetectors)
            ->map(fn (CollisionDetector|string $detector): CollisionDetector => $this->resolve($detector))
            ->all();
    }

    /**
     * @return array<string, Closure|class-string>
     */
    public function derivedResolvers(): array
    {
        return $this->derivedResolvers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function resourceOverrides(): array
    {
        return $this->resourceOverrides;
    }

    public function hasRestartCallbacks(): bool
    {
        return $this->restartCallbacks !== [];
    }

    public function fireAfterDatabaseCreated(CreateResult $result, IsolationPlan $plan): void
    {
        $this->fire($this->afterDatabaseCreated, [$result, $plan]);

        $this->dispatch(new DatabaseCreated($result, $plan));
    }

    public function fireAfterDatabaseDropped(DropResult $result, IsolationPlan $plan): void
    {
        $this->fire($this->afterDatabaseDropped, [$result, $plan]);

        $this->dispatch(new DatabaseDropped($result, $plan));
    }

    public function fireAfterPrefixFlushed(FlushResult $result, IsolationPlan $plan): void
    {
        $this->fire($this->afterPrefixFlushed, [$result, $plan]);

        $this->dispatch(new PrefixFlushed($result, $plan));
    }

    public function fireAfterApply(IsolationPlan $plan, ApplyResult $result): void
    {
        $this->fire($this->afterApply, [$plan, $result]);

        $this->dispatch(new IsolationApplied($plan, $result));
    }

    public function fireRestart(IsolationPlan $plan): void
    {
        $this->fire($this->restartCallbacks, [$plan]);
    }

    /**
     * The manager that drops per-instance databases for isolate:teardown.
     */
    public function databaseDestroyerManager(): DatabaseDestroyerManager
    {
        $inspector = $this->databaseInspector();

        return new DatabaseDestroyerManager($this->config(), [
            new SqliteDatabaseDestroyer($this->files(), $this->databasePath()),
            new MySqlDatabaseDestroyer($this->app->make('db'), $inspector),
            new PostgresDatabaseDestroyer($this->app->make('db'), $inspector),
        ]);
    }

    /**
     * The manager that flushes per-instance Redis keyspaces for isolate:teardown.
     */
    public function keyspaceFlusherManager(): KeyspaceFlusherManager
    {
        return new KeyspaceFlusherManager($this->config(), $this->keyspaceFlusher());
    }

    /**
     * Shared "does this database exist?" probe across sqlite/mysql/pgsql.
     */
    public function databaseInspector(): DatabaseInspector
    {
        return new DatabaseInspector(
            $this->config(),
            $this->app->make('db'),
            $this->files(),
            $this->databasePath(),
        );
    }

    /**
     * Build the teardown planner for this checkout's recorded number.
     */
    public function teardownPlanner(): TeardownPlanner
    {
        $currentNumber = $this->currentNumber();

        return new TeardownPlanner(
            $this->resolver($currentNumber),
            $this->databaseInspector(),
            $currentNumber,
            $this->maxInstances(),
        );
    }

    /**
     * The per-connection Redis flusher. Resolved from the container when bound
     * (so tests can swap in a fake) and otherwise built with the configured
     * vanilla base prefixes as hard guards.
     */
    protected function keyspaceFlusher(): KeyspaceFlusher
    {
        if ($this->app->bound(KeyspaceFlusher::class)) {
            return $this->app->make(KeyspaceFlusher::class);
        }

        return new RedisKeyspaceFlusher(
            new RawRedisConnectionFactory($this->app),
            $this->protectedRedisPrefixes(),
        );
    }

    /**
     * The vanilla (instance 0) prefix for each keyspace, which must never be
     * flushed (it would match every instance's keys). Derived through the resolver
     * so the active instance's own suffix is stripped: reading the live config
     * would, when the active instance is being torn down, yield that instance's
     * padded prefix and wrongly protect it from being flushed.
     *
     * @return array<int, string>
     */
    protected function protectedRedisPrefixes(): array
    {
        $resolver = $this->resolver($this->currentNumber());
        $vanilla = $resolver->resolve(0);

        $prefixes = [];

        foreach ($resolver->redisKeyspaceEnvKeys() as $key) {
            $value = $vanilla->get($key);

            if (is_string($value) && $value !== '') {
                $prefixes[] = $value;
            }
        }

        return $prefixes;
    }

    /**
     * Choose a number and apply the plan, returning the number, the applied
     * plan and the accumulated result.
     *
     * @param  array<int, string>  $warnings
     * @return array{0: int, 1: IsolationPlan, 2: ApplyResult}
     */
    protected function select(IsolationRequest $request, Resolver $resolver, NumberSelector $selector, array &$warnings): array
    {
        $number = $this->chooseNumber($request, $resolver, $selector, $warnings);
        $plan = $this->finalPlan($resolver, $number);

        $this->bandValidator()->assertUniquePorts($resolver->portValues($number));

        $apply = new ApplyResult;

        foreach ($this->appliers() as $applier) {
            $apply->merge($applier->apply($plan));
        }

        $this->clearConfigCache();

        return [$number, $plan, $apply];
    }

    /**
     * @param  array<int, string>  $warnings
     */
    protected function chooseNumber(IsolationRequest $request, Resolver $resolver, NumberSelector $selector, array &$warnings): int
    {
        if ($request->number !== null) {
            return $this->reportExplicit($resolver, $selector, $request->number, $warnings);
        }

        $selection = $selector->next();

        foreach ($selection->conflicts as $conflict) {
            $warnings[] = $conflict->message;
        }

        return $selection->number;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    protected function reportExplicit(Resolver $resolver, NumberSelector $selector, int $number, array &$warnings): int
    {
        $conflicts = $selector->conflictsFor($resolver->resolve($number));

        if ($conflicts !== []) {
            if ($this->throwOnConflict()) {
                throw new ConflictException($conflicts);
            }

            foreach ($conflicts as $conflict) {
                $warnings[] = $conflict->message;
            }
        }

        return $number;
    }

    protected function finalPlan(Resolver $resolver, int $number): IsolationPlan
    {
        $plan = $resolver->resolve($number);

        return new IsolationPlan(
            $number,
            array_merge($plan->envMap, ['ISOLATE_NUMBER' => (string) $number]),
            $plan->sideEffects,
            $plan->ports,
        );
    }

    /**
     * The default appliers plus any registered on the facade, run in order.
     *
     * @return array<int, Applier>
     */
    protected function appliers(): array
    {
        return [
            $this->dotenvApplier(),
            $this->databaseCreatorApplier(),
            ...$this->registeredAppliers(),
        ];
    }

    /**
     * The default detectors plus any registered on the facade.
     *
     * @return array<int, CollisionDetector>
     */
    protected function detectors(): array
    {
        return [
            new PortCollisionDetector($this->app->make(PortChecker::class)),
            new DatabaseCollisionDetector(
                $this->config(),
                $this->app->make('db'),
                $this->files(),
                $this->databasePath(),
            ),
            new RedisPrefixCollisionDetector($this->app),
            ...$this->registeredCollisionDetectors(),
        ];
    }

    protected function dotenvApplier(): DotenvApplier
    {
        return new DotenvApplier(
            $this->app->make(EnvWriter::class),
            $this->files(),
            $this->envPath(),
            $this->exampleEnvPath(),
        );
    }

    protected function databaseCreatorApplier(): DatabaseCreatorApplier
    {
        return new DatabaseCreatorApplier($this->databaseCreatorManager(), $this);
    }

    protected function databaseCreatorManager(): DatabaseCreatorManager
    {
        return new DatabaseCreatorManager($this->config(), [
            new SqliteDatabaseCreator($this->files(), $this->databasePath()),
            new MySqlDatabaseCreator($this->app->make('db')),
            new PostgresDatabaseCreator($this->app->make('db')),
        ]);
    }

    /**
     * @param  (Closure(string): void)|null  $onWarning
     */
    protected function lock(?Closure $onWarning = null): Lock
    {
        return new FileLock($this->lockPath(), $this->files(), $onWarning);
    }

    protected function restrictedPorts(): RestrictedPorts
    {
        /** @var array<int, int> $ports */
        $ports = (array) $this->config()->get('isolate.restricted_ports', []);

        return new RestrictedPorts($ports);
    }

    protected function nameDeriver(): NameDeriver
    {
        $name = $this->config()->get('isolate.name');
        $appName = $this->config()->get('app.name');

        return new NameDeriver(
            is_string($name) ? $name : null,
            is_string($appName) ? $appName : null,
            (string) $this->config()->get('isolate.suffix_format', '_{n}'),
        );
    }

    protected function activator(): ResourceActivator
    {
        return new ResourceActivator($this->config(), $this->app->make(PackageDetector::class));
    }

    /**
     * @return array<string, string>
     */
    protected function baseline(): array
    {
        return [
            'APP_URL' => (string) ($this->config()->get('app.url') ?: 'http://localhost'),
        ];
    }

    protected function throwOnConflict(): bool
    {
        return (bool) $this->config()->get('isolate.throw_on_conflict', false);
    }

    protected function bandSize(): int
    {
        return (int) $this->config()->get('isolate.band_size', self::DEFAULT_BAND_SIZE);
    }

    protected function envPath(): string
    {
        $configured = $this->config()->get('isolate.env_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : $this->app->basePath('.env');
    }

    protected function exampleEnvPath(): string
    {
        $configured = $this->config()->get('isolate.env_example_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : $this->app->basePath('.env.example');
    }

    protected function lockPath(): string
    {
        $configured = $this->config()->get('isolate.lock_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : $this->app->storagePath('framework/cache/isolate.lock');
    }

    protected function clearConfigCache(): void
    {
        $path = $this->app->getCachedConfigPath();

        if (is_file($path)) {
            $this->files()->delete($path);
        }
    }

    protected function databasePath(): string
    {
        return $this->app->databasePath();
    }

    protected function config(): Repository
    {
        return $this->app->make('config');
    }

    protected function files(): Filesystem
    {
        return $this->app->make(Filesystem::class);
    }

    protected function dispatch(object $event): void
    {
        $this->app->make('events')->dispatch($event);
    }

    /**
     * @param  array<int, Closure|class-string>  $callbacks
     * @param  array<int, mixed>  $arguments
     */
    protected function fire(array $callbacks, array $arguments): void
    {
        foreach ($callbacks as $callback) {
            if (is_string($callback)) {
                $callback = $this->app->make($callback);
            }

            $callback(...$arguments);
        }
    }

    /**
     * @template TContract of object
     *
     * @param  TContract|class-string<TContract>  $value
     * @return TContract
     */
    protected function resolve(object|string $value): object
    {
        if (is_string($value)) {
            /** @var TContract */
            return $this->app->make($value);
        }

        return $value;
    }
}
