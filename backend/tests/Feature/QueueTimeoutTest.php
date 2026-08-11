<?php

use App\Jobs\RunBackup;
use App\Services\Server\Applications\ProvisioningBudget;

/**
 * No job may outlive its own reservation.
 *
 * `retry_after` is how long the queue waits before deciding a reserved job is
 * dead and handing it to someone else. A job that runs longer than that is not
 * retried — it is dispatched a second time as a fresh reservation, which
 * `$tries = 1` does nothing about. These jobs change the server: a backup
 * running twice is waste, a restore running twice re-extracts an archive over
 * a site somebody may already be using again.
 *
 * A test for this existed and checked exactly one number — the provisioning
 * budget. `RunBackup` and `RunRestore` declare 3600 against a window of 2400
 * and sailed past it for that reason. This checks every job there is, so the
 * next one to declare a long timeout cannot slip through the same gap.
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

        // Jobs that size themselves from the work they are about to do set
        // this in the constructor, so there is nothing to read here. Those are
        // covered below by asserting the sources they compute from.
        if (! $property->isInitialized($instance)) {
            continue;
        }

        $timeouts[$class] = (int) $instance->timeout;
    }

    return $timeouts;
}

it('finds the jobs it is meant to be checking', function () {
    // Without this the test below passes on an empty list, which is how a
    // guard becomes decoration.
    expect(jobTimeouts())->not->toBeEmpty()
        ->and(array_keys(jobTimeouts()))->toContain(RunBackup::class);
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
        ->and((int) config('server.databases.install_timeout', 900) + 120)->toBeLessThan($retryAfter);
})->with(['database', 'redis', 'beanstalkd']);
