<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\HttpReadinessCheck;
use Illuminate\Support\Facades\Process;

/**
 * A NodeBB whose assets never compiled runs perfectly.
 *
 * It accepts connections, stays up and satisfies `systemctl is-active` — and
 * answers every request with `500 Internal server error. Failed to lookup
 * view!`. The panel reported that site as **Active**, because by every check
 * provisioning had, it was: the strongest thing it asked was whether the
 * process was running, which is a narrower question than anyone reading it
 * assumed.
 *
 * So the last step of provisioning now asks the application for a page.
 */
beforeEach(function () {
    // No waiting: the delay exists for a real cold boot, and a test that
    // sleeps 30 seconds to prove a retry loop is a test nobody runs.
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
    // The reported bug, exactly: NodeBB up, templates missing, 500 on `/`.
    // Before this check the install finished and the site was called Active.
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
    // Counts probes only. The journal capture that follows a failure is a
    // different command with a different job, and counting it here would make
    // this test about the wrong thing.
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

    // Retrying an application that is answering "I am broken" only delays the
    // report by half a minute — the waiting is for a boot, not for a bug.
    expect($probes)->toBe(1);
});

it('accepts a redirect to a login page', function () {
    // A working forum that sends anonymous visitors to /login is not broken.
    // Requiring 200 would fail installs that are completely fine.
    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '302');
    });

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // Reached: verify() returned rather than throwing, and it asked once —
    // a healthy answer is not retried.
    expect($calls)->toBe(1);
});

it('accepts an application that refuses anonymous requests', function () {
    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '401');
    });

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // Reached: verify() returned rather than throwing, and it asked once —
    // a healthy answer is not retried.
    expect($calls)->toBe(1);
});

it('accepts an application with no route at the root', function () {
    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '404');
    });

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // Reached: verify() returned rather than throwing, and it asked once —
    // a healthy answer is not retried.
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

    // A refused connection a second after `systemctl start` says nothing.
    // This is the case the retry loop exists for.
    expect($calls)->toBe(3);
});

it('reports an application that never answers, distinctly from one that errors', function () {
    config(['server.applications.readiness.attempts' => 2]);
    answersWith('000');

    try {
        app(HttpReadinessCheck::class)->verify(readinessApp());

        test()->fail('An application that never answers should fail provisioning.');
    } catch (ProvisioningFailedException $e) {
        // Two different problems, two different reasons: "it is broken" and
        // "it is not there" send the user to look in different places.
        expect($e->reason)->toBe('not_answering');
    }
});

it('skips an application that has no port of its own', function () {
    // A PHP site is served by the web server and has nothing to probe. Silence
    // rather than a failure — asking it for a page on port 0 would fail every
    // PHP install in the catalog.
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
    // The probe records a status code, which says the site is broken without
    // saying why. NodeBB's own log names the view it could not find and the
    // directory it searched — the whole answer, and the thing that could not
    // be seen from here for a whole day of guessing.
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
        // The reference has to point at the entry that *explains*, not the one
        // that noticed — quoting the probe hands the user a log holding the
        // number 500 and nothing else.
        expect($e->reference)->not->toBeEmpty();
    }

    expect($commands->contains(fn (string $c) => str_contains($c, 'journalctl')))->toBeTrue();
});

it('does not let a broken journal hide the real failure', function () {
    // A diagnostic that turns "this site answers 500" into an error about
    // journalctl has made the problem harder to see.
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
    // `--write-out %{http_code}` always prints something — `000` when curl
    // cannot connect — so empty output does not mean "no answer", it means the
    // probe never happened.
    //
    // Treating that as a failure would report a healthy site as broken from a
    // measurement never taken, which is the same mistake one level down that
    // this class exists to stop. It also made every other provisioning test in
    // the suite retry fifteen times with a two-second sleep, which is how this
    // was found: the full run stopped finishing.
    config(['server.applications.readiness.delay' => 5, 'server.applications.readiness.attempts' => 15]);

    $calls = 0;
    Process::fake(function () use (&$calls) {
        $calls++;

        return Process::result(output: '');
    });

    $started = microtime(true);

    app(HttpReadinessCheck::class)->verify(readinessApp());

    // Returned immediately: asked once, slept none of the 70 seconds the
    // configuration above would otherwise have cost.
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
