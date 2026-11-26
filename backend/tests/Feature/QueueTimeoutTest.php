<?php

use App\Jobs\InstallBuildTools;
use App\Jobs\InstallDatabaseEngine;
use App\Jobs\InstallFail2ban;
use App\Jobs\InstallIonCubeLoader;
use App\Jobs\InstallPhpExtension;
use App\Jobs\InstallPhpVersion;
use App\Jobs\InstallSupervisor;
use App\Jobs\RedeployContainerApplication;
use App\Jobs\RemovePhpVersion;
use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * No job may outlive its own reservation.
 *
 * `retry_after` is how long the queue waits before deciding a reserved job is dead and handing
 * it to someone else. A job running longer is dispatched a second time as a fresh reservation,
 * which `$tries = 1` does nothing about. These jobs change the server: a restore running twice
 * re-extracts an archive over a site that may be in use again.
 *
 * This checks every job, so a new job declaring a long timeout cannot slip through.
 */
/**
 * @return array<string, int> job class => timeout in seconds
 */
function jobTimeouts(): array
{
    $timeouts = [];

    foreach (glob(app_path('Jobs/*.php')) as $file) {
        $class = 'App\\Jobs\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->hasProperty('timeout')) {
            continue;
        }

        $instance = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('timeout');

        // Jobs that size their timeout in the constructor have nothing to read here; they are
        // covered below.
        if (! $property->isInitialized($instance)) {
            continue;
        }

        $timeouts[$class] = (int) $instance->timeout;
    }

    return $timeouts;
}

it('finds the jobs it is meant to be checking', function () {
    // Without this the test below passes on an empty list. Jobs that compute their timeout in
    // the constructor (e.g. `RunBackup`) leave this list silently, so they are covered by the
    // next test instead.
    expect(jobTimeouts())->not->toBeEmpty();
});

it('keeps every fixed job timeout inside the reservation window', function (string $connection) {
    $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

    foreach (jobTimeouts() as $class => $timeout) {
        expect($timeout)->toBeLessThan(
            $retryAfter,
            "{$class} declares a {$timeout}s timeout against a {$retryAfter}s reservation window "
            ."on the {$connection} connection. The queue would hand the job to another worker "
            .'while it is still running, and that second run is a new reservation, not a retry.',
        );
    }
})->with(['database', 'redis', 'beanstalkd']);

it('keeps the jobs that size themselves inside it too', function (string $connection) {
    $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

    // ProvisionApplication and DeployApplication compute their timeout from
    // the budget; InstallDatabaseEngine from the installer's own limit.
    expect(app(ProvisioningBudget::class)->longest())->toBeLessThan($retryAfter)
        ->and((new InstallDatabaseEngine('mysql'))->timeout)->toBeLessThan($retryAfter);

    // RunBackup and RunRestore read `job_timeout` in their constructors, so the reflection pass
    // cannot see them. Asserted on the instances, so a job that stops reading the key is caught.
    expect((new RunBackup(1))->timeout)->toBeLessThan($retryAfter)
        ->and((new RunRestore(1, 1))->timeout)->toBeLessThan($retryAfter)
        // Pull and redeploy: `compose pull` + `compose up`.
        ->and((new RedeployContainerApplication(1))->timeout)->toBeLessThan($retryAfter);
})->with(['database', 'redis', 'beanstalkd']);

// Every job that runs apt through ServerOps::apt(), which waits out a package-manager lock for
// up to aptLockBudgetSeconds(). A timeout shorter than the wait plus the work kills the job
// mid-wait as "stopped unexpectedly".
dataset('apt jobs', fn () => [
    'InstallDatabaseEngine' => [fn () => new InstallDatabaseEngine('mysql'), 900],
    'InstallPhpVersion' => [fn () => new InstallPhpVersion('8.3'), 900],
    'InstallPhpExtension' => [fn () => new InstallPhpExtension('8.3', 'gmp'), 900],
    'RemovePhpVersion' => [fn () => new RemovePhpVersion('8.3'), 900],
    'InstallBuildTools' => [fn () => new InstallBuildTools, 900],
    'InstallSupervisor' => [fn () => new InstallSupervisor, 600],
    'InstallFail2ban' => [fn () => new InstallFail2ban, 600],
    'InstallIonCubeLoader' => [fn () => new InstallIonCubeLoader('8.3'), 600],
]);

it('lets every apt job outlast apt\'s wait for the lock, inside the reservation', function (Closure $job, int $work) {
    $timeout = $job()->timeout;

    expect($timeout)->toBeGreaterThanOrEqual($work + ServerOps::aptLockBudgetSeconds());

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect($timeout)->toBeLessThan((int) config("queue.connections.{$connection}.retry_after"));
    }
})->with('apt jobs');

/**
 * @return array<int, string> every job class that claims a unique lock
 */
function uniqueJobs(): array
{
    $unique = [];

    foreach (glob(app_path('Jobs/*.php')) as $file) {
        $class = 'App\\Jobs\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        if ($reflection->implementsInterface(ShouldBeUnique::class)
            || $reflection->implementsInterface(ShouldBeUniqueUntilProcessing::class)) {
            $unique[] = $class;
        }
    }

    return $unique;
}

it('finds the unique jobs it is meant to be checking', function () {
    expect(uniqueJobs())->not->toBeEmpty()
        ->and(uniqueJobs())->toContain(RunBackup::class);
});

it('gives every unique job a lock that expires', function () {
    // `Illuminate\Bus\UniqueLock` falls back to 0 seconds, which RedisLock reads as `setnx` with
    // no TTL: a worker killed outright (OOM, reboot) leaves a key that never expires, and every
    // later dispatch for that id is silently dropped.
    foreach (uniqueJobs() as $class) {
        expect(method_exists($class, 'uniqueFor'))->toBeTrue(
            "{$class} claims a unique lock but never says when it expires, so a worker "
            .'killed mid-job locks that id out permanently.',
        );
    }
});

it('holds each unique lock for longer than the job may run', function () {
    // Expiring late costs one delayed run; expiring early lets two run at once (two archives of
    // one site, or two restores extracting over each other).
    foreach (uniqueJobs() as $class) {
        $job = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $timeout = (new ReflectionClass($class))->getProperty('timeout');

        if (! $timeout->isInitialized($job)) {
            continue;
        }

        expect($job->uniqueFor())->toBeGreaterThan(
            (int) $job->timeout,
            "{$class} releases its unique lock after {$job->uniqueFor()}s but may run for "
            ."{$job->timeout}s, so it can be dispatched again alongside itself.",
        );
    }
});
