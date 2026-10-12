<?php

use App\Jobs\DeployApplication;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\Applications\ProvisionProgress;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    config(['server.web_server_drivers.nginx.sites_dir' => '/etc/nginx/sites-enabled']);
});

function budgetApp(array $overrides = []): Application
{
    return Application::create(array_merge([
        'system_user_id' => test()->su->id,
        'name' => 'Site '.Str::random(6),
        'domain' => 'site.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'pending',
    ], $overrides));
}

describe('job timeouts', function () {
    it('gives every marketplace application more time than its own installer asks for', function () {
        // The regression this exists for: the job carried a flat 300 while
        // twelve installers declared 900–1800. With pcntl loaded Laravel
        // enforces its timeout with SIGALRM, so each of those was killed
        // mid-download and marked failed at step `worker`. Nextcloud, at
        // 280 MB, could never install at all.
        $installers = (array) config('server.installers');

        expect($installers)->not->toBeEmpty();

        foreach ($installers as $siteType => $settings) {
            $installer = (int) ($settings['timeout'] ?? config('server.installer_timeout'));
            $job = new ProvisionApplication(budgetApp(['site_type' => $siteType, 'domain' => "{$siteType}.example.com"])->id);

            expect($job->timeout)->toBeGreaterThan(
                $installer,
                "{$siteType}: the job would be killed before its installer's own timeout"
            );
        }
    });

    it('leaves a deploy room for the steps after the build', function () {
        // The old 900 was exactly git_timeout + build_timeout, so a build that
        // used its full allowance killed the job just as it finished — before
        // the chown and the restart that make the new code live.
        $slow = (int) config('server.git_timeout') + (int) config('server.build_timeout');

        expect((new DeployApplication(budgetApp()->id))->timeout)->toBeGreaterThan($slow);
    });

    it('falls back to the default allowance for a site type with nothing to install', function () {
        // git, blank PHP and static have no installer; they still get the
        // overhead for the directory, the vhost and the reload.
        $job = new ProvisionApplication(budgetApp(['site_type' => 'php'])->id);

        expect($job->timeout)->toBeGreaterThan((int) config('server.installer_timeout'));
    });

    it('survives an application deleted between dispatch and construction', function () {
        expect((new ProvisionApplication(99999))->timeout)->toBeGreaterThan(0);
    });
});

describe('docker app first install (RC-20)', function () {
    it('gives every recipe and the Container tab twenty minutes plus the overhead', function () {
        // Recipes and `docker` have no `installers.<type>.timeout`, so they got
        // the 300 s default: 420 s for a job that pulls the images. Open WebUI +
        // Ollama (3.8 GB) died at step `worker` twice that way in RC-16.
        $budget = app(ProvisioningBudget::class);
        $recipes = array_keys(app(RecipeRegistry::class)->all());

        expect($recipes)->toContain('vaultwarden');
        expect($budget->forSiteType('docker'))->toBe(1320);

        foreach ($recipes as $slug) {
            expect($budget->forSiteType($slug))->toBe(1320, "{$slug}: a recipe's first install keeps the old 420 s");
        }
    });

    it('reads the allowance from server.docker.install_timeout', function () {
        config(['server.docker.install_timeout' => 1500]);

        expect(app(ProvisioningBudget::class)->forSiteType('vaultwarden'))->toBe(1620)
            ->and(app(ProvisioningBudget::class)->forSiteType('docker'))->toBe(1620);
    });

    it('leaves native site types where they were', function () {
        $budget = app(ProvisioningBudget::class);

        expect($budget->forSiteType('php'))->toBe(420)
            ->and($budget->forSiteType(null))->toBe(420)
            ->and($budget->forSiteType('nextcloud'))->toBe(120 + (int) config('server.installers.nextcloud.timeout'));
    });

    it('counts the docker budget in the longest job', function () {
        // `longest()` walks the installer list, which no Docker app is in.
        // Raised past every native installer, the Docker budget would be
        // invisible to the `retry_after` check without this.
        config(['server.docker.install_timeout' => 5000]);

        expect(app(ProvisioningBudget::class)->longest())->toBe(5120);

        config(['server.docker.install_timeout' => 1200]);

        expect(app(ProvisioningBudget::class)->longest())
            ->toBeGreaterThanOrEqual(app(ProvisioningBudget::class)->forSiteType('docker'));
    });

    it('sizes the provisioning job of a container site from it', function () {
        $app = budgetApp(['site_type' => 'docker', 'serving_profile' => 'docker', 'image' => 'nginx:1.27-alpine', 'container_port' => 80]);

        expect((new ProvisionApplication($app->id))->timeout)->toBe(1320);
    });

    it('lets the first compose up of a provision pull for the whole allowance', function (string $siteType) {
        $timeouts = [];
        Process::fake(function ($process) use (&$timeouts) {
            $line = implode(' ', (array) $process->command);

            if (str_contains($line, ' compose ') && str_contains($line, ' up -d')) {
                $timeouts[] = $process->timeout;
            }

            return match (true) {
                // The validator's parse of a recipe's rendered file: one service,
                // published to loopback on the allocated port.
                str_contains($line, 'config --format json') => Process::result(json_encode(['services' => ['app' => [
                    'image' => 'example/app:1',
                    'ports' => [['host_ip' => '127.0.0.1', 'published' => '20101', 'target' => 80]],
                ]]])),
                str_contains($line, '--status running') => Process::result("abc\n"),
                default => Process::result(),
            };
        });

        // `forceCreate`, because a recipe renders its compose from the slug and
        // the port, which the create action assigns and `create()` drops.
        $app = Application::forceCreate([
            'system_user_id' => test()->su->id,
            'name' => 'Site '.$siteType,
            'slug' => $siteType,
            'domain' => "{$siteType}.example.com",
            'site_type' => $siteType,
            'serving_profile' => 'docker',
            'web_root' => 'public_html',
            'status' => 'pending',
            'app_port' => 20101,
            'image' => $siteType === 'docker' ? 'nginx:1.27-alpine' : null,
            'container_port' => $siteType === 'docker' ? 80 : null,
        ]);

        (new ProvisionApplication($app->id))->handle(
            app(ApplicationProvisioner::class),
            app(ActivityLogger::class),
        );

        expect($timeouts)->not->toBeEmpty()
            ->and($timeouts[0])->toBe(1200);
    })->with(['docker', 'memos']);

    it('keeps the ordinary ceiling on every other compose up and pull', function () {
        // Only the first install waits that long. A start, a settings change
        // or a "pull and redeploy" runs inside a request or a shorter job.
        $ups = [];
        $pulls = [];
        Process::fake(function ($process) use (&$ups, &$pulls) {
            $line = implode(' ', (array) $process->command);

            if (str_contains($line, ' compose ') && str_contains($line, ' up -d')) {
                $ups[] = $process->timeout;
            }

            if (str_contains($line, ' compose ') && str_ends_with($line, ' pull')) {
                $pulls[] = $process->timeout;
            }

            return Process::result(str_contains($line, '--status running') ? "abc\n" : '');
        });

        $supervisor = app(ContainerSupervisor::class);
        $app = budgetApp(['site_type' => 'docker', 'serving_profile' => 'docker', 'image' => 'nginx:1.27-alpine', 'container_port' => 80]);
        $root = '/home/deploy/site/public_html';

        $supervisor->apply($app, $root);
        $supervisor->start($app, $root);
        $supervisor->pull($app, $root);

        expect($ups)->toHaveCount(3)
            ->and($ups)->each->toBe(600)
            ->and($pulls)->toBe([600]);

        $supervisor->apply($app, $root, install: true);

        expect(end($ups))->toBe(1200);
    });
});

describe('queue reservation window', function () {
    it('outlasts the longest job on every connection that has one', function () {
        // `retry_after` is when the queue decides a reserved job is dead and
        // lets another worker take it. Shorter than the job means a second
        // worker re-enters a half-applied server change — and `$tries = 1` does
        // not prevent it, because that is a new reservation, not a retry.
        $longest = app(ProvisioningBudget::class)->longest();

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            expect((int) config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan(
                $longest,
                "{$connection}: a job still running could be picked up a second time"
            );
        }
    });
});

describe('live progress', function () {
    it('records steps as they happen, not all at the end', function () {
        // `steps[]` is documented as the progress indicator while an
        // application sits at `provisioning`. It was assembled in a local
        // variable and written once on success, so during the twenty minutes a
        // Nextcloud install takes it was `[]`.
        $app = budgetApp();
        $seen = [];

        Process::fake(function ($process) use ($app, &$seen) {
            // Read the row, not the model the job is holding — the question is
            // what a poll from the frontend would see right now.
            $seen[] = count((array) Application::whereKey($app->id)->value('steps'));

            return Process::result(exitCode: 0);
        });

        (new ProvisionApplication($app->id))->handle(
            app(ApplicationProvisioner::class),
            app(ActivityLogger::class),
        );

        // The row grew while the job was running: by the time the config was
        // written, the earlier steps were already visible to a poll. Before
        // this, every one of these reads was 0.
        expect($seen)->not->toBeEmpty();
        expect(max($seen))->toBeGreaterThan(0);
        expect($seen[0])->toBe(0);
    });

    it('keeps the steps it got through when a step fails', function () {
        // A failure used to leave `steps` empty, so the user could see which
        // step broke and nothing about how far it had got.
        // The placeholder write — as the site user, after ownership.
        Process::fake(fn ($process) => in_array('tee', $process->command, true)
            && str_ends_with((string) end($process->command), '/index.php')
            ? Process::result(errorOutput: 'no space left on device', exitCode: 1)
            : Process::result(exitCode: 0));

        $app = budgetApp();

        (new ProvisionApplication($app->id))->handle(
            app(ApplicationProvisioner::class),
            app(ActivityLogger::class),
        );

        $app->refresh();

        expect($app->status->value)->toBe('failed');
        expect($app->failed_step)->toBe('placeholder');
        expect($app->steps)->toBe(['ensure_account', 'create_directory', 'set_ownership']);
    });

    it('starts a retry from an empty list rather than the previous attempt', function () {
        $app = budgetApp();

        Process::fake(fn ($process) => in_array('tee', $process->command, true)
            && str_ends_with((string) end($process->command), '/index.php')
            ? Process::result(exitCode: 1)
            : Process::result(exitCode: 0));

        (new ProvisionApplication($app->id))->handle(
            app(ApplicationProvisioner::class),
            app(ActivityLogger::class),
        );

        Process::fake(fn ($process) => $process->command[0] === 'mkdir'
            ? Process::result(exitCode: 1)
            : Process::result(exitCode: 0));

        (new ProvisionApplication($app->id))->handle(
            app(ApplicationProvisioner::class),
            app(ActivityLogger::class),
        );

        // Only what this attempt got through — the account check — and not the
        // steps the first attempt managed before it failed.
        expect($app->refresh()->steps)->toBe(['ensure_account']);
    });
});

describe('the progress recorder', function () {
    it('collapses a step whose commands run several times', function () {
        // `downloadAndExtract` runs three commands under `extract`. The user is
        // being told what is happening, not how many processes it took.
        $app = budgetApp();
        $progress = app(ProvisionProgress::class);

        $progress->open($app);
        $progress->record('download');
        $progress->record('download');
        $progress->record('extract');
        $progress->record('extract');
        $progress->record('download');

        // The last one appears again: only the immediately preceding step is
        // compared, so a step that legitimately recurs is not swallowed.
        expect($progress->steps())->toBe(['download', 'extract', 'download']);
        expect($app->refresh()->steps)->toBe(['download', 'extract', 'download']);
    });

    it('writes nowhere until something opens it', function () {
        // An installer exercised directly in a test has no application open.
        $progress = app(ProvisionProgress::class);

        $progress->record('download');

        expect($progress->steps())->toBe([]);
    });
});
