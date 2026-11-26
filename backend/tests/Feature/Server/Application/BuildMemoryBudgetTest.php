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
 * A build killed by the OOM killer leaves SIGKILL, no stderr, exit 137 and an empty log.
 *
 * Two halves: cap the V8 heap so the build survives, and read the exit status so the panel can
 * say why when it does not.
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
    // 1200 MiB available → 75% → 900 MiB. Sizing from the 2 GB total would allow a heap larger
    // than the free memory, which is what causes the kill.
    fakeMeminfo(1_228_800);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBe(900);
});

it('offers the cap as a NODE_OPTIONS environment pair', function () {
    fakeMeminfo(1_228_800);

    expect(app(BuildMemoryBudget::class)->nodeOptions())
        ->toBe(['NODE_OPTIONS' => '--max-old-space-size=900']);
});

it('does not cap a server with plenty of memory', function () {
    // 32 GB available: a ceiling far past what the build wants is no ceiling, so leave V8's default.
    fakeMeminfo(33_554_432);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBeNull()
        ->and(app(BuildMemoryBudget::class)->nodeOptions())->toBe([]);
});

it('still sets a floor on a very small box', function () {
    // 256 MB available → 75% is 192 MB, below the floor. Keep the cap at the floor: small boxes
    // are where a silent SIGKILL hurts most.
    fakeMeminfo(262_144);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBe(512);
});

it('sets no cap when it cannot read how much memory there is', function () {
    // A cap derived from a number we failed to read is worse than no cap.
    config(['server.proc_dir' => sys_get_temp_dir().'/panel-does-not-exist-'.bin2hex(random_bytes(4))]);

    expect(app(BuildMemoryBudget::class)->heapMegabytes())->toBeNull()
        ->and(app(BuildMemoryBudget::class)->nodeOptions())->toBe([]);
});

it('names an out-of-memory kill, which is the failure with an empty log', function () {
    Process::fake();

    // 137 = 128 + SIGKILL, no output: the shape the OOM killer leaves behind.
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

    // Killed, but it said something first: its own words beat an invented category.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-124',
        result: Process::result(output: '', errorOutput: 'ENOSPC: no space left on device', exitCode: 137),
    );

    expect(ProvisioningFailedException::fromResult('build', $result)->reason)->toBeNull();
});

it('leaves an ordinary failure unclassified', function () {
    Process::fake();

    // A wrong reason sends the user to fix something that was never broken; exit 1 says nothing.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-125',
        result: Process::result(output: '', errorOutput: 'build failed', exitCode: 1),
    );

    expect(ProvisioningFailedException::fromResult('build', $result)->reason)->toBeNull();
});

it('records the reason on the application when a step is killed', function () {
    // End to end: the tests above call `fromResult()` directly and would pass even if nothing
    // called it. This runs a real installer step against a killed process.
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

    // Killed: exit 137 and no output, as when the OOM killer picks the build.
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

    // What the API hands the frontend: a named cause, titled in the viewer's locale.
    $payload = (new ApplicationResource($application->fresh()))
        ->toArray(request());

    expect($payload['failed_reason'])->toBe('out_of_memory')
        ->and($payload['failed_reason_title'])->toBe(__('application.failure_reason.out_of_memory'))
        ->and($payload['failed_reason_title'])->not->toContain('application.failure_reason');
});

it('has the reason translated in every locale', function () {
    // `hi` is the locale most often left as an English stub.
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('application.failure_reason.out_of_memory', [], $locale);

        expect($line)->not->toBe('application.failure_reason.out_of_memory')
            ->and($line)->not->toBeEmpty();
    }
});

// A native module that needs compiling on a server with no compiler: thousands of lines of
// output, so the one line that matters has to be named.

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

    // npm writes thousands of peer-dependency warnings around the gyp transcript, then exits 1.
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

    // gyp prefixes real compile errors the same way; naming the toolchain would send someone to
    // install what they already have.
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
    // The cheaper fix: isolated-vm ships prebuilt binaries for LTS ABIs only, not the odd-numbered
    // releases, so "install build tools" alone would push users toward a toolchain they do not need.
    expect(__('application.failure_reason.no_build_tools'))->toContain('Node');
});

it('names an out-of-memory kill that only printed npm warnings first', function () {
    Process::fake();

    // npm prints peer-dependency warnings before any work starts, so a killed `npm install` is
    // never silent.
    $warnings = "npm warn ERESOLVE overriding peer dependency\nnpm warn While resolving: @getzep/zep-cloud@1.0.6\n\nnpm warn   node_modules/@tiptap/pm\n";

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-126',
        result: Process::result(output: '', errorOutput: $warnings, exitCode: 137),
    );

    expect(ProvisioningFailedException::fromResult('install_app', $result)->reason)->toBe('out_of_memory');
});

it('still prefers npm\'s own error to a guess when it printed one', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-127',
        result: Process::result(output: '', errorOutput: "npm warn deprecated x\nnpm error code ENOSPC\n", exitCode: 137),
    );

    expect(ProvisioningFailedException::fromResult('install_app', $result)->reason)->toBeNull();
});

it('names a deploy script that tried to log in to the repository', function (string $stderr) {
    Process::fake();

    // git's own wording for a `git pull` that prompts for credentials.
    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-126',
        result: Process::result(output: '', errorOutput: $stderr, exitCode: 128),
    );

    expect(ProvisioningFailedException::fromResult('script', $result)->reason)->toBe('script_git_auth')
        // Only the user's script lacks the credential; the panel's own fetch failing like this
        // means the repository or its account is unreachable.
        ->and(ProvisioningFailedException::fromResult('fetch', $result)->reason)->toBe('git_repository_unreachable')
        ->and(__('application.failure_reason.script_git_auth'))->toContain('git pull');
})->with([
    "fatal: could not read Username for 'https://github.com': No such device or address",
    "fatal: could not read Password for 'https://user@gitlab.com': terminal prompts disabled",
]);

it('leaves a script failure that only mentions git alone', function () {
    Process::fake();

    $result = new ServerOpsResult(
        ok: false,
        reference: 'ref-127',
        result: Process::result(output: '', errorOutput: "error: Your local changes to the following files would be overwritten by merge:\n\tREADME.md", exitCode: 1),
    );

    expect(ProvisioningFailedException::fromResult('script', $result)->reason)->toBeNull();
});
