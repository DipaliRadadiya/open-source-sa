<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\BuildMemoryBudget;
use App\Services\Server\Applications\Installers\NodeBbInstaller;
use App\Services\Server\ServerOpsResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The NodeBB build kept failing on a 2 GB server with nothing in its log.
 *
 * Not because the command failed — because it was **killed**. V8 sizes its
 * heap from a compiled-in default rather than from the machine, grows past
 * what the box has, and the OOM killer takes it: SIGKILL, no stderr, exit 137.
 * Every layer above could only say "the build failed", and the reference it
 * offered pointed at an empty log.
 *
 * Two halves, tested here: cap the heap so the build survives, and read the
 * exit status so that when it does not, the panel can say why.
 */
function fakeMeminfo(int $availableKb): void
{
    $dir = sys_get_temp_dir().'/panel-meminfo-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/meminfo', implode("\n", [
        'MemTotal:        2035792 kB',
        "MemAvailable:    {$availableKb} kB",
        'SwapTotal:       2097148 kB',
    ])."\n");

    config(['server.proc_dir' => $dir]);
}

it('sizes the heap from available memory, not total', function () {
    // 1200 MiB available → 75% → 900 MiB. Total is 2 GB in the fixture: sizing
    // from that would authorise a heap larger than the free memory, which is
    // the mistake that produces the kill this exists to prevent.
    fakeMeminfo(1_228_800);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBe(900);
});

it('offers the cap as a NODE_OPTIONS environment pair', function () {
    fakeMeminfo(1_228_800);

    expect(app(BuildMemoryBudget::class)->nodeOptions())
        ->toBe(['NODE_OPTIONS' => '--max-old-space-size=900']);
});

it('does not cap a server with plenty of memory', function () {
    // 32 GB available. A ceiling far past what the build wants is the same as
    // no ceiling, and V8's own defaults are right here — so leave them.
    fakeMeminfo(33_554_432);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBeNull()
        ->and(app(BuildMemoryBudget::class)->nodeOptions())->toBe([]);
});

it('still sets a floor on a very small box', function () {
    // 256 MB available → 75% is 192 MB, below the floor. The cap is kept at
    // the floor rather than dropped: this is exactly the machine where the
    // difference between a readable error and a silent SIGKILL matters most.
    fakeMeminfo(262_144);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBe(512);
});

it('sets no cap when it cannot read how much memory there is', function () {
    // A server the panel did not build. A cap derived from a number we failed
    // to read is worse than no cap.
    config(['server.proc_dir' => sys_get_temp_dir().'/panel-does-not-exist-'.bin2hex(random_bytes(4))]);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBeNull()
        ->and(app(BuildMemoryBudget::class)->nodeOptions())->toBe([]);
});

it('names an out-of-memory kill, which is the failure with an empty log', function () {
    Process::fake();

    // 137 = 128 + SIGKILL. No stdout, no stderr — the shape the OOM killer
    // leaves behind, and the reason the reference alone explained nothing.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-123',
        result: Process::result(output: '', errorOutput: '', exitCode: 137),
    );

    $exception = ProvisioningFailedException::fromResult('build', $result);

    expect($exception->reason)->toBe('out_of_memory')
        ->and($exception->step)->toBe('build')
        ->and($exception->reference)->toBe('ref-123');
});

it('does not claim out of memory when the command explained itself', function () {
    Process::fake();

    // Killed, but it managed to say something first. Its own words are a
    // better explanation than a category invented here.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-124',
        result: Process::result(output: '', errorOutput: 'ENOSPC: no space left on device', exitCode: 137),
    );

    expect(ProvisioningFailedException::fromResult('build', $result)->reason)->toBeNull();
});

it('leaves an ordinary failure unclassified', function () {
    Process::fake();

    // A wrong reason is worse than none — it sends the user to fix something
    // that was never broken. Exit 1 says nothing about why, so neither do we.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-125',
        result: Process::result(output: '', errorOutput: 'build failed', exitCode: 1),
    );

    expect(ProvisioningFailedException::fromResult('build', $result)->reason)->toBeNull();
});

it('records the reason on the application when a step is killed', function () {
    // The end-to-end assertion, and the one that matters.
    //
    // The three tests above exercise `fromResult()` directly, so they pass
    // whether or not anything *calls* it — they proved the classifier worked
    // and would have said nothing if it were wired to nothing. This one runs a
    // real installer step against a killed process and looks at what the user
    // is finally shown, which is the only claim worth making.
    $seeder = new PermissionSeeder;
    $seeder->run();

    $systemUser = SystemUser::create([
        'username' => 'oomuser', 'home_path' => '/home/oomuser',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Forum', 'slug' => 'forum', 'domain' => 'forum.test',
        'site_type' => 'nodebb', 'serving_profile' => 'node',
        'status' => 'provisioning', 'node_version' => '22', 'app_port' => 4567,
    ]);

    // Killed: exit 137, and nothing written anywhere. This is what the panel
    // actually receives when the OOM killer chooses the build.
    Process::fake(fn () => Process::result(output: '', errorOutput: '', exitCode: 137));

    try {
        app(NodeBbInstaller::class)
            ->install($application, $application->documentRoot(), []);

        $this->fail('The killed step should have failed provisioning.');
    } catch (ProvisioningFailedException $e) {
        $application->update([
            'status' => 'failed',
            'failed_step' => $e->step,
            'failed_reason' => $e->reason,
            'reference' => $e->reference,
        ]);
    }

    // What the API hands the frontend: a named cause, titled in the viewer's
    // locale, instead of a step and a reference to a log with nothing in it.
    $payload = (new ApplicationResource($application->fresh()))
        ->toArray(request());

    expect($payload['failed_reason'])->toBe('out_of_memory')
        ->and($payload['failed_reason_title'])->toBe(__('application.failure_reason.out_of_memory'))
        ->and($payload['failed_reason_title'])->not->toContain('application.failure_reason');
});

it('has the reason translated in every locale', function () {
    // No hardcoded English reaches the user. `hi` is checked explicitly
    // because it is the locale most often left as an English stub.
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('application.failure_reason.out_of_memory', [], $locale);

        expect($line)->not->toBe('application.failure_reason.out_of_memory')
            ->and($line)->not->toBeEmpty();
    }
});

/*
 * The second failure this classifier exists for: a native module that needed
 * compiling on a server with no compiler. Unlike the OOM case there IS output
 * -- thousands of lines of it -- which is exactly why the one line that
 * matters has to be named rather than left in the log.
 */

it('names a missing build tool from the gyp transcript', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-200',
        result: Process::result(
            output: '',
            errorOutput: "gyp ERR! build error \ngyp ERR! stack Error: not found: make\ngyp ERR! not ok",
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('install_app', $result)->reason)
        ->toBe('no_build_tools');
});

it('finds it under the npm warnings that buried it in production', function () {
    Process::fake();

    // Shaped like the real failure: npm writes thousands of peer-dependency
    // warnings around the gyp transcript, and exits 1, which says nothing.
    $noise = str_repeat("npm warn ERESOLVE overriding peer dependency\n", 500);

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-201',
        result: Process::result(
            output: '',
            errorOutput: $noise."gyp ERR! stack Error: not found: make\n".$noise,
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('install_app', $result)->reason)
        ->toBe('no_build_tools');
});

it('does not blame the compiler for a compile that failed', function () {
    Process::fake();

    // gyp prefixes real compilation errors the same way. A source file that
    // will not build is a different problem with a different fix, and naming
    // the toolchain would send someone to install what they already have.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-202',
        result: Process::result(
            output: '',
            errorOutput: "gyp ERR! build error\n../src/thing.cc:42:7: error: expected ';'\ngyp ERR! not ok",
            exitCode: 1,
        ),
    );

    expect(ProvisioningFailedException::fromResult('install_app', $result)->reason)->toBeNull();
});

it('has the missing-build-tool reason translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('application.failure_reason.no_build_tools', [], $locale);

        expect($line)->not->toBe('application.failure_reason.no_build_tools')
            ->and($line)->not->toBeEmpty();
    }
});

it('offers the Node version as a fix, not only the compiler', function () {
    // The cheaper fix, and the one that would have avoided this entirely:
    // isolated-vm ships prebuilt binaries for the LTS ABIs and none for the
    // odd-numbered releases between them. A message that only said "install
    // build tools" would push every user toward a 300 MB toolchain they do
    // not need.
    expect(__('application.failure_reason.no_build_tools'))->toContain('Node');
});
