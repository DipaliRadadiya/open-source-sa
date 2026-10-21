<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\GitAccount;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Support\Facades\Process;

/*
 * FS-C22 #1: every git command in a deploy ran as root, inside a repository
 * the site user owns. `.git/config` is theirs to write, and git executes some
 * of what it says (`core.fsmonitor`, `core.sshCommand`, hooks) — so the next
 * deploy or webhook ran the site user's commands as root. Root's global
 * `safe.directory` was extended for every document root to make that work.
 */

beforeEach(function () {
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->account = GitAccount::create([
        'provider' => 'github', 'label' => 'Work', 'identifier' => 'octocat',
        'token' => 'ghp_site_user_secret',
    ]);

    $this->ran = new ArrayObject;
});

function siteUserGitApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
        'git_account_id' => test()->account->id,
        'repository' => 'octocat/hello',
        'branch' => 'main',
    ], $overrides));
}

/**
 * Records every command; `$respond` may answer one before the defaults do.
 */
function siteUserGitFake(?callable $respond = null, bool $hasGitDirectory = false): void
{
    Process::fake(function ($process) use ($respond, $hasGitDirectory) {
        $command = (array) $process->command;
        test()->ran[] = $command;

        if ($respond !== null && ($answer = $respond($command)) !== null) {
            return $answer;
        }

        return match (true) {
            $command[0] === 'test' && str_ends_with((string) end($command), '/.git') => Process::result(exitCode: $hasGitDirectory ? 0 : 1),
            $command[0] === 'test' => Process::result(exitCode: 1),
            in_array('rev-parse', $command, true) => Process::result(output: "abc123def456\n"),
            $command[0] === 'curl' => Process::result(output: '200'),
            default => Process::result(exitCode: 0),
        };
    });
}

function siteUserDeploy(Application $application): void
{
    (new DeployApplication($application->id))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
        app(DeploymentRecorder::class),
    );
}

/**
 * @return array<int, array<int, string>> every command that runs git, as run
 */
function siteUserGitCommands(): array
{
    return array_values(array_filter(
        (array) test()->ran,
        fn (array $c) => $c[0] === 'git' || ($c[0] === 'runuser' && ($c[4] ?? null) === 'git'),
    ));
}

it('runs every git command in the repository as the site user, never as root', function () {
    siteUserGitFake();

    siteUserDeploy($app = siteUserGitApp());

    $git = siteUserGitCommands();

    expect($app->fresh()->last_commit)->toBe('abc123def456')
        ->and($git)->not->toBeEmpty()
        ->and(array_filter($git, fn (array $c) => $c[0] === 'git'))->toBe([])
        ->and(array_filter($git, fn (array $c) => array_slice($c, 0, 4) !== ['runuser', '-u', 'deploy', '--']))->toBe([]);

    // Each step of the deploy, not only the fetch.
    foreach (['init', 'remote', 'set-url', 'fetch', 'reset', '--set-upstream-to=origin/main', 'rev-parse', 'log'] as $step) {
        expect(collect($git)->contains(fn (array $c) => in_array($step, $c, true)))->toBeTrue();
    }
});

it('runs a public repository as the site user too, with no credential at all', function () {
    siteUserGitFake();

    siteUserDeploy(siteUserGitApp([
        'git_account_id' => null,
        'repository' => null,
        'repository_url' => 'https://github.com/laravel/laravel.git',
    ]));

    $git = siteUserGitCommands();

    expect(array_filter($git, fn (array $c) => $c[0] === 'git'))->toBe([])
        ->and(collect($git)->contains(fn (array $c) => in_array('https://github.com/laravel/laravel.git', $c, true)))->toBeTrue()
        ->and(collect($git)->contains(fn (array $c) => in_array('credential.helper=', $c, true)))->toBeFalse()
        ->and(collect((array) test()->ran)->contains(fn (array $c) => in_array('mkdir', $c, true)))->toBeFalse();
});

it('never adds a document root to root\'s global safe.directory', function () {
    siteUserGitFake();

    siteUserDeploy(siteUserGitApp());

    Process::assertNotRan(fn ($p) => str_contains(implode(' ', (array) $p->command), 'safe.directory'));
    Process::assertNotRan(fn ($p) => in_array('--global', (array) $p->command, true));
});

it('hands the token to the site user through a private file, and to no other credential helper', function () {
    siteUserGitFake();

    siteUserDeploy(siteUserGitApp());

    $ran = collect((array) test()->ran);

    // A 0700 directory the site user makes, so nothing can be waiting at it.
    $mkdir = $ran->first(fn (array $c) => array_slice($c, 0, 7) === ['runuser', '-u', 'deploy', '--', 'mkdir', '-m', '0700']);
    expect($mkdir)->not->toBeNull();
    $directory = $mkdir[7];
    expect($directory)->toMatch('#/git-[0-9a-f-]{36}$#');

    // The token only ever on stdin.
    Process::assertNotRan(fn ($p) => str_contains(implode(' ', (array) $p->command), 'ghp_site_user_secret'));
    Process::assertRan(fn ($p) => (array) $p->command === ['runuser', '-u', 'deploy', '--', 'sh', '-c', 'umask 077 && cat > "$1"', 'sh', $directory.'/credentials']
        && str_contains((string) $p->input, 'ghp_site_user_secret'));

    // The empty helper clears any helper set earlier — including one in the
    // repository's own `.git/config` — before the panel's own.
    $fetch = $ran->first(fn (array $c) => in_array('fetch', $c, true));
    $reset = array_search('credential.helper=', $fetch, true);
    $store = array_search('credential.helper=store --file='.$directory.'/credentials', $fetch, true);

    expect($reset)->not->toBeFalse()
        ->and($store)->not->toBeFalse()
        ->and($reset)->toBeLessThan($store)
        ->and($fetch[$reset - 1])->toBe('-c')
        ->and($fetch)->toContain('credential.interactive=never');

    // Nothing else is given the file.
    expect($ran->filter(fn (array $c) => str_contains(implode(' ', $c), 'credential.helper=store'))->count())->toBe(1);

    // And it is gone afterwards, removed by its owner.
    Process::assertRan(fn ($p) => (array) $p->command === ['runuser', '-u', 'deploy', '--', 'rm', '-rf', $directory]);
});

it('removes the credential even when the fetch fails, and as root when the site user cannot', function () {
    siteUserGitFake(fn (array $c) => match (true) {
        in_array('fetch', $c, true) => Process::result(errorOutput: 'fatal: auth failed', exitCode: 128),
        $c[0] === 'runuser' && ($c[4] ?? null) === 'rm' => Process::result(errorOutput: 'runuser: user deploy does not exist', exitCode: 1),
        default => null,
    });

    siteUserDeploy($app = siteUserGitApp());

    $directory = collect((array) test()->ran)->first(fn (array $c) => in_array('mkdir', $c, true))[7];

    expect($app->fresh()->failed_step)->toBe('fetch');
    Process::assertRan(fn ($p) => (array) $p->command === ['rm', '-rf', $directory]);
});

it('does not fetch when the private credential cannot be written', function () {
    siteUserGitFake(fn (array $c) => in_array('mkdir', $c, true)
        ? Process::result(errorOutput: 'mkdir: cannot create directory', exitCode: 1)
        : null);

    siteUserDeploy($app = siteUserGitApp());

    expect($app->fresh()->failed_step)->toBe('write_credential');
    Process::assertNotRan(fn ($p) => in_array('fetch', (array) $p->command, true));
});

it('hands a .git root left behind to the site user before git runs as them', function () {
    // Checkouts made before this change, and v7's, may still hold root-owned
    // objects the site user cannot write.
    siteUserGitFake(hasGitDirectory: true);

    siteUserDeploy($app = siteUserGitApp());

    $ran = array_values((array) test()->ran);
    $gitDirectory = $app->codePath().'/.git';

    $chown = array_search(['chown', '-R', '-h', 'deploy:deploy', $gitDirectory], $ran, true);
    $firstGit = array_key_first(array_filter($ran, fn (array $c) => ($c[4] ?? null) === 'git'));

    expect($chown)->not->toBeFalse()
        ->and($chown)->toBeLessThan($firstGit);
});

it('leaves a checkout with no .git alone', function () {
    siteUserGitFake(hasGitDirectory: false);

    siteUserDeploy($app = siteUserGitApp());

    Process::assertNotRan(fn ($p) => ($p->command[0] ?? null) === 'chown' && end($p->command) === $app->codePath().'/.git');
});

it('keeps the remote probe as root, outside any repository', function () {
    // `ls-remote` reads no repository, and a site being created has no
    // system user to run it as yet.
    siteUserGitFake();

    expect(app(GitDeployer::class)->checkRemoteBranch(siteUserGitApp()))->toBe('branch_missing');

    Process::assertRan(fn ($p) => ($p->command[0] ?? null) === 'git' && in_array('ls-remote', (array) $p->command, true)
        && ! in_array('-C', (array) $p->command, true));
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? null) === 'runuser');
});

it('raises a failed git step as the provisioning failure it was', function () {
    siteUserGitFake(fn (array $c) => in_array('reset', $c, true)
        ? Process::result(errorOutput: 'error: unable to unlink old file', exitCode: 1)
        : null);

    expect(fn () => app(GitDeployer::class)->deploy(siteUserGitApp(), '/home/deploy/shop/public_html'))
        ->toThrow(ProvisioningFailedException::class);
});
