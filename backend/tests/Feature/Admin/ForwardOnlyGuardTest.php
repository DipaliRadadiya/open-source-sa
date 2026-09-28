<?php

use App\Services\Panel\ForwardOnlyGuard;

/**
 * The downgrade guard, run for real against real repositories.
 *
 * The tests that existed checked that the script *contained*
 * `merge-base --is-ancestor`. It did, and on a shallow clone — which is what
 * install.sh makes — it never fired: the Apache test box, 238 commits past
 * v1.0.17, "updated" to v1.0.17 and reported success. So these build an
 * origin with a release tag, clone it the way install.sh does, and execute
 * the guard.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/forward-guard-'.bin2hex(random_bytes(4));
    mkdir($this->root);

    $this->git = fn (string $dir, string $args): string => trim((string) shell_exec(
        'git -c user.name=t -c user.email=t@t -c init.defaultBranch=main -C '.escapeshellarg($dir).' '.$args.' 2>&1'
    ));

    // origin: c1 — c2 (tag v1.0.0) — c3 — c4 (main)
    $origin = $this->root.'/origin';
    mkdir($origin);
    ($this->git)($origin, 'init -q');

    foreach (['c1', 'c2', 'c3', 'c4'] as $commit) {
        file_put_contents("{$origin}/file", $commit);
        ($this->git)($origin, "add file && git -C {$origin} -c user.name=t -c user.email=t@t commit -qm {$commit}");

        if ($commit === 'c2') {
            ($this->git)($origin, 'tag v1.0.0');
        }
    }

    $this->origin = $origin;
});

afterEach(function () {
    shell_exec('rm -rf '.escapeshellarg($this->root));
});

/** Clone the way install.sh does, optionally at an earlier commit. */
function guardClone(string $name, ?string $checkout = null, bool $shallow = true): string
{
    $dir = test()->root.'/'.$name;
    shell_exec(sprintf(
        'git clone -q %s %s %s 2>&1',
        $shallow ? '--depth 1' : '',
        escapeshellarg('file://'.test()->origin),
        escapeshellarg($dir),
    ));

    if ($checkout !== null) {
        // A box installed when main was at an earlier commit.
        shell_exec('git -C '.escapeshellarg($dir).' fetch -q origin '.escapeshellarg($checkout).' 2>&1');
        shell_exec('git -C '.escapeshellarg($dir).' checkout -q '.escapeshellarg($checkout).' 2>&1');
    }

    return $dir;
}

/** @return array{exit: int, output: string} */
function runGuard(string $clone, string $tag): array
{
    $current = trim((string) shell_exec('git -C '.escapeshellarg($clone).' rev-parse HEAD'));
    $script = "finish() { echo \"FINISH \$*\"; }\n"
        .ForwardOnlyGuard::script('git -C '.escapeshellarg($clone), $tag, $current)
        ."\necho PROCEEDING\n";

    exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

it('refuses an older release on a shallow clone that is ahead of it', function () {
    // The Apache box: installed from main, main moved on, the release is behind.
    $clone = guardClone('ahead');

    expect(trim((string) shell_exec('git -C '.escapeshellarg($clone).' rev-parse --is-shallow-repository')))->toBe('true');

    $result = runGuard($clone, 'v1.0.0');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('FINISH failed target_not_newer')
        ->and($result['output'])->not->toContain('PROCEEDING');
});

it('goes ahead when the release really is newer, even from a shallow clone', function () {
    $origin = test()->origin;
    $c1 = trim((string) shell_exec('git -C '.escapeshellarg($origin).' rev-parse HEAD~3'));

    $clone = guardClone('behind', $c1);

    $result = runGuard($clone, 'v1.0.0');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('PROCEEDING');
});

it('refuses the release that is already running', function () {
    $tagged = trim((string) shell_exec('git -C '.escapeshellarg(test()->origin).' rev-parse v1.0.0'));

    $result = runGuard(guardClone('same', $tagged), 'v1.0.0');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('FINISH failed target_not_newer');
});

it('refuses a release on a history that has diverged from the running one', function () {
    $clone = guardClone('diverged', null, shallow: false);
    shell_exec('git -C '.escapeshellarg($clone).' checkout -q v1.0.0~1 2>&1');
    file_put_contents($clone.'/file', 'local');
    shell_exec('git -C '.escapeshellarg($clone).' -c user.name=t -c user.email=t@t commit -qam local 2>&1');

    $result = runGuard($clone, 'v1.0.0');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('FINISH failed target_not_newer');
});
