<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Appliers\DotenvApplier;
use Ldiebold\Isolate\Appliers\WorktreeCopyApplier;
use Ldiebold\Isolate\Env\LineDotenvWriter;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Support\PhpDirectoryCopier;
use Ldiebold\Isolate\Support\SystemDirectoryCopier;
use Ldiebold\Isolate\Support\WorktreeLocator;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_wtcopy_'.uniqid();
    $this->origin = $this->dir.'/origin';
    $this->work = $this->dir.'/work';
    $this->files->makeDirectory($this->origin, 0755, true);
    $this->files->makeDirectory($this->work, 0755, true);
});

afterEach(function () {
    $this->files->deleteDirectory($this->dir);
});

/**
 * A locator whose fake git reports a linked worktree rooted at $origin, or - when
 * $origin is null - a directory that is not a worktree at all.
 */
function locatorFor(string $work, ?string $origin): WorktreeLocator
{
    return new WorktreeLocator($work, function (array $args) use ($work, $origin): ?string {
        if ($origin === null) {
            return null;
        }

        return match (implode(' ', $args)) {
            'rev-parse --absolute-git-dir' => $origin.'/.git/worktrees/w',
            'rev-parse --path-format=absolute --git-common-dir' => $origin.'/.git',
            'worktree list --porcelain' => "worktree {$origin}\n\nworktree {$work}\n",
            default => null,
        };
    });
}

/**
 * @param  array<int, string>  $paths
 * @param  (Closure(string): void)|null  $notifier
 */
function copyApplier(WorktreeLocator $locator, string $work, array $paths, ?Closure $notifier = null): WorktreeCopyApplier
{
    $files = new Filesystem;

    return new WorktreeCopyApplier(
        $locator,
        new SystemDirectoryCopier($files, new PhpDirectoryCopier($files)),
        $files,
        $work,
        $paths,
        $notifier,
    );
}

it('copies a missing directory from the origin', function () {
    $this->files->makeDirectory($this->origin.'/node_modules', 0755, true);
    $this->files->put($this->origin.'/node_modules/pkg.txt', 'x');

    $result = copyApplier(locatorFor($this->work, $this->origin), $this->work, ['node_modules'])
        ->apply(new IsolationPlan(1));

    expect($this->files->get($this->work.'/node_modules/pkg.txt'))->toBe('x')
        ->and($result->changes)->toContain('Hydrated worktree from '.$this->origin);
});

it('copies a file such as .env verbatim', function () {
    $this->files->put($this->origin.'/.env', "APP_KEY=secret\nDB_DATABASE=app\n");

    $result = copyApplier(locatorFor($this->work, $this->origin), $this->work, ['.env'])
        ->apply(new IsolationPlan(1));

    expect($this->files->get($this->work.'/.env'))->toBe("APP_KEY=secret\nDB_DATABASE=app\n")
        ->and($result->changes)->toContain('Copied .env');
});

it('never overwrites an existing destination (copy-if-missing)', function () {
    $this->files->makeDirectory($this->origin.'/node_modules', 0755, true);
    $this->files->put($this->origin.'/node_modules/pkg.txt', 'origin');
    $this->files->makeDirectory($this->work.'/node_modules', 0755, true);
    $this->files->put($this->work.'/node_modules/pkg.txt', 'local');

    $result = copyApplier(locatorFor($this->work, $this->origin), $this->work, ['node_modules'])
        ->apply(new IsolationPlan(1));

    expect($this->files->get($this->work.'/node_modules/pkg.txt'))->toBe('local')
        ->and($result->changes)->toBe([]);
});

it('preserves symlinks inside a copied directory', function () {
    $this->files->makeDirectory($this->origin.'/node_modules/.bin', 0755, true);
    $this->files->put($this->origin.'/node_modules/real.js', 'r');
    symlink('../real.js', $this->origin.'/node_modules/.bin/tool');

    copyApplier(locatorFor($this->work, $this->origin), $this->work, ['node_modules'])
        ->apply(new IsolationPlan(1));

    expect(is_link($this->work.'/node_modules/.bin/tool'))->toBeTrue()
        ->and(readlink($this->work.'/node_modules/.bin/tool'))->toBe('../real.js');
});

it('is a silent no-op outside a worktree', function () {
    $this->files->put($this->origin.'/.env', 'X=1');

    $result = copyApplier(locatorFor($this->work, null), $this->work, ['.env'])
        ->apply(new IsolationPlan(1));

    expect($result->changes)->toBe([])
        ->and($result->warnings)->toBe([])
        ->and($this->files->exists($this->work.'/.env'))->toBeFalse();
});

it('silently skips a path the origin does not have', function () {
    $result = copyApplier(locatorFor($this->work, $this->origin), $this->work, ['node_modules'])
        ->apply(new IsolationPlan(1));

    expect($result->changes)->toBe([])->and($result->warnings)->toBe([]);
});

it('warns when a worktree is detected but the origin is gone', function () {
    $result = copyApplier(locatorFor($this->work, $this->dir.'/missing-origin'), $this->work, ['.env'])
        ->apply(new IsolationPlan(1));

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('could not resolve the origin');
});

it('rejects paths that escape the project and warns', function () {
    $result = copyApplier(locatorFor($this->work, $this->origin), $this->work, ['../evil', '/etc/passwd'])
        ->apply(new IsolationPlan(1));

    expect($result->warnings)->toHaveCount(2)
        ->and($result->changes)->toBe([]);
});

it('does nothing when the copy list is empty', function () {
    $this->files->put($this->origin.'/.env', 'X=1');

    $result = copyApplier(locatorFor($this->work, $this->origin), $this->work, [])
        ->apply(new IsolationPlan(1));

    expect($result->changes)->toBe([])
        ->and($this->files->exists($this->work.'/.env'))->toBeFalse();
});

it('notifies before a directory copy so slow copies show activity', function () {
    $this->files->makeDirectory($this->origin.'/node_modules', 0755, true);
    $this->files->put($this->origin.'/node_modules/a', '1');

    $messages = [];
    copyApplier(locatorFor($this->work, $this->origin), $this->work, ['node_modules'], function (string $m) use (&$messages): void {
        $messages[] = $m;
    })->apply(new IsolationPlan(1));

    expect($messages)->toContain('Copying node_modules …');
});

it('reports a human-readable size for a copied directory', function () {
    $this->files->makeDirectory($this->origin.'/node_modules', 0755, true);
    $this->files->put($this->origin.'/node_modules/blob', str_repeat('x', 2048));

    $result = copyApplier(locatorFor($this->work, $this->origin), $this->work, ['node_modules'])
        ->apply(new IsolationPlan(1));

    expect(collect($result->changes)->contains(fn (string $c): bool => str_starts_with($c, 'Copied node_modules (')))->toBeTrue();
});

it('layers isolate keys onto a copied origin .env (ordering B)', function () {
    // The origin .env carries real secrets and the vanilla port.
    $this->files->put($this->origin.'/.env', "APP_KEY=secret-key\nSERVER_PORT=8000\n");

    $plan = new IsolationPlan(3, ['SERVER_PORT' => '8003']);

    // 1. The copy applier runs first, seeding the worktree .env from the origin.
    copyApplier(locatorFor($this->work, $this->origin), $this->work, ['.env'])->apply($plan);

    // 2. DotenvApplier then layers the per-instance keys onto that same file.
    (new DotenvApplier(new LineDotenvWriter, $this->files, $this->work.'/.env', $this->work.'/.env.example'))
        ->apply($plan);

    expect($this->files->get($this->work.'/.env'))
        ->toContain('APP_KEY=secret-key') // preserved from the origin
        ->toContain('SERVER_PORT=8003');  // overridden by isolate
});
