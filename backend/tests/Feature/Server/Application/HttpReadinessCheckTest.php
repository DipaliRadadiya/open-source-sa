<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\HttpReadinessCheck;
use Illuminate\Support\Facades\Process;

/**
 * An app can pass `systemctl is-active` and still answer every request with a 500
 * (e.g. NodeBB with uncompiled assets), so provisioning's last step asks it for a page.
 */
beforeEach(function () {
    // No waiting: the delay is for a real cold boot.
    config(['server.applications.readiness.delay' => 0]);

    $this->systemUser = SystemUser::create([
        'username' => 'readyuser', 'home_path' => '/home/readyuser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function readinessApp(int $port = 4567): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => 'Forum', 'slug' => 'forum', 'domain' => 'forum.test',
        'site_type' => 'nodebb', 'serving_profile' => 'node',
        'status' => 'provisioning', 'node_version' => '22', 'app_port' => $port,
    ]);
}

/** Every curl in this file answers with one status code. */
function answersWith(string $code): void
{
    Process::fake(fn () => Process::result(output: $code));
}

it('fails provisioning when the application answers 500 on every request', function () {
    // NodeBB up, templates missing, 500 on `/`.
    answersWith('500');

    try {
        app(HttpReadinessCheck::class)->verify(readinessApp());

        test()->fail('A site answering 500 on every request must not provision as Active.');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('verify_serving')
            ->and($e->reason)->toBe('serving_error');
    }
});

it('does not retry a 5xx, because it will still be broken in two seconds', function () {
    // Counts curl probes only, not the journal capture that follows a failure.
    $probes = 0;
    Process::fake(function ($process) use (&$probes) {
        if (str_contains(implode(' ', (array) $process->command), 'curl')) {
            $probes++;
        }

        return Process::result(output: '500');
    });

    try {
        app(HttpReadinessCheck::class)->verify(readinessApp());
    } catch (ProvisioningFailedException) {
        // expected
    }

    // The retry loop waits for a boot, not for a bug.
    expect($probes)->toBe(1);
});

it('accepts a redirect to a login page', function () {
    // A working forum that sends anonymous visitors to /login is not broken.
    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '302');
    });

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // verify() returned without throwing and asked once: a healthy answer is not retried.
    expect($calls)->toBe(1);
});

it('accepts an application that refuses anonymous requests', function () {
    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '401');
    });

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // verify() returned without throwing and asked once: a healthy answer is not retried.
    expect($calls)->toBe(1);
});

it('accepts an application with no route at the root', function () {
    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '404');
    });

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // verify() returned without throwing and asked once: a healthy answer is not retried.
    expect($calls)->toBe(1);
});

it('waits for a slow boot before giving up', function () {
    config(['server.applications.readiness.attempts' => 4]);

    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        // Connection refused twice — curl writes 000 — then the forum is up.
        return Process::result(output: $calls < 3 ? '000' : '200');
    });

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // A refused connection right after `systemctl start` says nothing: the retry loop's case.
    expect($calls)->toBe(3);
});

it('reports an application that never answers, distinctly from one that errors', function () {
    config(['server.applications.readiness.attempts' => 2]);
    answersWith('000');

    try {
        app(HttpReadinessCheck::class)->verify(readinessApp());

        test()->fail('An application that never answers should fail provisioning.');
    } catch (ProvisioningFailedException $e) {
        // "Broken" and "not there" send the user to look in different places.
        expect($e->reason)->toBe('not_answering');
    }
});

it('skips an application that has no port of its own', function () {
    // A PHP site is served by the web server: nothing to probe, and probing port 0 would fail it.
    Process::fake(fn () => Process::result(output: '500'));

    $php = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'status' => 'provisioning', 'php_version' => '8.4',
    ]);

    app(HttpReadinessCheck::class)->verify($php);

    Process::assertNothingRan();
});

it('captures the application log beside the failure and points the reference at it', function () {
    // A status code says the site is broken, not why; the application's own log does.
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $command = implode(' ', (array) $process->command);
        $commands->push($command);

        return str_contains($command, 'journalctl')
            ? Process::result(output: 'Failed to lookup view "500" in views directory "/home/x/build/public/templates"')
            : Process::result(output: '500');
    });

    try {
        app(HttpReadinessCheck::class)->verify(readinessApp());

        test()->fail('A 5xx must fail provisioning.');
    } catch (ProvisioningFailedException $e) {
        // The reference must point at the entry that explains, not the probe that noticed.
        expect($e->reference)->not->toBeEmpty();
    }

    expect($commands->contains(fn (string $c) => str_contains($c, 'journalctl')))->toBeTrue();
});

it('does not let a broken journal hide the real failure', function () {
    // A failing diagnostic must not replace "this site answers 500" with a journalctl error.
    Process::fake(function ($process) {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, 'journalctl')
            ? Process::result(exitCode: 1, errorOutput: 'No journal files were found.')
            : Process::result(output: '500');
    });

    try {
        app(HttpReadinessCheck::class)->verify(readinessApp());

        test()->fail('A 5xx must fail provisioning even when the journal cannot be read.');
    } catch (ProvisioningFailedException $e) {
        expect($e->step)->toBe('verify_serving')
            ->and($e->reason)->toBe('serving_error');
    }
});

it('skips, without waiting, when no status was measured at all', function () {
    // `--write-out %{http_code}` always prints something (`000` when curl cannot connect), so
    // empty output means the probe never happened. Failing on it would report a healthy site as
    // broken from a measurement never taken.
    config(['server.applications.readiness.delay' => 5, 'server.applications.readiness.attempts' => 15]);

    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '');
    });

    $started = microtime(true);

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // Returned immediately: asked once, slept none of the 70 seconds the config above allows.
    expect($calls)->toBe(1)
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('has both reasons and the step title translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        foreach (['serving_error', 'not_answering'] as $reason) {
            $line = __("application.failure_reason.{$reason}", [], $locale);

            expect($line)->not->toBe("application.failure_reason.{$reason}")
                ->and($line)->not->toBeEmpty();
        }

        $step = __('application.steps.verify_serving', [], $locale);

        expect($step)->not->toBe('application.steps.verify_serving');
    }
});
