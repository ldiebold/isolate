<?php

use Illuminate\Config\Repository;
use Ldiebold\Isolate\Claims\SelfClaimProvider;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\NumberSelector;
use Ldiebold\Isolate\Resolver;
use Ldiebold\Isolate\SideEffect;
use Ldiebold\Isolate\Support\DatabaseNameNormalizer;
use Ldiebold\Isolate\Support\NameDeriver;
use Ldiebold\Isolate\Support\ResourceActivator;
use Ldiebold\Isolate\Support\RestrictedPorts;
use Ldiebold\Isolate\Support\TemplateResolver;
use Ldiebold\Isolate\Tests\Fakes\FakePackageDetector;
use Ldiebold\Isolate\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * @param  array<string, mixed>  $config
 */
function activator(array $config = [], array $packages = [], ?Closure $env = null): ResourceActivator
{
    return new ResourceActivator(new Repository($config), new FakePackageDetector($packages), $env);
}

/**
 * Build a Resolver from a flat config array plus run options
 * (currentNumber, baseline, packages, env reader, manager mutator).
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $options
 */
function makeResolver(array $config, array $options = []): Resolver
{
    $repository = new Repository(array_merge([
        'app' => ['name' => 'Fuellox', 'url' => 'http://localhost'],
        'isolate' => ['name' => null, 'suffix_format' => '_{n}'],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => 'forge']]],
    ], $config));

    $manager = new Isolate(app());

    if (isset($options['manager'])) {
        ($options['manager'])($manager);
    }

    $activator = new ResourceActivator(
        $repository,
        new FakePackageDetector($options['packages'] ?? []),
        $options['env'] ?? null,
    );

    return new Resolver(
        app(),
        $repository,
        $activator,
        new NameDeriver(
            $repository->get('isolate.name'),
            $repository->get('app.name'),
            $repository->get('isolate.suffix_format', '_{n}'),
        ),
        new TemplateResolver,
        new DatabaseNameNormalizer,
        $manager,
        $options['currentNumber'] ?? null,
        $options['baseline'] ?? ['APP_URL' => $repository->get('app.url')],
    );
}

/**
 * Build a NumberSelector over a single browser-facing SERVER_PORT resource,
 * with run options (self claim, restricted ports, detectors, cap, strict mode).
 *
 * @param  array<string, mixed>  $options
 */
function selector(array $options = []): NumberSelector
{
    $resolver = makeResolver(['isolate.resources' => [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
    ]]);

    return new NumberSelector(
        $resolver,
        new RestrictedPorts($options['restricted'] ?? []),
        new SelfClaimProvider($options['self'] ?? null),
        $options['detectors'] ?? [],
        $options['max'] ?? 50,
        $options['throw'] ?? false,
    );
}

/**
 * A plan that carries a single create_database side effect.
 */
function planWithDatabase(string $database = 'forge_7', string $connection = 'sqlite'): IsolationPlan
{
    return new IsolationPlan(7, ['DB_DATABASE' => $database], [
        SideEffect::createDatabase(['connection' => $connection, 'database' => $database, 'env' => 'DB_DATABASE']),
    ]);
}
