<?php

use App\Jobs\InstallDatabaseEngine;
use App\Models\User;
use App\Support\OsRelease;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * An engine whose vendor has not built for this Ubuntu release.
 *
 * The message on a failed install was already right; the problem was that the
 * button was offered at all. Clicking it wrote an apt source and a signing key
 * to the box, waited for apt, and then failed on a package that was never going
 * to be there.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->osRelease = tempnam(sys_get_temp_dir(), 'osrel');
    config(['server.os_release' => $this->osRelease]);
    OsRelease::flush();
});

afterEach(function () {
    @unlink($this->osRelease);
    OsRelease::flush();
});

function runningUbuntu(string $version, string $codename): void
{
    file_put_contents(
        test()->osRelease,
        "NAME=\"Ubuntu\"\nVERSION_ID=\"{$version}\"\nVERSION_CODENAME={$codename}\n",
    );
    OsRelease::flush();
}

function engineRow(string $engine): array
{
    $body = test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->getJson('/api/databases/engines')->json('engines');

    return collect($body)->firstWhere('engine', $engine);
}

it('greys the MongoDB card on Ubuntu 26.04 and says why', function () {
    Process::fake();
    runningUbuntu('26.04', 'resolute');

    $mongodb = engineRow('mongodb');

    expect($mongodb['installable'])->toBeFalse()
        // A stable code to branch on, and a sentence already translated — the
        // same shape a blocked site-type card carries.
        ->and($mongodb['unavailable']['code'])->toBe('os_unsupported')
        ->and($mongodb['unavailable']['reason'])->toContain('Ubuntu 26.04')
        ->and($mongodb['unavailable']['reason'])->toContain('MongoDB');
});

it('leaves MongoDB installable on a release it does publish for', function () {
    Process::fake();
    runningUbuntu('24.04', 'noble');

    $mongodb = engineRow('mongodb');

    expect($mongodb['installable'])->toBeTrue()
        ->and($mongodb['unavailable'])->toBeNull();
});

it('does not block the engines that come from Ubuntu\'s own archive', function () {
    Process::fake();
    runningUbuntu('26.04', 'resolute');

    // MySQL, MariaDB and PostgreSQL are installed from the Ubuntu archive — no
    // third-party repository, so no vendor to be behind on a release.
    foreach (['mysql', 'mariadb', 'postgresql'] as $engine) {
        expect(engineRow($engine)['unavailable'])->toBeNull();
    }
});

it('refuses the install instead of queueing a job that cannot succeed', function () {
    Queue::fake();
    Process::fake();
    runningUbuntu('26.04', 'resolute');

    $response = test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/databases/engines/mongodb')
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Ubuntu 26.04')
        ->and($response->json('message'))->toContain('MongoDB');

    // The whole point: nothing queued, and nothing written to the box.
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('still queues the install on a supported release', function () {
    Queue::fake();
    Process::fake();
    runningUbuntu('24.04', 'noble');

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/databases/engines/mongodb')
        ->assertStatus(202);

    Queue::assertPushed(InstallDatabaseEngine::class);
});

it('does not refuse when it cannot tell which release this is', function () {
    // An unreadable /etc/os-release means the panel does not know. "I cannot
    // tell" must not become "refused" — that would block an engine because we
    // failed to read a file. The install still classifies the real failure as
    // `os_unsupported` if it turns out to be one.
    Queue::fake();
    Process::fake();
    file_put_contents($this->osRelease, '');
    OsRelease::flush();

    expect(engineRow('mongodb')['unavailable'])->toBeNull();

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/databases/engines/mongodb')
        ->assertStatus(202);
});

it('can be overridden per server, because the list goes stale', function () {
    // It is a statement about what a vendor has published today. The day
    // MongoDB ships for resolute this entry is wrong in the direction of
    // refusing something that works, and nobody should have to wait for a panel
    // release to unblock it.
    Queue::fake();
    Process::fake();
    runningUbuntu('26.04', 'resolute');
    config(['server.databases.engines.mongodb.unsupported_codenames' => []]);

    expect(engineRow('mongodb')['installable'])->toBeTrue();

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/databases/engines/mongodb')
        ->assertStatus(202);
});
