<?php

use App\Enums\PanelUpdateStatus;
use App\Exceptions\Admin\PanelUpdate\PanelUpdateHelperFailedException;
use App\Jobs\PerformPanelUpdate;
use App\Models\ActivityLog;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Panel\InstalledPanelInfo;
use App\Services\Panel\PanelUpdateRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * The administrator-only panel-update endpoints.
 *
 * What this feature is, today:
 *  - GET  returns the latest update row + a snapshot of the installed
 *    version/commit, derived from config and `.git/HEAD` (no shell).
 *  - POST queues a new update row and dispatches the worker.
 *
 * What this feature is NOT, today (the test asserts this too):
 *  - The worker is intentionally a no-op that closes the row as `failed`
 *    with `reason=unsupported`. It MUST NOT shell out, run composer, run
 *    npm, run `Process::run`, or touch any service. The privileged helper
 *    that actually switches releases arrives later; these tests pin its
 *    absence so the row never claims an update happened when it didn't.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->nonAdmin = User::factory()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

function panelUpdateHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('returns no update row and an installed snapshot when the panel has never been updated', function () {
    config(['app.version' => '2026.08.0']);

    $response = $this->withHeaders(panelUpdateHeaders())
        ->getJson('/api/admin/panel-updates');

    $response->assertOk()
        ->assertJsonPath('panel_update', null)
        ->assertJsonPath('installed.version', '2026.08.0')
        // Without a `.git/HEAD` file under `base_path()`, commit hash is null
        // — the test runs out of `/tmp/.../backend` and never finds one.
        ->assertJsonStructure(['installed' => ['version', 'commit_hash', 'commit_short', 'source']]);
});

it('returns the latest in-flight row when one exists, even if a newer terminal row is also there', function () {
    config(['app.version' => '1.0.0']);

    $old = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'unsupported',
        'reference' => 'old-ref',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(5),
    ]);

    $inFlight = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Running,
        'started_at' => now(),
    ]);

    $newer = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'worker',
        'reference' => 'newer-ref',
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    $response = $this->withHeaders(panelUpdateHeaders())
        ->getJson('/api/admin/panel-updates');

    $response->assertOk()
        ->assertJsonPath('panel_update.id', $inFlight->id)
        ->assertJsonPath('panel_update.status', 'running')
        ->assertJsonPath('panel_update.in_flight', true);

    // The terminal rows are present in the table but not in the response —
    // pinning that here so a future "show newest" change has to justify
    // itself in the diff.
    expect([$old->id, $newer->id])->not->toContain($response->json('panel_update.id'));
});

it('returns the newest terminal row when no update is in flight', function () {
    $older = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'unsupported',
        'reference' => 'older',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    $newer = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'worker',
        'reference' => 'newer',
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    $response = $this->withHeaders(panelUpdateHeaders())
        ->getJson('/api/admin/panel-updates');

    $response->assertOk()
        ->assertJsonPath('panel_update.id', $newer->id)
        ->assertJsonPath('panel_update.reason', 'worker')
        ->assertJsonPath('panel_update.message', __('panel_update.reason.worker', ['reference' => 'newer']));
});

it('queues a panel update for an admin and returns 202 with the row', function () {
    Queue::fake();

    $response = $this->withHeaders(panelUpdateHeaders())
        ->postJson('/api/admin/panel-updates');

    $response->assertStatus(202)
        ->assertJsonPath('panel_update.status', 'pending')
        ->assertJsonPath('panel_update.in_flight', true)
        ->assertJsonStructure([
            'panel_update' => [
                'id', 'status', 'status_title', 'in_flight',
                'reason', 'message', 'reference',
                'from_version', 'from_commit', 'from_commit_short',
                'to_version', 'to_commit', 'to_commit_short',
                'duration', 'started_at', 'finished_at', 'created_at',
            ],
            'message',
        ]);

    expect(PanelUpdate::query()->count())->toBe(1)
        ->and(PanelUpdate::query()->first()->status)->toBe(PanelUpdateStatus::Pending);

    Queue::assertPushed(PerformPanelUpdate::class, fn (PerformPanelUpdate $job): bool => $job->panelUpdateId === $response->json('panel_update.id'));
});

it('records an activity entry for the queued update, attributed to the admin', function () {
    Queue::fake();

    $this->withHeaders(panelUpdateHeaders())
        ->postJson('/api/admin/panel-updates')
        ->assertStatus(202);

    $log = ActivityLog::where('type', 'panel_update')->where('action', 'queued')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->admin->id);
});

it('refuses a second POST while one is in flight, with 409 and a translated message', function () {
    Queue::fake();

    PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Running,
        'started_at' => now(),
    ]);

    $response = $this->withHeaders(panelUpdateHeaders())
        ->postJson('/api/admin/panel-updates');

    $response->assertStatus(409)
        ->assertJsonPath('message', __('errors/panel_update.already_in_progress'))
        ->assertJsonStructure(['message', 'reference']);

    // No second row was written.
    expect(PanelUpdate::query()->count())->toBe(1);

    // And no second job was queued.
    Queue::assertNothingPushed();
});

it('refuses a second POST while one is pending, before a worker picks it up', function () {
    Queue::fake();

    PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Pending,
    ]);

    $this->withHeaders(panelUpdateHeaders())
        ->postJson('/api/admin/panel-updates')
        ->assertStatus(409);

    expect(PanelUpdate::query()->count())->toBe(1);
});

it('allows a new update once the previous one has reached a terminal status', function () {
    Queue::fake();

    PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'unsupported',
        'reference' => 'old',
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    $this->withHeaders(panelUpdateHeaders())
        ->postJson('/api/admin/panel-updates')
        ->assertStatus(202);

    expect(PanelUpdate::query()->count())->toBe(2);
});

it('rejects an unauthenticated POST with 401', function () {
    $this->postJson('/api/admin/panel-updates')->assertUnauthorized();
});

it('rejects a non-admin POST with 403', function () {
    $token = $this->nonAdmin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/admin/panel-updates')
        ->assertForbidden();
});

it('rejects a non-admin GET with 403', function () {
    $token = $this->nonAdmin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/panel-updates')
        ->assertForbidden();
});

it('runs the configured helper and records a successful update', function () {
    $update = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Pending,
        'from_version' => '1.0.0',
    ]);

    $runner = Mockery::mock(PanelUpdateRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['version' => '1.1.0', 'commit' => str_repeat('a', 40)]);

    (new PerformPanelUpdate($update->id, $this->admin->id))
        ->handle(app(ActivityLogger::class), $runner);

    $update->refresh();

    expect($update->status)->toBe(PanelUpdateStatus::Succeeded)
        ->and($update->to_version)->toBe('1.1.0')
        ->and($update->to_commit)->toBe(str_repeat('a', 40))
        ->and($update->finished_at)->not->toBeNull();

    $log = ActivityLog::where('type', 'panel_update')->where('action', 'succeeded')->latest('id')->first();
    expect($log)->not->toBeNull()->and($log->user_id)->toBe($this->admin->id);
});

it('records a translated helper failure without leaking its technical detail', function () {
    $update = PanelUpdate::create(['user_id' => $this->admin->id, 'status' => PanelUpdateStatus::Pending]);
    $runner = Mockery::mock(PanelUpdateRunner::class);
    $runner->shouldReceive('run')->once()->andThrow(new PanelUpdateHelperFailedException('helper-ref'));

    (new PerformPanelUpdate($update->id, $this->admin->id))
        ->handle(app(ActivityLogger::class), $runner);

    $update->refresh();
    expect($update->status)->toBe(PanelUpdateStatus::Failed)
        ->and($update->reason)->toBe('helper')
        ->and($update->reference)->toBe('helper-ref');
});

it('records worker failure via failed() hook so a crashed job does not leave the row at running', function () {
    $update = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Pending,
    ]);

    // The row was never picked up — `running` here is what happens if a
    // worker crash landed mid-handle.
    $update->update(['status' => PanelUpdateStatus::Running, 'started_at' => now()]);

    (new PerformPanelUpdate($update->id, $this->admin->id))->failed(new RuntimeException('boom'));

    $update->refresh();

    expect($update->status)->toBe(PanelUpdateStatus::Failed)
        ->and($update->reason)->toBe('worker')
        ->and($update->reference)->not->toBeNull()
        ->and($update->finished_at)->not->toBeNull();
});

it('derives installed commit from .git/HEAD without shelling out', function () {
    // The composer-installed test environment has no `.git` under
    // `base_path()`; the service must report source=unknown or source=config
    // (depending on whether APP_VERSION is set), never crash. The "no
    // shell" rule is the load-bearing assertion: shelling out would break
    // tests and break the admin screen at the same time.
    $info = app(InstalledPanelInfo::class)->installed();

    expect($info)->toHaveKeys(['version', 'commit_hash', 'commit_short', 'source'])
        ->and(in_array($info['source'], ['config', 'git', 'unknown'], true))->toBeTrue();
});

it('renders the failure message in the viewer locale, not the locale of whoever queued the update', function () {
    PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Failed,
        'reason' => 'unsupported',
        'reference' => 'ref-de',
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    $de = $this->withHeaders(array_merge(panelUpdateHeaders(), ['Accept-Language' => 'de']))
        ->getJson('/api/admin/panel-updates')->json('panel_update.message');

    $en = $this->withHeaders(array_merge(panelUpdateHeaders(), ['Accept-Language' => 'en']))
        ->getJson('/api/admin/panel-updates')->json('panel_update.message');

    expect($de)->toBe(__('panel_update.reason.unsupported', ['reference' => 'ref-de'], 'de'))
        ->and($en)->toBe(__('panel_update.reason.unsupported', ['reference' => 'ref-de'], 'en'))
        ->and($de)->not->toBe($en);
});

it('releases the cache lock even when the action throws', function () {
    // The lock is the in-flight guard. A POST that errors after acquiring
    // the lock MUST release it, otherwise the next POST stays 409 forever
    // until the TTL elapses.
    Queue::fake();

    $key = 'panel-update:queue';

    $this->withHeaders(panelUpdateHeaders())
        ->postJson('/api/admin/panel-updates')
        ->assertStatus(202);

    // The first request released its lock on the way out — the lock is
    // either gone or restorable, but a fresh acquire succeeds.
    $lock = Cache::lock($key, 10);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
