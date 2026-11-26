<?php

use App\Enums\DeploymentTrigger;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Jobs\DeployApplication;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\GitDeployer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Creating a site from a repository and ending up with the repository on disk.
 *
 * A placeholder in the document root makes `git clone` refuse the directory, the first deploy
 * must be dispatched on its own, and nothing may be written root-owned after `chown -R`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->su = SystemUser::create([
        'username' => 'deploy', 'home_path' => '/home/deploy',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function firstDeployApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Shop',
        'domain' => 'shop.example.com',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
        'repository_url' => 'https://github.com/octocat/hello.git',
        'branch' => 'main',
    ], $overrides));
}

/** Records every command, and lets a test decide what the document root contains when git asks. */
function fakeFirstDeploy(string $contents = ''): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran, $contents) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        $ran->append($args);

        if (($args[0] ?? '') === 'find') {
            return Process::result(output: $contents);
        }

        // Not a repository yet: this is a first deploy.
        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-d') {
            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });

    return $ran;
}

function runFirstDeploy(Application $application): void
{
    (new DeployApplication($application->id))->handle(
        app(GitDeployer::class),
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
        app(DeploymentRecorder::class),
    );
}

/**
 * The git part of a command, whether or not it runs as the site user:
 * `runuser -u <user> -- git init` reads as `git init`.
 *
 * @param  array<int, string>  $args
 * @return array<int, string>
 */
function firstDeployGit(array $args): array
{
    return ($args[0] ?? '') === 'runuser' && ($args[3] ?? '') === '--' ? array_slice($args, 4) : $args;
}

function firstDeployRan(ArrayObject $ran, callable $matches): bool
{
    foreach ($ran as $args) {
        if ($matches($args)) {
            return true;
        }
    }

    return false;
}

it('creates the directory it is about to chown, serve and deploy into', function () {
    $app = firstDeployApp();
    $ran = fakeFirstDeploy();

    app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($app->fresh()->load('systemUser'));

    // `chown -R` on a missing path exits 1 and provisioning dies at `set_ownership`. Faked
    // processes report success, so the command list, not the exit code, is what this asserts.
    expect(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'mkdir'
        && in_array($documentRoot, $args, true)))->toBeTrue();

    expect(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'chown'
        && in_array($documentRoot, $args, true)))->toBeTrue();
});

it('gives a git site an .env above the document root, owned by the site user', function () {
    $app = firstDeployApp();
    $ran = fakeFirstDeploy();

    app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

    $fresh = $app->fresh()->load('systemUser');
    $env = $fresh->rootPath().'/.env';

    // Above the served directory: it holds secrets and anything under the document root is a URL.
    expect($env)->not->toStartWith(app(ApplicationProvisioner::class)->documentRoot($fresh));

    expect(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'touch'
        && in_array($env, $args, true)))->toBeTrue();

    // `touch` runs elevated; left root-owned, the site could not write it.
    // `-h`: a plain chown follows a link planted in the user's own folder.
    expect(firstDeployRan($ran, fn ($args) => ($args[0] ?? '') === 'chown'
        && ($args[1] ?? '') === '-h'
        && in_array('deploy:deploy', $args, true)
        && in_array($env, $args, true)))->toBeTrue();
});

it('does not write a placeholder into a git site', function () {
    $app = firstDeployApp();
    $ran = fakeFirstDeploy();

    app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

    // The placeholder is what made `git clone` refuse the directory.
    expect(firstDeployRan($ran, fn ($args) => in_array('tee', $args, true)
        && str_contains((string) end($args), 'index.php')))->toBeFalse();

    expect($app->fresh()->steps)->not->toContain('placeholder');
});

it('still writes a placeholder for a blank PHP site, as the site user after taking ownership', function () {
    $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
    $ran = fakeFirstDeploy();

    app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

    $order = [];

    foreach ($ran as $args) {
        // Root taking its own `conf/` folder back (SiteConfFolder) is not the hand-over to the user.
        if (($args[0] ?? '') === 'chown' && ($args[1] ?? '') === '-R' && ! in_array('root:root', $args, true)) {
            $order[] = 'chown';
        } elseif (($args[0] ?? '') === 'runuser' && ($args[4] ?? '') === 'tee' && str_ends_with((string) end($args), '/index.php')) {
            $order[] = 'tee as user';
        } elseif (($args[0] ?? '') === 'tee' && str_ends_with((string) end($args), '/index.php')) {
            $order[] = 'tee as root';
        }
    }

    // As the user it is theirs by construction; as root it followed a link planted at index.php.
    expect($order)->toBe(['chown', 'tee as user']);
});

it('uses init, not clone, even when the directory is empty', function () {
    // Never `git clone`: a file landing between the emptiness check and the clone (a racing
    // deploy, a retry) makes it refuse. `git init` never refuses on directory contents.
    $app = firstDeployApp(['status' => 'active']);
    $ran = fakeFirstDeploy(contents: '');

    runFirstDeploy($app);

    expect(firstDeployRan($ran, fn ($args) => (firstDeployGit($args)[0] ?? '') === 'git' && (firstDeployGit($args)[1] ?? '') === 'clone'))->toBeFalse()
        ->and(firstDeployRan($ran, fn ($args) => (firstDeployGit($args)[0] ?? '') === 'git' && (firstDeployGit($args)[1] ?? '') === 'init'))->toBeTrue();
});

it('fetches instead of cloning when the directory already has files', function () {
    $app = firstDeployApp(['status' => 'active']);

    // A migrated server's existing files, or our own placeholder: `git clone` would fail here.
    $ran = fakeFirstDeploy(contents: "index.php\n.env");

    runFirstDeploy($app);

    expect(firstDeployRan($ran, fn ($args) => (firstDeployGit($args)[0] ?? '') === 'git' && (firstDeployGit($args)[1] ?? '') === 'clone'))->toBeFalse()
        ->and(firstDeployRan($ran, fn ($args) => (firstDeployGit($args)[0] ?? '') === 'git' && (firstDeployGit($args)[1] ?? '') === 'init'))->toBeTrue()
        ->and(firstDeployRan($ran, fn ($args) => (firstDeployGit($args)[0] ?? '') === 'git' && in_array('fetch', $args, true)))->toBeTrue()
        // The reset makes it equivalent to a clone rather than a merge with what was there.
        ->and(firstDeployRan($ran, fn ($args) => in_array('reset', $args, true) && in_array('--hard', $args, true)))->toBeTrue();
});

it('never puts the credential in the stored remote on the fetch path', function () {
    $app = firstDeployApp(['status' => 'active']);
    $ran = fakeFirstDeploy(contents: 'index.php');

    runFirstDeploy($app);

    foreach ($ran as $args) {
        if (in_array('set-url', $args, true)) {
            expect(implode(' ', $args))->not->toContain('@github.com/octocat');
        }
    }

    expect(firstDeployRan($ran, fn ($args) => in_array('set-url', $args, true)))->toBeTrue();
});

it('deploys a git site automatically once it is provisioned', function () {
    Queue::fake();

    $app = firstDeployApp();
    fakeFirstDeploy();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    // "Create a site from this repository" includes fetching the repository.
    Queue::assertPushed(DeployApplication::class);

    $deployment = Deployment::first();

    // Recorded with its own trigger: nobody pressed anything, so the history must not claim so.
    expect($deployment)->not->toBeNull()
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Initial);
});

it('does not deploy a site that has no repository', function () {
    Queue::fake();

    $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
    fakeFirstDeploy();

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    Queue::assertNotPushed(DeployApplication::class);
    expect(Deployment::count())->toBe(0);
});

it('does not deploy when provisioning failed', function () {
    Queue::fake();

    $app = firstDeployApp();

    Process::fake(fn ($process) => ($process->command[0] ?? '') === 'mkdir'
        ? Process::result(exitCode: 1, errorOutput: 'nope')
        : Process::result(exitCode: 0));

    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    // A clone into a directory that was never made would fail second, with a confusing reason.
    expect($app->fresh()->status->value)->toBe('failed');
    Queue::assertNotPushed(DeployApplication::class);
});

/*
 * The deploy script at creation time.
 *
 * The first deploy runs on its own as soon as provisioning finishes, so the script has to be
 * given at creation: filled in afterwards, the first deploy has already run without it.
 */
describe('a deploy script given at creation', function () {
    it('runs on the very first deploy', function () {
        $ran = fakeFirstDeploy();

        $application = firstDeployApp(['deploy_script' => 'php artisan migrate --force']);

        runFirstDeploy($application);

        expect(firstDeployRan($ran, fn (array $args): bool => collect($args)
            ->contains(fn ($a) => is_string($a) && str_contains($a, 'php artisan migrate --force'))))
            ->toBeTrue();
    });

    it('is preferred over the build command, the same as any later deploy', function () {
        $ran = fakeFirstDeploy();

        $application = firstDeployApp([
            'build_command' => 'composer install',
            'deploy_script' => 'php artisan migrate --force',
        ]);

        runFirstDeploy($application);

        $script = collect(iterator_to_array($ran))
            ->flatten()
            ->filter(fn ($a) => is_string($a) && str_contains($a, 'artisan migrate'))
            ->first();

        // GitDeployer::script() prefers deploy_script over build_command; the first deploy is no
        // exception.
        expect($script)->not->toBeNull()
            ->and($script)->not->toContain('composer install');
    });

    it('still falls back to the build command when no script was given', function () {
        $ran = fakeFirstDeploy();

        $application = firstDeployApp(['build_command' => 'composer install --no-dev']);

        runFirstDeploy($application);

        expect(firstDeployRan($ran, fn (array $args): bool => collect($args)
            ->contains(fn ($a) => is_string($a) && str_contains($a, 'composer install --no-dev'))))
            ->toBeTrue();
    });
});

it('seeds a fresh checkout .env from the repository .env.example', function () {
    // Provisioning only touches an empty .env and repositories gitignore theirs, so without
    // seeding a git-deployed Laravel site gets a zero-byte file and no APP_KEY.
    $app = firstDeployApp();
    $ran = fakeFirstDeploy();

    // The faked curl has no status code, so `verify` throws; seeding runs well before that.
    try {
        app(GitDeployer::class)->deploy($app->load('systemUser'), $app->codePath());
    } catch (ProvisioningFailedException) {
        // Expected — see above.
    }

    $seed = collect($ran)->first(fn (array $args): bool => ($args[0] ?? '') === 'runuser'
        && str_contains((string) ($args[6] ?? ''), '.env.example'));

    expect($seed)->not->toBeNull();

    $script = (string) $seed[6];

    expect($script)
        // Never over a file with content: this runs on every deploy and the user's settings win.
        ->toContain('if [ -s "$env" ]; then exit 0; fi')
        // A repository with no example is left alone rather than guessed at.
        ->toContain('if [ ! -f "$root/.env.example" ]; then exit 0; fi')
        // Deliberately NOT key:generate — see the test below.
        ->not->toContain('key:generate')
        // Only replaced, never appended: no APP_URL in the example means a non-Laravel config.
        ->toContain('if grep -q "^APP_URL=" "$env"; then')
        ->toContain('APP_URL='.$app->url())
        // key:generate writes to base_path('.env'); with the file elsewhere it would make a second.
        ->toContain('[ "$env" = "$root/.env" ]')
        // As the site user — a root-owned .env is one the site cannot write.
        ->and($seed[1] ?? '')->toBe('-u');
});

it('generates the app key without needing the dependencies it does not have yet', function () {
    // `artisan` needs vendor/autoload.php, which `composer install` creates in the deploy script,
    // after this step. A `key:generate` here died on the first deploy and passed on the re-run.
    $app = firstDeployApp();
    $ran = fakeFirstDeploy();

    try {
        app(GitDeployer::class)->deploy($app->load('systemUser'), $app->codePath());
    } catch (ProvisioningFailedException) {
        // The faked curl has no status code, so `verify` throws. Not this test.
    }

    $script = (string) collect($ran)->first(fn (array $args): bool => ($args[0] ?? '') === 'runuser'
        && str_contains((string) ($args[6] ?? ''), '.env.example'))[6];

    // Nothing in this step may reach for the application it is preparing.
    expect($script)
        ->not->toContain('artisan')
        ->not->toContain('key:generate')
        // 32 random bytes base64-encoded is what key:generate writes; openssl needs nothing to boot.
        ->toContain('openssl rand -base64 32')
        // `|` as the sed delimiter: base64 can carry a `/` and never a `|`.
        ->toContain('sed -i "s|^APP_KEY=.*|APP_KEY=base64:$key|"')
        // Only when there is no real key: this runs on every deploy, and rotating it logs users out.
        ->toContain('! grep -q "^APP_KEY=base64:." "$env"');
});

describe('the page a new site serves', function () {
    /*
     * The page is publicly reachable from the moment a site provisions and a blank site can sit
     * for months, so what it does not contain matters more than what it does.
     */

    /**
     * Everything piped into a `tee`, keyed by the path written.
     *
     * An ArrayObject, not an array: the fake fills it after this returns, and an array would be
     * copied empty.
     */
    function teedFiles(): ArrayObject
    {
        $written = new ArrayObject;

        Process::fake(function ($process) use ($written) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            // Directly, or as the site user (`runuser -u <user> -- tee <path>`).
            $tee = ($args[0] ?? '') === 'runuser' ? array_slice($args, 4) : $args;

            if (($tee[0] ?? '') === 'tee' && isset($tee[1])) {
                $written[$tee[1]] = (string) ($process->input ?? '');
            }

            if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-d') {
                return Process::result(exitCode: 1);
            }

            return Process::result(exitCode: 0);
        });

        return $written;
    }

    it('serves a styled page rather than a bare heading', function () {
        $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
        $written = teedFiles();

        app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

        $page = $written['/home/deploy/public_html/index.php'] ?? '';

        expect($page)->toContain('shop.example.com')
            ->and($page)->toContain('<style>')
            ->and($page)->toContain('prefers-color-scheme');
    });

    it('proves PHP is running instead of claiming it', function () {
        // A static file served from a misconfigured pool looks like a working site, so the page
        // prints something only PHP could produce.
        $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
        $written = teedFiles();

        app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

        $page = $written['/home/deploy/public_html/index.php'] ?? '';

        // Literal PHP in the written file: Blade executes a `<?php` block in a view, so the
        // fragment is passed in as data.
        expect($page)->toContain('<?php echo htmlspecialchars(date(')
            // The template was rendered, not copied: a raw Blade directive would show to visitors.
            ->and($page)->not->toContain('{{')
            ->and($page)->not->toContain('@if')
            ->and($page)->not->toContain('@endif');
    });

    it('says nothing a scanner could use', function () {
        // No version, no path, no server software, no phpinfo: the page is public.
        $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
        $written = teedFiles();

        app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

        $page = $written['/home/deploy/public_html/index.php'] ?? '';

        expect($page)->not->toContain('phpinfo')
            ->not->toContain('8.4')
            ->not->toContain('/home/deploy')
            ->not->toContain('PHP_VERSION')
            // Indexed, a placeholder outranks the real site that replaces it.
            ->and($page)->toContain('noindex');
    });

    it('fetches nothing from anywhere', function () {
        // No outbound network means a dead stylesheet; it also keeps visitors off third parties.
        $app = firstDeployApp(['site_type' => 'php', 'repository_url' => null, 'branch' => null]);
        $written = teedFiles();

        app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

        $page = $written['/home/deploy/public_html/index.php'] ?? '';

        expect($page)->not->toContain('http://')
            ->not->toContain('https://')
            ->not->toContain('<link')
            ->not->toContain('<script');
    });

    it('leaves the PHP fragment out of a static site', function () {
        // A `<?php` block in an `index.html` is text the visitor reads.
        $app = firstDeployApp([
            'site_type' => 'static',
            'serving_profile' => 'static',
            'repository_url' => null,
            'branch' => null,
        ]);
        $written = teedFiles();

        app(ApplicationProvisioner::class)->provision($app->load('systemUser'));

        $page = $written['/home/deploy/public_html/index.html'] ?? '';

        expect($page)->toContain('shop.example.com')
            ->and($page)->not->toContain('<?php');
    });
});
