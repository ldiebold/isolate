<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance Name
    |--------------------------------------------------------------------------
    |
    | Base name used to derive per-instance prefixes (Redis, Horizon, etc.).
    | When null, Str::slug(config('app.name')) is used. At n = 0 no suffix is
    | appended so values return to their vanilla defaults.
    |
    */

    'name' => null,

    /*
    |--------------------------------------------------------------------------
    | Suffix Format
    |--------------------------------------------------------------------------
    |
    | Format applied to "name" resources for instance n. The {n} placeholder is
    | replaced with the instance number. At n = 0 the suffix is omitted entirely
    | so vanilla defaults are preserved.
    |
    */

    'suffix_format' => '_{n}',

    /*
    |--------------------------------------------------------------------------
    | Band Size & Instance Cap
    |--------------------------------------------------------------------------
    |
    | The SAME instance number n is added to every port base, so distinct bases
    | never collide within one instance. "band_size" exists to stop cross-
    | instance overlap (baseA + nA == baseB + nB); bases must be at least
    | band_size apart. "max_instances" is a COUNT: valid n values are
    | 0 .. max_instances - 1, and max_instances must be <= band_size.
    |
    */

    'band_size' => 100,

    'max_instances' => 50,

    /*
    |--------------------------------------------------------------------------
    | Lock Path
    |--------------------------------------------------------------------------
    |
    | App-local lock file guarding the select + write critical section. When
    | null it defaults to storage/framework/cache/isolate.lock. Cross-checkout
    | safety comes from collision detectors, not this lock.
    |
    */

    'lock_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Env File Paths
    |--------------------------------------------------------------------------
    |
    | The .env file isolate writes and the example it seeds from when no .env
    | exists. When null they default to base_path('.env') and
    | base_path('.env.example'). Point them elsewhere for monorepos where the
    | Laravel app lives in a subdirectory.
    |
    */

    'env_path' => null,

    'env_example_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Worktree File Hydration
    |--------------------------------------------------------------------------
    |
    | When `isolate` runs inside a linked git worktree, these paths are copied
    | (if missing) from the origin repository this worktree was created from -
    | the gitignored artifacts a fresh `git worktree add` does not carry over.
    | Copy-if-missing: existing paths are never overwritten. The copy runs
    | before the .env is written, so a copied .env keeps its real values and
    | isolate layers the per-instance ports/prefixes on top. Set to an empty
    | array to disable, or pass --no-copy for a one-off skip.
    |
    | `vendor` is intentionally omitted: `isolate` cannot boot without it, so
    | it must already exist by the time this runs. Install it (or copy it) from
    | your worktree-creation step instead - see the README.
    |
    */

    'worktree' => [
        'copy' => [
            '.env',
            'node_modules',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conflict Policy
    |--------------------------------------------------------------------------
    |
    | When false (default) conflicted candidate numbers are skipped so the
    | selector can find the next free n, and explicit selections warn about
    | detected conflicts. When true, confirmed resource conflicts fail fast.
    |
    */

    'throw_on_conflict' => env('ISOLATE_THROW_ON_CONFLICT', false),

    /*
    |--------------------------------------------------------------------------
    | Restricted Ports
    |--------------------------------------------------------------------------
    |
    | Chromium's kRestrictedPorts (ERR_UNSAFE_PORT). A candidate n is rejected
    | if any browser-facing resource base + n lands on one of these ports.
    |
    */

    'restricted_ports' => [
        1, 7, 9, 11, 13, 15, 17, 19, 20, 21, 22, 23, 25, 37, 42, 43, 53, 69, 77,
        79, 87, 95, 101, 102, 103, 104, 109, 110, 111, 113, 115, 117, 119, 123,
        135, 137, 139, 143, 161, 179, 389, 427, 465, 512, 513, 514, 515, 526,
        530, 531, 532, 540, 548, 554, 556, 563, 587, 601, 636, 989, 990, 993,
        995, 1719, 1720, 1723, 2049, 3659, 4045, 5060, 5061, 6000, 6566, 6665,
        6666, 6667, 6668, 6669, 6697, 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Each resource declares its activation predicate via "active_when".
    | Supported forms: 'always', ['env' => 'KEY'], ['config' => 'path'],
    | ['package' => 'vendor/name'], ['any' => [...]], ['all' => [...]].
    |
    | The {default} token inside a config path is replaced with the default
    | database connection name.
    |
    */

    'resources' => [

        // App/server port. `artisan serve` honours SERVER_PORT.
        [
            'type' => 'port',
            'env' => 'SERVER_PORT',
            'base' => 8000,
            'browser_facing' => true,
            'active_when' => 'always',
        ],

        // APP_URL: rewrite ONLY the port of the existing URL (resolved after ports).
        [
            'type' => 'derived',
            'env' => 'APP_URL',
            'rewrite_port_of' => 'APP_URL',
            'port_from' => 'SERVER_PORT',
            'active_when' => 'always',
        ],

        // Reverb (native = single port). Writes both the bind and client keys.
        [
            'type' => 'port',
            'env' => ['REVERB_SERVER_PORT', 'REVERB_PORT'],
            'base' => 8100,
            'browser_facing' => true,
            'active_when' => ['package' => 'laravel/reverb'],
        ],

        // Database name + create side-effect. Base read from the connection config.
        [
            'type' => 'name',
            'env' => 'DB_DATABASE',
            'config' => 'database.connections.{default}.database',
            'side_effect' => 'create_database',
            'normalize' => 'database_identifier',
            'active_when' => ['config' => 'database.connections.{default}.database'],
        ],

        // Redis prefix: base read from the framework's configured prefix so
        // n = 0 returns to the exact vanilla value. Marked as a redis keyspace so
        // the per-instance suffix is fixed-width zero-padded (unambiguous to scan)
        // and the keys are flushed on `isolate:teardown`.
        [
            'type' => 'name',
            'env' => 'REDIS_PREFIX',
            'config' => 'database.redis.options.prefix',
            'keyspace' => 'redis',
            'active_when' => ['config' => 'database.redis.options.prefix'],
        ],

        // Horizon prefix: base read from Horizon's configured prefix, active
        // only when the Horizon Composer package is installed.
        [
            'type' => 'name',
            'env' => 'HORIZON_PREFIX',
            'config' => 'horizon.prefix',
            'keyspace' => 'redis',
            'active_when' => ['package' => 'laravel/horizon'],
        ],

        // Examples for resources that cannot be safely auto-detected:
        // [
        //     'type' => 'port',
        //     'env' => 'VITE_PORT',
        //     'base' => 8200,
        //     'browser_facing' => true,
        //     'active_when' => ['env' => 'VITE_PORT'],
        // ],
    ],

];
