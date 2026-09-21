<?php

use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Setup\Components\DatabaseComponent;
use Illuminate\Support\Facades\Process;

/**
 * What the setup page is allowed to cost.
 *
 * Every engine answers "which version are you?" by spawning its own client
 * under sudo, and — when it stays silent — "are you installed?" by asking the
 * package manager. Neither is free, and `DatabaseComponent` asked three times:
 * `installed()`, `detail()` and `options()` each took the full capability list.
 * One render of the setup page ran **24 commands** on a four-engine box.
 *
 * Half of those were `dpkg-query`, which the page never reads: it shows
 * `running` and `version`, and `installed` exists for the engine list in the
 * API. So the page paid for an answer it then discarded, four times over,
 * three times per render.
 *
 * The numbers here are the point. A regression in this area is invisible — the
 * screen stays correct and merely gets slower, so nothing fails and nobody
 * notices. Counting commands is the only thing that notices.
 */

/**
 * A box where no engine answers.
 *
 * This is the state that costs the most, and the ordinary state of a fresh
 * server: every version probe fails, so nothing short-circuits and every
 * package-manager question gets asked. A fake where the engines all answer
 * hides exactly the work this change removes — it was written that way first,
 * and the sabotage pass caught it.
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
        'package' => collect($seen)
            ->filter(fn ($p) => in_array('dpkg-query', $p->command, true) || in_array('which', $p->command, true))
            ->count(),
    ];
}

it('probes each engine once, and asks the package manager nothing, for a whole setup page render', function () {
    $seen = [];
    fakeSilentBox($seen);

    $component = app(DatabaseComponent::class);
    $component->installed();
    $component->detail();
    $component->options();

    // One version probe per engine and nothing else. `package => 0` is the
    // half of the saving that survives on a box where engines are answering
    // and `installed()` would short-circuit anyway.
    expect(probeCount($seen))->toBe([
        'total' => count(app(DatabaseManager::class)->engineNames()),
        'package' => 0,
    ]);
});

it('probes each engine once however many times one manager is asked', function () {
    $seen = [];
    fakeSilentBox($seen);

    $manager = app(DatabaseManager::class);
    $manager->capabilities();
    $manager->capabilities();
    $manager->detectedVersions();

    // Three questions, one round of probes: a version probe and a package
    // question per engine, not three of each.
    $engines = count($manager->engineNames());

    expect(probeCount($seen))->toBe(['total' => $engines * 2, 'package' => $engines]);
});

/**
 * The rule that makes the memo safe, pinned so it cannot be undone quietly.
 *
 * The result is remembered for the lifetime of one DatabaseManager, which is
 * only ever short because nothing binds the class as a singleton. Bind it as
 * one — a single `$this->app->singleton(...)` — and the queue worker, which
 * keeps its container between jobs, would hold a version it detected hours ago
 * and report an engine the user has since removed as still installed. That is
 * exactly the failure detect-don't-trust exists to prevent, and it would show
 * up as a wrong screen rather than as an error.
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

    // Mixed on purpose: one engine up, the rest silent. Both arms of
    // `running` have to agree between the two paths, not just the easy one.
    Process::fake(function ($process) use (&$seen) {
        $seen[] = $process;

        if (in_array('dpkg-query', $process->command, true)) {
            return Process::result(output: 'unknown ok not-installed');
        }

        return in_array('mariadb', $process->command, true)
            ? Process::result(output: '11.8.6')
            : Process::result(exitCode: 1);
    });

    // The saving must be free. `options()` stopped reading the capability list
    // and now reads the versions directly, so the two have to agree — engine
    // for engine, in the same order — or the page has quietly changed.
    $capabilities = app(DatabaseManager::class)->capabilities();
    $options = app(DatabaseComponent::class)->options();

    expect(array_column($options, 'value'))->toBe(array_column($capabilities, 'engine'))
        ->and(array_column($options, 'version'))->toBe(array_column($capabilities, 'version'))
        ->and(array_column($options, 'installed'))->toBe(array_column($capabilities, 'running'));
});
