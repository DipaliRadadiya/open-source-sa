<?php

use App\Jobs\InstallBuildTools;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\BuildTools\BuildToolsManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * The compiler toolchain npm falls back to when a package ships no prebuilt
 * binary for the site's Node version.
 *
 * A server without it does not install those apps slowly — it fails, and the
 * message npm surfaces is about dependency resolution, because the one useful
 * line sits under thousands of peer warnings. n8n died exactly this way on a
 * real box on 2026-09-15.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

/**
 * A box where `which` finds the named binaries and nothing else.
 *
 * @param  array<int, string>  $present
 */
function fakeToolchain(array $present): void
{
    Process::fake(function ($process) use ($present) {
        if (($process->command[0] ?? null) === 'which') {
            return in_array($process->command[1] ?? '', $present, true)
                ? Process::result(output: '/usr/bin/'.$process->command[1])
                : Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });
}

it('reports the toolchain as present only when every binary is there', function () {
    fakeToolchain(BuildToolsManager::BINARIES);

    expect(app(BuildToolsManager::class)->installed())->toBeTrue()
        ->and(app(BuildToolsManager::class)->missing())->toBe([]);
});

it('reports a half-toolchain as missing, because node-gyp needs all of it', function () {
    // The dangerous middle state: `make` present, no compiler. An `any`
    // check would call this server ready and the build would fail later,
    // with a worse message than the one this feature exists to prevent.
    fakeToolchain(['make']);

    $manager = app(BuildToolsManager::class);

    expect($manager->installed())->toBeFalse()
        ->and($manager->missing())->toBe(['cc', 'g++']);
});

it('detects live every time, never from a remembered answer', function () {
    fakeToolchain(BuildToolsManager::BINARIES);
    expect(app(BuildToolsManager::class)->installed())->toBeTrue();

    // Someone removed the packages. detect-don't-trust: the next question gets
    // the new answer, not the old one. A cached yes would send a site install
    // into the wall this feature exists to prevent.
    fakeToolchain([]);
    expect(app(BuildToolsManager::class)->installed())->toBeFalse();
});

it('queues the install and records it before returning', function () {
    Queue::fake();
    fakeToolchain([]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/build-tools/install')
        ->assertStatus(202);

    Queue::assertPushed(InstallBuildTools::class);

    // The row is written before dispatch, not inside the job: otherwise there
    // is a window between the 202 and a worker picking it up where the install
    // exists and nothing can see it.
    expect(app(InstallTracker::class)->current(InstallBuildTools::RUNTIME, InstallBuildTools::VERSION))
        ->not->toBeNull();
});

it('refuses to install a toolchain that is already there', function () {
    Queue::fake();
    fakeToolchain(BuildToolsManager::BINARIES);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/build-tools/install')
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

/**
 * apt taking a lock is the concrete reason this job is unique. Two installs at
 * once means the second waits and then fails, and the user reads an error that
 * has nothing to do with their server.
 */
it('is a unique job, so two requests cannot run apt against itself', function () {
    expect(app(InstallBuildTools::class))->toBeInstanceOf(ShouldBeUnique::class);
});

it('denies installing without manage permission, and allows reading with view', function () {
    fakeToolchain([]);

    $viewer = User::factory()->create();
    grantPermission($viewer, 'node');
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/build-tools')->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/build-tools/install')->assertForbidden();
});

it('shows the setup row only while the toolchain is missing, and names the gap', function () {
    fakeToolchain(['make']);

    $row = collect(
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/setup')->assertOk()->json('setup.components')
    )->firstWhere('key', 'build_tools');

    expect($row)->not->toBeNull()
        ->and($row['state'])->not->toBe('installed')
        // The detail names what is absent rather than asserting the whole
        // toolchain is gone — a box with make and no compiler is a different
        // problem from a bare one.
        ->and($row['detail'])->toBe('cc, g++');
});

/**
 * The failure message used to tell users an LTS Node version would avoid
 * compiling. It was measured false on 2026-09-15: Node 22 is LTS and ships no
 * isolated-vm prebuild — only 24 and 26 do. Advice that works by luck reads
 * exactly like advice that works.
 */
it('no longer promises that an LTS Node version avoids compiling', function () {
    foreach (['en', 'de', 'es', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $message = __('application.failure_reason.no_build_tools', [], $locale);

        expect($message)->not->toBe('application.failure_reason.no_build_tools')
            ->and($message)->not->toMatch('/LTS|long-term|長期/u');
    }
});
