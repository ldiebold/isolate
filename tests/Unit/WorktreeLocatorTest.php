<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Support\WorktreeLocator;
use Symfony\Component\Process\Process;

/**
 * @param  array<string, string>  $responses
 */
function fakeGit(array $responses): Closure
{
    return fn (array $args): ?string => $responses[implode(' ', $args)] ?? null;
}

it('detects a linked worktree by its differing git dirs', function () {
    $locator = new WorktreeLocator('/wt', fakeGit([
        'rev-parse --absolute-git-dir' => '/origin/.git/worktrees/wt',
        'rev-parse --path-format=absolute --git-common-dir' => '/origin/.git',
    ]));

    expect($locator->isLinkedWorktree())->toBeTrue();
});

it('treats the main worktree (matching git dirs) as not linked', function () {
    $locator = new WorktreeLocator('/main', fakeGit([
        'rev-parse --absolute-git-dir' => '/main/.git',
        'rev-parse --path-format=absolute --git-common-dir' => '/main/.git',
    ]));

    expect($locator->isLinkedWorktree())->toBeFalse()
        ->and($locator->originPath())->toBeNull();
});

it('returns null when git is unavailable or not a repo', function () {
    $locator = new WorktreeLocator('/x', fn (array $args): ?string => null);

    expect($locator->isLinkedWorktree())->toBeFalse()
        ->and($locator->originPath())->toBeNull();
});

it('resolves the origin from the first worktree list entry', function () {
    $locator = new WorktreeLocator('/wt', fakeGit([
        'rev-parse --absolute-git-dir' => '/origin/.git/worktrees/wt',
        'rev-parse --path-format=absolute --git-common-dir' => '/origin/.git',
        'worktree list --porcelain' => "worktree /home/dev/origin\nHEAD abc123\nbranch refs/heads/master\n\nworktree /home/dev/wt\nHEAD abc123\nbranch refs/heads/feature\n",
    ]));

    expect($locator->originPath())->toBe('/home/dev/origin');
});

it('locates the origin of a real linked worktree', function () {
    if (trim((string) shell_exec('command -v git')) === '') {
        $this->markTestSkipped('git is not available');
    }

    $root = sys_get_temp_dir().'/isolate_wt_real_'.uniqid();
    $origin = $root.'/origin';
    $worktree = $root.'/feature';
    mkdir($origin, 0755, true);

    $git = function (array $args) use ($origin): void {
        $process = new Process(['git', ...$args], $origin);
        $process->mustRun();
    };

    $git(['init', '-q']);
    $git(['config', 'user.email', 'test@example.com']);
    $git(['config', 'user.name', 'Test']);
    $git(['commit', '-q', '--allow-empty', '-m', 'init']);
    $git(['worktree', 'add', '-q', $worktree]);

    $origin = realpath($origin) ?: $origin;

    expect((new WorktreeLocator($worktree))->originPath())->toBe($origin);

    (new Filesystem)->deleteDirectory($root);
});
