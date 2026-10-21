<?php

use App\Models\Application;
use App\Models\GitAccount;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as RealProcess;

/*
 * The root `git ls-remote` probe asked before a git site is created. Its
 * credential used to be written with `tee` into /tmp under the default umask
 * and `chmod 0600`'d afterwards: a watcher on a test server caught it at 0644
 * in between, readable by every local account, other sites' users included.
 * It is private from the moment it exists now — a 0700 directory only root can
 * enter, and a file opened under umask 077 inside it.
 */

const PROBE_SENTINEL = 'ghp_probe_privacy_FAKE_sentinel';

beforeEach(function () {
    config(['server.git_credential_dir' => '/var/probe-base']);

    $this->account = GitAccount::create([
        'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat',
        'token' => PROBE_SENTINEL,
    ]);

    $this->ran = new ArrayObject;
});

function probeApp(array $overrides = []): Application
{
    // The probe gets an unsaved application, the way the create request
    // builds one before anything exists.
    return (new Application)->forceFill(array_merge([
        'git_account_id' => test()->account->id,
        'repository' => 'octocat/hello',
        'branch' => 'main',
    ], $overrides));
}

/**
 * Records every command with its stdin; `$respond` may answer one first.
 * ls-remote answers "the branch is there" unless told otherwise.
 */
function probeFake(?callable $respond = null): void
{
    Process::fake(function ($process) use ($respond) {
        $command = (array) $process->command;
        test()->ran[] = ['command' => $command, 'input' => (string) $process->input];

        if ($respond !== null && ($answer = $respond($command, $process)) !== null) {
            return $answer;
        }

        return in_array('ls-remote', $command, true)
            ? Process::result(output: "0123456789abcdef0123456789abcdef01234567\t".end($command)."\n")
            : Process::result(exitCode: 0);
    });
}

/**
 * @return array<int, array<int, string>>
 */
function probeCommands(): array
{
    return array_map(fn (array $r) => $r['command'], (array) test()->ran);
}

function probeIndex(callable $match): int|false
{
    foreach (probeCommands() as $i => $command) {
        if ($match($command)) {
            return $i;
        }
    }

    return false;
}

it('makes the credential private from the first write: a 0700 directory, then a file opened under umask 077', function () {
    probeFake();

    expect(app(GitDeployer::class)->checkRemoteBranch(probeApp()))->toBeNull();

    $commands = probeCommands();

    // The directory first, made by root (no runuser: there is no site user
    // yet), exactly 0700, at a fresh name under the configured base.
    expect($commands[0][0])->toBe('mkdir')
        ->and(array_slice($commands[0], 0, 3))->toBe(['mkdir', '-m', '0700'])
        ->and(count($commands[0]))->toBe(4)
        ->and($commands[0][3])->toMatch('#^/var/probe-base/git-[0-9a-f-]{36}$#');

    $directory = $commands[0][3];

    // Then the file, inside it. The umask is set before the redirection opens
    // the file, and the token is on stdin only.
    expect(test()->ran[1]['command'])->toBe(['sh', '-c', 'umask 077 && cat > "$1"', 'sh', $directory.'/credentials'])
        ->and(test()->ran[1]['input'])->toBe('https://x-access-token:'.PROBE_SENTINEL."@github.com\n");

    // git reads it from there, and nothing else is given it.
    $git = $commands[2];
    expect($git[0])->toBe('git')
        ->and($git)->toContain('credential.helper=store --file='.$directory.'/credentials')
        ->and($git)->toContain('credential.interactive=never')
        ->and($git)->toContain('ls-remote');

    // Removed afterwards — the directory it made, nothing more — and that is
    // the whole run.
    expect($commands[3])->toBe(['rm', '-rf', $directory])
        ->and($commands)->toHaveCount(4);
});

it('never tees, never chmods, and never puts the token on argv', function () {
    probeFake();

    app(GitDeployer::class)->checkRemoteBranch(probeApp());

    foreach (probeCommands() as $command) {
        expect($command[0])->not->toBeIn(['tee', 'chmod', 'runuser', 'sudo'])
            ->and(implode(' ', $command))->not->toContain(PROBE_SENTINEL);
    }

    // On stdin of the one write, and of nothing else.
    $carrying = array_filter((array) test()->ran, fn (array $r) => str_contains($r['input'], PROBE_SENTINEL));
    expect($carrying)->toHaveCount(1)
        ->and(array_values($carrying)[0]['command'][0])->toBe('sh');
});

it('creates nothing for a public repository', function () {
    probeFake();

    expect(app(GitDeployer::class)->checkRemoteBranch(probeApp([
        'git_account_id' => null,
        'repository' => null,
        'repository_url' => 'https://github.com/laravel/laravel.git',
    ])))->toBeNull();

    $commands = probeCommands();

    expect($commands)->toHaveCount(1)
        ->and($commands[0][0])->toBe('git')
        ->and(implode(' ', $commands[0]))->not->toContain('credential');
});

it('refuses to write when the directory cannot be made, and leaves whatever is at that path alone', function () {
    // Something already at the name — a planted symlink, say — or a full disk.
    probeFake(fn (array $c) => $c[0] === 'mkdir'
        ? Process::result(errorOutput: 'mkdir: cannot create directory: File exists', exitCode: 1)
        : null);

    expect(app(GitDeployer::class)->checkRemoteBranch(probeApp()))->toBeNull();

    // No write, no git, and above all no rm of a path that is not ours.
    expect(probeCommands())->toHaveCount(1);
    Process::assertNotRan(fn ($p) => in_array($p->command[0] ?? null, ['sh', 'tee', 'git', 'rm'], true));
});

it('removes its own directory and never runs git when the file cannot be written', function () {
    probeFake(fn (array $c) => $c[0] === 'sh'
        ? Process::result(errorOutput: 'sh: cannot create: No space left on device', exitCode: 2)
        : null);

    expect(app(GitDeployer::class)->checkRemoteBranch(probeApp()))->toBeNull();

    $commands = probeCommands();
    $directory = $commands[0][3];

    expect($commands)->toHaveCount(3)
        ->and($commands[2])->toBe(['rm', '-rf', $directory]);
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? null) === 'git');
});

it('removes only its own credential directory, whatever the remote answers', function (?string $answer, ?string $expected) {
    probeFake(fn (array $c) => in_array('ls-remote', $c, true) ? match ($answer) {
        'missing' => Process::result(output: ''),
        'denied' => Process::result(errorOutput: 'remote: Repository not found.', exitCode: 128),
        'offline' => Process::result(errorOutput: 'fatal: unable to access: Could not resolve host: github.com', exitCode: 128),
        default => null,
    } : null);

    expect(app(GitDeployer::class)->checkRemoteBranch(probeApp()))->toBe($expected);

    $commands = probeCommands();
    $directory = $commands[0][3];
    $removals = array_values(array_filter($commands, fn (array $c) => $c[0] === 'rm'));

    expect($removals)->toBe([['rm', '-rf', $directory]])
        ->and(end($commands))->toBe(['rm', '-rf', $directory]);
})->with([
    'branch present' => [null, null],
    'branch missing' => ['missing', 'branch_missing'],
    'no access' => ['denied', 'repository_unreachable'],
    'host unreachable' => ['offline', 'host_unreachable'],
]);

it('keeps each provider\'s credential username', function (string $provider, string $username, string $host) {
    test()->account->update(['provider' => $provider]);
    probeFake();

    app(GitDeployer::class)->checkRemoteBranch(probeApp());

    expect(test()->ran[1]['input'])->toBe("https://{$username}:".PROBE_SENTINEL."@{$host}\n");
})->with([
    'github' => ['github', 'x-access-token', 'github.com'],
    'gitlab' => ['gitlab', 'oauth2', 'gitlab.com'],
    'bitbucket' => ['bitbucket', 'x-token-auth', 'bitbucket.org'],
]);

it('is never readable by anyone but its owner, from the first moment it exists', function () {
    // The exact commands the probe produces, executed for real (as the test
    // user, without sudo) in a fresh temporary base — under umask 0, so
    // nothing here leans on an inherited umask being kind. After every
    // command, everything under the base is looked at.
    $base = sys_get_temp_dir().'/probe-privacy-'.bin2hex(random_bytes(6));
    mkdir($base, 0755);
    config(['server.git_credential_dir' => $base]);

    $observations = [];
    $observe = function (string $after) use ($base, &$observations) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            clearstatcache(true, $entry->getPathname());
            $observations[] = [
                'after' => $after,
                'path' => substr($entry->getPathname(), strlen($base)),
                'type' => is_link($entry->getPathname()) ? 'link' : ($entry->isDir() ? 'dir' : 'file'),
                'mode' => sprintf('%04o', fileperms($entry->getPathname()) & 07777),
                'sentinel' => $entry->isFile() && str_contains((string) file_get_contents($entry->getPathname()), PROBE_SENTINEL),
            ];
        }
    };

    $outputs = [];

    Process::fake(function ($process) use ($base, $observe, &$outputs) {
        $command = (array) $process->command;

        if (in_array('ls-remote', $command, true)) {
            $observe('git');

            return Process::result(output: "0123456789abcdef0123456789abcdef01234567\trefs/heads/main\n");
        }

        // Only ever inside the temporary base.
        expect($command[0])->toBeIn(['mkdir', 'sh', 'tee', 'chmod', 'rm'])
            ->and(str_starts_with((string) end($command), $base.'/'))->toBeTrue();

        $real = new RealProcess($command, null, null, $process->input === null ? null : (string) $process->input);
        $real->run();
        $outputs[] = $real->getOutput().$real->getErrorOutput();
        $observe($command[0]);

        return Process::result(output: $real->getOutput(), errorOutput: $real->getErrorOutput(), exitCode: (int) $real->getExitCode());
    });

    $previous = umask(0);

    try {
        expect(app(GitDeployer::class)->checkRemoteBranch(probeApp()))->toBeNull();
    } finally {
        umask($previous);
        $left = array_values(array_diff((array) scandir($base), ['.', '..']));
        File::deleteDirectory($base);
    }

    // The sentinel was seen, so the observation is about something real.
    $first = collect($observations)->firstWhere('sentinel', true);
    expect($first)->not->toBeNull()
        ->and($first['mode'])->toBe('0600')
        ->and($first['after'])->toBe('sh');

    foreach ($observations as $seen) {
        expect($seen['type'])->not->toBe('link', json_encode($seen))
            ->and(octdec($seen['mode']) & 0077)->toBe(0, json_encode($seen));

        if (substr_count($seen['path'], '/') === 1) {
            expect($seen['type'].' '.$seen['mode'])->toBe('dir 0700', json_encode($seen));
        }
    }

    // git saw exactly the private file in the private directory.
    expect(collect($observations)->where('after', 'git')->map(fn ($s) => $s['type'].' '.$s['mode'])->values()->all())
        ->toBe(['dir 0700', 'file 0600']);

    // Nothing echoed the token, and nothing outlived the probe.
    foreach ($outputs as $output) {
        expect($output)->not->toContain(PROBE_SENTINEL);
    }
    expect($left)->toBe([]);
});
