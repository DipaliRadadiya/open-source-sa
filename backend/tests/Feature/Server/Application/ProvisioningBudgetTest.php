<?php

use App\Jobs\DeployApplication;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\Applications\ProvisionProgress;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

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
        'name' => 'Site',
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
        Process::fake(fn ($process) => $process->command[0] === 'tee'
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
        expect($app->steps)->toBe(['create_directory']);
    });

    it('starts a retry from an empty list rather than the previous attempt', function () {
        $app = budgetApp();

        Process::fake(fn ($process) => $process->command[0] === 'tee'
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

        // Not the two steps the first attempt managed.
        expect($app->refresh()->steps)->toBe([]);
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
