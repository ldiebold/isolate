# Isolate for Laravel

Run many checkouts of the same Laravel app side by side without them stepping on each other.

When you run the same app from several git worktrees or working copies at once, every copy
fights over the same runtime footprint: they all try to `php artisan serve` on port `8000`,
share one database, and write to the same Redis / queue / cache prefixes. You get
"address already in use" errors and copies clobbering each other's data.

This collides hard with **AI coding agents**. A common workflow now is to fan several agents
out in parallel — each on its own git worktree or workspace — so they can build, boot, and
test independently. Without isolation they all grab port `8000` and the same database and trip
over one another.

`php artisan isolate` fixes this with one idempotent command per worktree. It picks a free
**instance number `n`** and derives the whole runtime footprint from it — ports (`base + n`),
the database name, and Redis / Horizon prefixes (`base + suffix(n)`) — verifies nothing
is already in use, writes the app's `.env`, and creates the per-instance database. Point each
agent at its own checkout, run `isolate` once, and every agent gets a clean, isolated
environment:

| Worktree | Command | App URL | Database | Prefix |
| -------- | ------- | ------- | -------- | ------ |
| Agent A  | `php artisan isolate` | `http://localhost:8000` | `app`   | `app`   |
| Agent B  | `php artisan isolate` | `http://localhost:8001` | `app_1` | `app_1` |
| Agent C  | `php artisan isolate` | `http://localhost:8002` | `app_2` | `app_2` |

(The `Database` and `Prefix` columns share a base here for illustration. In practice the database
name derives from your default connection's configured database and the prefixes from
`isolate.name`, so their bases can differ.)

At `n = 0` no suffix is added, so a checkout returns to vanilla defaults. Everything is
idempotent and lock-guarded, and every seam is an interface you can swap.

## Installation

Requires **PHP 8.2+** and **Laravel 11, 12, or 13**.

```bash
composer require ldiebold/isolate
```

The service provider is auto-discovered. A typical worktree / agent setup runs `isolate` right
after creating the checkout:

```bash
git worktree add ../app-feature-x
cd ../app-feature-x
composer install
php artisan isolate            # claim a free instance number for this worktree
```

Publish the config if you want to customise the resource map:

```bash
php artisan vendor:publish --tag=isolate-config
```

## Documentation

### Commands

```bash
php artisan isolate              # auto-select the next free instance number
php artisan isolate --auto       # same as above
php artisan isolate --number=3   # use instance 3 explicitly
php artisan isolate --reset      # forced return to vanilla (instance 0)
php artisan isolate --migrate    # isolate, then run migrations against the new database
php artisan isolate --seed       # isolate, then migrate + seed
php artisan isolate --restart    # fire any registered restart hooks after applying
```

Inspect the current state and candidate numbers:

```bash
php artisan isolate:status            # current number + resolved ports/names
php artisan isolate:list              # candidate numbers and detected conflict reasons
php artisan isolate:list --limit=20   # inspect more candidates (default 10, capped at max_instances)
```

Running `isolate` with no flags behaves like `--auto`. Re-running is idempotent: a recorded
`ISOLATE_NUMBER` is preferred, so the same checkout keeps its number, existing databases are
reused, and the resolved `.env` values stay stable — exactly what you want when an agent re-runs
its setup script.

### How it works

Every resource derives its value from one shared instance number `n`:

| Resource type | Example                 | Value at `n`                             |
| ------------- | ----------------------- | ---------------------------------------- |
| `port`        | `SERVER_PORT` base 8000 | `8000 + n`                               |
| `name`        | `REDIS_PREFIX`          | the configured prefix + suffix(n)        |
| `name` (db)   | `DB_DATABASE`           | `base + suffix(n)`, normalized + created |
| `derived`     | `APP_URL`               | the existing URL with its port rewritten |

At `n = 0` no suffix is added, so names return to their base values. Fresh auto-selection only
chooses a number whose browser-facing ports avoid Chrome's restricted-port set and whose actual
resources (ports, databases, Redis prefixes) are free. Explicit `--number` choices and a recorded
`ISOLATE_NUMBER` are treated as intentional self-claims: they may warn about detected conflicts,
but restricted-port filtering is not applied to them. There is no sibling-checkout discovery;
conflicts are detected from real resource state.

### Configuration

`config/isolate.php` is pure, cacheable data:

```php
'name'              => null,    // null ⇒ Str::slug(config('app.name'))
'suffix_format'     => '_{n}',  // n = 0 ⇒ no suffix
'band_size'         => 100,
'max_instances'     => 50,      // valid n: 0..49
'lock_path'         => null,    // null ⇒ storage/framework/cache/isolate.lock
'env_path'          => null,    // null ⇒ base_path('.env') (point elsewhere for monorepos)
'env_example_path'  => null,    // null ⇒ base_path('.env.example')
'throw_on_conflict' => env('ISOLATE_THROW_ON_CONFLICT', false),
'restricted_ports'  => [ /* Chrome ERR_UNSAFE_PORT set */ ],
'resources'         => [ /* the map below */ ],
```

Each resource declares an `active_when` predicate so the default map self-activates only what
is present: `'always'`, `['env' => 'KEY']`, `['config' => 'path']`, `['package' => 'vendor/name']`,
`['any' => [...]]`, `['all' => [...]]`. The `{default}` token in a config path resolves to the
default database connection.

```php
['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
['type' => 'derived', 'env' => 'APP_URL', 'rewrite_port_of' => 'APP_URL', 'port_from' => 'SERVER_PORT', 'active_when' => 'always'],
['type' => 'port', 'env' => ['REVERB_SERVER_PORT', 'REVERB_PORT'], 'base' => 8100, 'browser_facing' => true, 'active_when' => ['package' => 'laravel/reverb']],
['type' => 'name', 'env' => 'DB_DATABASE', 'config' => 'database.connections.{default}.database', 'side_effect' => 'create_database', 'normalize' => 'database_identifier', 'active_when' => ['config' => 'database.connections.{default}.database']],
['type' => 'name', 'env' => 'REDIS_PREFIX',   'config' => 'database.redis.options.prefix', 'active_when' => ['config' => 'database.redis.options.prefix']],
['type' => 'name', 'env' => 'HORIZON_PREFIX', 'config' => 'horizon.prefix', 'active_when' => ['package' => 'laravel/horizon']],
```

#### Band spacing (read this before customising the map)

Because the **same** `n` is added to every port base, distinct bases never collide within one
instance. `band_size` exists to stop **cross-instance** overlap (`baseA + nₐ == baseB + n_b`).
The invariant validated on every run is:

> port bases must be **at least `band_size` apart** and **≥ 1024** (unprivileged), and
> `max_instances` must be **≤ `band_size`**.

`max_instances` is a **count**, so valid numbers are `0 .. max_instances - 1`.

Vanilla Laravel's `serve` (8000) and Reverb (8080) are only 80 apart and would **fail**
validation at `band_size = 100`. The shipped map therefore spaces Reverb at **8100**. If you
add resources, keep their bases ≥ `band_size` apart (or lower `band_size`).

### Extending

Config stays pure data; anything involving closures or runtime objects is registered on the
`Isolate` facade, typically from a service provider's `boot()`:

```php
use Ldiebold\Isolate\Facades\Isolate;

// Add a port resource (e.g. Vite's dev server). Pass an array of keys to write
// several env keys for the same port.
Isolate::port('VITE_PORT', 8200, ['browser_facing' => true]);

// Add a per-instance name resource (prefix, queue name, etc.).
Isolate::name('PULSE_PREFIX');

// Compute a derived env value at runtime (closure or class-string DerivedResolver).
Isolate::derive('PUSHER_APP_CLUSTER', fn (array $env, int $n) => 'eu-'.$n);

// Run a callback after the plan is applied, or after a database is created.
Isolate::after(fn ($plan, $result) => /* ... */);
Isolate::afterDatabaseCreated(fn ($result, $plan) => /* ... */);

// Fire a restart hook with `isolate --restart` (closure OR cache-safe class-string).
Isolate::restartUsing(RestartHorizon::class);
```

For deeper customisation you can register a custom applier or collision detector, or override a
raw resource definition:

```php
Isolate::applier(MyFrontendApplier::class);
Isolate::collisionDetector(MyServiceCollisionDetector::class);
Isolate::resource('VITE_PORT', ['type' => 'port', 'env' => 'VITE_PORT', 'base' => 8200, 'active_when' => 'always']);
```

Most seams are interfaces with shipped defaults and test fakes:
`PortChecker`, `EnvWriter`, and `PackageDetector` are resolved from the container and can be
rebound directly. Register custom `CollisionDetector` and `Applier` implementations through the
facade methods above. Database creation and locking are internal defaults today rather than
container-swappable contracts.

### Running programmatically

`php artisan isolate` is a thin wrapper over the `Isolate` service, so you can run the same flow
from your own code — an agent orchestrator, a custom command, a test — and inspect the result:

```php
use Ldiebold\Isolate\Facades\Isolate;
use Ldiebold\Isolate\IsolationRequest;

$result = Isolate::run(IsolationRequest::auto());   // or ::for(3) / ::reset()

$result->number;        // the chosen instance number
$result->plan->envMap;  // every env value that was written
$result->warnings;      // non-fatal conflict / degradation messages
```

### Events

Isolate dispatches two events you can listen for:

- `Ldiebold\Isolate\Events\IsolationApplied` — after a plan is applied (`$plan`, `$result`).
- `Ldiebold\Isolate\Events\DatabaseCreated` — after a per-instance database is created (`$result`, `$plan`).

The `Isolate::after(...)` and `Isolate::afterDatabaseCreated(...)` callbacks fire alongside these.

### Conflict policy

By default conflicted candidate numbers are skipped (so `--auto` finds the next free one) and
explicit `--number` / `--reset` selections **warn** about detected conflicts but proceed. Set
`throw_on_conflict` (or `ISOLATE_THROW_ON_CONFLICT=true`) to fail fast on confirmed conflicts.
Unavailable probes (DB down, Redis absent, no lock) always degrade with a warning, never a
crash.

### Testing

```bash
composer test          # Pest
composer lint          # Pint + PHPStan
```

Postgres / MySQL database-creation tests are gated behind `INTEGRATION_DB=1` (configure the
server with the `ISOLATE_PG_*` / `ISOLATE_MYSQL_*` env vars). Everything else, including SQLite
creation, runs without external services.

## License

MIT.
