<?php

use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Setup\Components\DatabaseComponent;
use Illuminate\Support\Facades\Process;

/**
 * What the setup page is allowed to cost.
 *
 * Every engine answers "which version are you?" by spawning its own client under sudo and,
 * when silent, "are you installed?" via the package manager. Neither is free, so
 * `DatabaseComponent` must not ask three times (`installed()`, `detail()`, `options()`), and
 * must not run `dpkg-query` for a page that only shows `running` and `version`.
 *
 * Counting commands is the only way to notice a regression here: the screen stays correct and
 * merely gets slower.
 */

/**
 * A box where no engine answers.
 *
 * The costliest state, and the ordinary one on a fresh server: every version probe fails, so
 * nothing short-circuits and every package-manager question is asked. A fake where the
 * engines answer would hide the work being counted.
 */
function fakeSilentBox(array &$seen): void
{
    Process::fake(function ($process) use (&$seen) {
        $seen[] = $process;

        if (in_array('dpkg-query', $process->command, true)) {
            return Process::result(output: 'unknown ok not-installed');
        }

        return Process::result(exitCode: 1);
    });
}

function probeCount(array $seen): array
{
    return [
        'total' => count($seen),
        'package' => collect($seen)->filter(fn ($p) => in_array('dpkg-query', $p->command, true))->count(),
        // `which <client>`: a PATH lookup, not the package manager. MongoDB looks for mongosh
        // before running it (sudo logs an error for a missing binary); that lookup replaces
        // the run, so the total does not grow.
        'lookup' => collect($seen)->filter(fn ($p) => in_array('which', $p->command, true))->count(),
    ];
}

it('probes each engine once, and asks the package manager nothing, for a whole setup page render', function () {
    $seen = [];
    fakeSilentBox($seen);

    $component = app(DatabaseComponent::class);
    $component->installed();
    $component->detail();
    $component->options();

    // One version probe per engine and nothing else. `package => 0` is the saving that
    // survives where engines answer and `installed()` would short-circuit anyway.
    expect(probeCount($seen))->toBe([
        'total' => count(app(DatabaseManager::class)->engineNames()),
        'package' => 0,
        'lookup' => 1,
    ]);
});

it('probes each engine once however many times one manager is asked', function () {
    $seen = [];
    fakeSilentBox($seen);

    $manager = app(DatabaseManager::class);
    $manager->capabilities();
    $manager->capabilities();
    $manager->detectedVersions();

    // Three questions, one round of probes: not three of each per engine.
    $engines = count($manager->engineNames());

    expect(probeCount($seen))->toBe(['total' => $engines * 2, 'package' => $engines, 'lookup' => 1]);
});

/**
 * The rule that makes the memo safe, pinned so it cannot be undone quietly.
 *
 * The result is remembered for the lifetime of one DatabaseManager, which is short only
 * because nothing binds the class as a singleton. As a singleton, the queue worker (which
 * keeps its container between jobs) would hold a stale version and report a removed engine as
 * still installed.
 */
it('resolves a new manager every time, so the memo can never outlive a request', function () {
    expect(app(DatabaseManager::class))->not->toBe(app(DatabaseManager::class));
});

it('re-detects for a manager resolved after the engine changed', function () {
    $seen = [];
    fakeSilentBox($seen);

    app(DatabaseManager::class)->detectedVersions();
    $first = count($seen);

    app(DatabaseManager::class)->detectedVersions();

    expect(count($seen))->toBe($first * 2);
});

it('reports the same engine state through the cheap probe as through the full list', function () {
    $seen = [];

    // Mixed on purpose: one engine up, the rest silent, so both arms of `running` are checked.
    Process::fake(function ($process) use (&$seen) {
        $seen[] = $process;

        if (in_array('dpkg-query', $process->command, true)) {
            return Process::result(output: 'unknown ok not-installed');
        }

        return in_array('mariadb', $process->command, true)
            ? Process::result(output: '11.8.6')
            : Process::result(exitCode: 1);
    });

    // `options()` reads the versions directly instead of the capability list: the two must
    // agree, engine for engine and in the same order.
    $capabilities = app(DatabaseManager::class)->capabilities();
    $options = app(DatabaseComponent::class)->options();

    expect(array_column($options, 'value'))->toBe(array_column($capabilities, 'engine'))
        ->and(array_column($options, 'version'))->toBe(array_column($capabilities, 'version'))
        ->and(array_column($options, 'installed'))->toBe(array_column($capabilities, 'running'));
});
