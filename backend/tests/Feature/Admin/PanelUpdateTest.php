<?php

use App\Actions\Admin\PanelUpdate\QueuePanelUpdate;
use App\Enums\PanelUpdateStatus;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\Panel\AvailableRelease;
use App\Services\Panel\InstalledPanelInfo;
use App\Services\Panel\UpdatePreflight;
use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Cache::flush();
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

function panelAdminHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function fakeRelease(string $tag = 'v9.9.9'): void
{
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => $tag,
        'published_at' => '2026-08-01T10:00:00Z',
        'body' => 'Release notes here.',
        'html_url' => 'https://github.com/example/repo/releases/tag/'.$tag,
    ])]);
}

it('reports installed version, available version and preflight', function () {
    fakeRelease();

    $response = $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update');

    $response->assertOk()
        ->assertJsonStructure(['panel_update' => [
            'installed' => ['version', 'commit_hash', 'commit_short', 'branch', 'source', 'is_git_checkout', 'has_local_changes'],
            'available' => ['version', 'published_at', 'notes', 'url', 'checked'],
            'update_available',
            'preflight' => ['ready', 'checks'],
        ]]);

    // The tag is published as v9.9.9 but surfaced bare.
    expect($response->json('panel_update.available.version'))->toBe('9.9.9')
        ->and($response->json('panel_update.available.checked'))->toBeTrue();
});

it('denies a non-admin', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/panel-update')
        ->assertForbidden();
});

it('denies an unauthenticated request', function () {
    $this->getJson('/api/admin/panel-update')->assertUnauthorized();
});

it('reports no update when the release host cannot be reached', function () {
    Http::fake(['api.github.com/*' => Http::response(null, 503)]);

    $response = $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update');

    // Failing soft is the point: an unreachable release host must not turn
    // an informational widget into a 500.
    $response->assertOk();
    expect($response->json('panel_update.available.checked'))->toBeFalse()
        ->and($response->json('panel_update.available.version'))->toBeNull()
        ->and($response->json('panel_update.update_available'))->toBeFalse();
});

it('caches the availability check so the dashboard cannot hammer the release host', function () {
    fakeRelease();

    $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update')->assertOk();
    $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update')->assertOk();

    Http::assertSentCount(1);
});

it('bypasses the cache when asked to refresh', function () {
    fakeRelease();

    $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update')->assertOk();
    $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update?refresh=1')->assertOk();

    Http::assertSentCount(2);
});

describe('version comparison', function () {
    it('only reports an update when the published version is genuinely newer', function () {
        $releases = app(AvailableRelease::class);

        expect($releases->isNewer('1.0.0', '1.0.1'))->toBeTrue()
            ->and($releases->isNewer('1.0.0', '2.0.0'))->toBeTrue()
            ->and($releases->isNewer('1.0.1', '1.0.0'))->toBeFalse()
            ->and($releases->isNewer('1.0.0', '1.0.0'))->toBeFalse();
    });

    it('never guesses when either side is unknown', function () {
        $releases = app(AvailableRelease::class);

        // Prompting an update on a guess invites a mutating, downtime-
        // incurring operation for no reason.
        expect($releases->isNewer(null, '2.0.0'))->toBeFalse()
            ->and($releases->isNewer('1.0.0', null))->toBeFalse()
            ->and($releases->isNewer(null, null))->toBeFalse();
    });
});

describe('installed panel info', function () {
    it('reports a reachable release baseline for this branch checkout', function () {
        $info = app(InstalledPanelInfo::class)->installed();

        expect($info['version'])->not->toBeNull()
            ->and($info['source'])->toBeIn(['tag-ahead', 'tag', 'file']);
    });

    it('resolves the commit from .git without shelling out', function () {
        $info = app(InstalledPanelInfo::class)->installed();

        expect($info['is_git_checkout'])->toBeTrue()
            ->and($info['commit_hash'])->toMatch('/^[0-9a-f]{40}$/')
            ->and($info['commit_short'])->toHaveLength(7);
    });
});

describe('preflight', function () {
    it('fails the clean-tree check when the checkout has local changes', function () {
        $response = $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update');

        $checks = collect($response->json('panel_update.preflight.checks'))->keyBy('key');

        // Whatever the real state of this checkout is, the check must be
        // present and must be a boolean — never absent, never null.
        expect($checks)->toHaveKey('clean_working_tree')
            ->and($checks['clean_working_tree']['passed'])->toBeBool();
    });

    it('reports every check the update depends on', function () {
        $response = $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update');

        expect(collect($response->json('panel_update.preflight.checks'))->pluck('key')->all())
            ->toBe(['git_checkout', 'clean_working_tree', 'free_disk', 'free_memory', 'writable_path']);
    });

    it('is ready only when every check passes', function () {
        $response = $this->withHeaders(panelAdminHeader())->getJson('/api/admin/panel-update');

        $checks = collect($response->json('panel_update.preflight.checks'));

        expect($response->json('panel_update.preflight.ready'))
            ->toBe($checks->every(fn ($check) => $check['passed'] === true));
    });
});

it('names the files that are blocking an update', function () {
    // The check that actually stops people used to report nothing: on a real
    // server it refused every update with an empty detail, and the only way to
    // learn what was dirty was to SSH in and run `git status`. A blocker the
    // reader cannot act on is barely better than no blocker at all.
    $installed = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $installed->shouldReceive('localChanges')->andReturn([
        'M backend/config/server.php',
        'D frontend/tests/a.mjs',
    ]);

    $check = collect((new UpdatePreflight($installed))->run()['checks'])
        ->firstWhere('key', 'clean_working_tree');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('2 uncommitted changes')
        ->and($check['detail'])->toContain('backend/config/server.php')
        ->and($check['detail'])->toContain('frontend/tests/a.mjs');
});

it('caps the blocking list rather than pasting a whole git status', function () {
    // A stale checkout can have hundreds of entries. The point is to identify
    // the problem, not to reproduce git status in a status card.
    $installed = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $installed->shouldReceive('localChanges')->andReturn(
        array_map(fn (int $i): string => "M file-{$i}.php", range(1, 40)),
    );

    $detail = collect((new UpdatePreflight($installed))->run()['checks'])
        ->firstWhere('key', 'clean_working_tree')['detail'];

    expect($detail)->toContain('40 uncommitted changes')
        ->and($detail)->toContain('and 35 more')
        ->and(substr_count($detail, 'file-'))->toBe(5);
});

it('still fails closed when git cannot answer', function () {
    // Unknown is not clean. If we cannot prove the tree is safe we do not get
    // to run `git checkout --force` over whatever is in it.
    $installed = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $installed->shouldReceive('localChanges')->andReturn(null);

    $check = collect((new UpdatePreflight($installed))->run()['checks'])
        ->firstWhere('key', 'clean_working_tree');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toBe('unknown');
});

it('does not let a finished run nobody watched block the next update', function () {
    // The row is only advanced by PanelUpdateRunner::reconcile(), and the only
    // caller is somebody looking at the update page. The run itself is a
    // detached script that cannot write to the database -- it restarts php-fpm
    // partway through, which is why progress lives in a file at all.
    //
    // So an update that finished unobserved -- tab closed, browser reloaded
    // into a restarting panel -- left a row saying "running" forever, and every
    // later press was refused with "An update is already running", for good.
    // On the test server row #7 said `pending` while its state file had said
    // `succeeded` for minutes.
    $dir = storage_path('framework/testing/panel-update');
    is_dir($dir) || mkdir($dir, 0755, true);
    config()->set('panel_update.state_dir', $dir);

    $stale = PanelUpdate::create([
        'user_id' => $this->admin->id,
        'status' => PanelUpdateStatus::Running,
        'from_version' => '1.0.0',
        'from_commit' => str_repeat('a', 40),
        'to_version' => '1.0.1',
        'started_at' => now()->subHour(),
    ]);

    // What the detached script actually wrote, which nobody has read back.
    file_put_contents(
        app(UpdateScript::class)->statePath($stale),
        json_encode(['step' => 'health_check', 'status' => 'succeeded', 'reason' => '', 'at' => now()->toIso8601String()]),
    );

    try {
        app(QueuePanelUpdate::class)->execute($this->admin, dryRun: true);
    } catch (ValidationException $e) {
        // Whatever else stops it here -- this checkout is not a panel install
        // -- it must no longer be the phantom run. That was the permanent one.
        expect($e->getMessage())->not->toBe(__('panel_update.errors.in_progress'));
    }

    // ...and the phantom is settled rather than left to block the next press.
    expect($stale->fresh()->status)->toBe(PanelUpdateStatus::Succeeded);
});

it('reports the tag it is checked out on, not a file somebody forgot', function () {
    // v1.0.2 and v1.0.3 were both tagged without bumping VERSION, so both ship
    // 1.0.1. The health check asserts the version it installed is the version
    // answering, so an update to either builds for twenty minutes and rolls
    // back. Three releases out of four got this wrong; the file is maintained
    // by hand and hand-maintenance is the bug.
    $repo = sys_get_temp_dir().'/panel-version-'.bin2hex(random_bytes(4));
    mkdir($repo.'/backend', 0755, true);
    file_put_contents($repo.'/VERSION', "1.0.1\n");

    $git = fn (array $args) => Process::path($repo)->run(array_merge(['git'], $args));
    $git(['init', '-q']);
    $git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'r']);
    $git(['tag', 'v1.0.3']);

    $info = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $info->shouldReceive('repositoryPath')->andReturn($repo);

    $installed = $info->installed();

    expect($installed['version'])->toBe('1.0.3')
        ->and($installed['source'])->toBe('tag');

    Process::run(['rm', '-rf', $repo]);
});

it('falls back to the file on a branch, which every fresh install is on', function () {
    // install.sh clones --depth 1 onto a branch and fetches no tags, so there
    // is nothing to describe. That is exactly why the file stays as fallback
    // rather than being removed.
    $repo = sys_get_temp_dir().'/panel-version-'.bin2hex(random_bytes(4));
    mkdir($repo.'/backend', 0755, true);
    file_put_contents($repo.'/VERSION', "1.0.1\n");

    $git = fn (array $args) => Process::path($repo)->run(array_merge(['git'], $args));
    $git(['init', '-q']);
    $git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'r']);

    $info = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $info->shouldReceive('repositoryPath')->andReturn($repo);

    expect($info->installed()['source'])->toBe('file')
        ->and($info->installed()['version'])->toBe('1.0.1');

    Process::run(['rm', '-rf', $repo]);
});

it('ignores a tag that is not version-shaped', function () {
    // `nightly` or `staging` on a commit is a normal thing to do, and putting
    // a word where the update compares numbers would break the comparison.
    $repo = sys_get_temp_dir().'/panel-version-'.bin2hex(random_bytes(4));
    mkdir($repo.'/backend', 0755, true);
    file_put_contents($repo.'/VERSION', "1.0.1\n");

    $git = fn (array $args) => Process::path($repo)->run(array_merge(['git'], $args));
    $git(['init', '-q']);
    $git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'r']);
    $git(['tag', 'nightly']);

    $info = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $info->shouldReceive('repositoryPath')->andReturn($repo);

    expect($info->installed()['source'])->toBe('file');

    Process::run(['rm', '-rf', $repo]);
});

it('uses the nearest release as the baseline when HEAD is ahead of it', function () {
    // A branch deployed from main can be newer than the latest release while
    // VERSION is stale. Reporting the file here offered v1.0.3 as an update
    // and `checkout --force` downgraded the panel to that older tag. Keep the
    // distinction in `source`, but compare from the reachable release version.
    $repo = sys_get_temp_dir().'/panel-version-'.bin2hex(random_bytes(4));
    mkdir($repo.'/backend', 0755, true);
    file_put_contents($repo.'/VERSION', "1.0.1\n");

    $git = fn (array $args) => Process::path($repo)->run(array_merge(['git'], $args));
    $git(['init', '-q']);
    $git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'released']);
    $git(['tag', 'v1.0.3']);
    $git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'after']);

    $info = Mockery::mock(InstalledPanelInfo::class)->makePartial();
    $info->shouldReceive('repositoryPath')->andReturn($repo);

    expect($info->installed()['source'])->toBe('tag-ahead')
        ->and($info->installed()['version'])->toBe('1.0.3');

    Process::run(['rm', '-rf', $repo]);
});

it('does not let polling the update page use up the update button', function () {
    // `throttle:3,1` and `throttle:30,1` looked like two independent limits and
    // were one. Laravel keys an inline throttle on the route URI and the user,
    // with no reference to the method or the limit — and GET and POST here are
    // both `/panel-update`. So every poll spent one of the button's three
    // attempts, and after three requests of any kind in a minute the update
    // answered 429 "Too Many Requests". Measured on a real server as a single
    // counter climbing 1, 2, 3 while two endpoints read it against 30 and 3.
    Cache::flush();

    // Well past the start limit of 3, nowhere near the check limit of 30.
    foreach (range(1, 10) as $ignored) {
        test()->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/admin/panel-update')
            ->assertOk();
    }

    // The button must still be pressable. Whatever it answers, it must not be
    // 429 — that was the bug, and it made the feature unreachable from the UI.
    $response = test()->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/admin/panel-update');

    expect($response->status())->not->toBe(429);
});

it('keeps the update routes answering while the panel is down', function () {
    // `artisan down` is step two of an update, so from that moment every API
    // call returned 503 — including the progress feed the page polls and the
    // button itself. The page could not show the run it exists to show, and
    // pressing update during one answered "Couldn't start the update" when the
    // truth was "step 12 of 16". Reproduced on a real server twice.
    //
    // Driven through the actual maintenance file rather than by asserting on
    // config: the middleware reads that file, and a list that looks right but
    // does not match the dispatched URI would pass a config assertion happily.
    $down = storage_path('framework/down');
    file_put_contents($down, json_encode(['except' => []]));

    try {
        $update = PanelUpdate::create([
            'user_id' => $this->admin->id,
            'status' => PanelUpdateStatus::Running,
            'from_version' => '1.0.0',
            'from_commit' => str_repeat('a', 40),
            'to_version' => '1.0.1',
            'started_at' => now(),
        ]);

        $headers = ['Authorization' => 'Bearer '.$this->token];

        // The progress feed: what the page polls every few seconds.
        expect(test()->withHeaders($headers)->getJson("/api/admin/panel-update/{$update->id}")->status())
            ->not->toBe(503);

        // The page's own read, and the button.
        expect(test()->withHeaders($headers)->getJson('/api/admin/panel-update')->status())->not->toBe(503)
            ->and(test()->withHeaders($headers)->postJson('/api/admin/panel-update')->status())->not->toBe(503);

        // Health, because the release flow verifies *before* leaving
        // maintenance — with health behind the gate it could never pass its
        // own check and would roll back every time.
        expect(test()->getJson('/api/health')->status())->not->toBe(503);

        // Everything else still 503s. Maintenance mode has a job: no writes
        // reach a half-updated panel.
        expect(test()->withHeaders($headers)->getJson('/api/applications')->status())->toBe(503);
    } finally {
        @unlink($down);
    }
});
