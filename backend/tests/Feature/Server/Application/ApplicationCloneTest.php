<?php

use App\Jobs\RunClone;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\ServerCapability;
use App\Models\SiteClone;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Site Clone: a fully independent duplicate — no ongoing relationship to
 * the source (unlike Staging). What matters here: a source needing a
 * database refuses cleanly when its type has no CloneStrategy, webhook
 * identity is never copied (it has a unique constraint and would create
 * ambiguity), and `provision()`'s installer/process-start steps are
 * skipped so a fresh install never fights the rsync that follows it.
 *
 * Cloning is asynchronous: the request returns 202 with a `SiteClone` record
 * and the work happens in `RunClone` off the queue, because rsyncing a large
 * site outlasts an HTTP request. So these tests assert the handover (202, a
 * pending record) and then run the job, which is the rest of the operation as
 * far as the user is concerned.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

function cloneHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/**
 * Starts a clone through the API and then runs the queued job, returning the
 * refreshed record. The 202 is only a promise; nothing exists until the job
 * has run.
 */
function runClone(Application $source, string $domain, ?string $name = null): SiteClone
{
    $payload = array_filter(['domain' => $domain, 'name' => $name]);

    $response = test()->withHeaders(cloneHeaders())
        ->postJson("/api/applications/{$source->id}/clone", $payload)
        ->assertAccepted()
        ->assertJsonPath('clone.domain', $domain)
        ->assertJsonPath('clone.status', 'pending');

    $clone = SiteClone::findOrFail($response->json('clone.id'));

    app()->call([new RunClone($clone->id, $source->id), 'handle']);

    return $clone->fresh();
}

function fakeCloneServer(bool $testPasses = true): void
{
    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });
}

it('clones a static site (no database needed) generically', function () {
    fakeCloneServer();

    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Docs',
        'slug' => 'docs', 'domain' => 'docs.test', 'site_type' => 'static',
        'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
    ]);

    $record = runClone($source, 'docs-clone.test');

    expect($record->status->value)->toBe('completed')
        ->and($record->target_application_id)->not->toBeNull();

    $clone = Application::find($record->target_application_id);

    expect($clone)->not->toBeNull()
        ->and($clone->cloned_from_application_id)->toBe($source->id)
        ->and($clone->status->value)->toBe('active')
        ->and($clone->webhook_enabled)->toBeFalse()
        // Without a slug the clone provisions into the system user's home,
        // which is where every other clone for that user would land too.
        ->and($clone->slug)->not->toBeNull()
        ->and($clone->slug)->not->toBe($source->slug)
        ->and(ActivityLog::where('type', 'application')->where('action', 'cloned')->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'rsync');
});

it('clones a WordPress site including its database', function () {
    fakeCloneServer();

    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop', 'domain' => 'shop.test', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $database = Database::create(['name' => 'shop_db', 'engine' => 'mysql', 'application_id' => $source->id]);
    DatabaseUser::create(['database_id' => $database->id, 'username' => 'shop_user', 'password' => 'secret', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    $record = runClone($source, 'shop-clone.test');

    expect($record->status->value)->toBe('completed');

    $clone = Application::find($record->target_application_id);

    expect(Database::where('application_id', $clone->id)->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'mysqldump');
    Process::assertRan(fn ($p) => in_array('runuser', $p->command, true) && in_array('search-replace', $p->command, true));
});

it('refuses to clone a database-needing type with no clone recipe', function () {
    fakeCloneServer();

    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Forum',
        'slug' => 'forum', 'domain' => 'forum.test', 'site_type' => 'nodebb',
        'serving_profile' => 'node', 'status' => 'active', 'node_version' => '22', 'app_port' => 4000,
    ]);

    // The refusal moved into the job with the 202: the request can no longer
    // report it, so the record carries the failure instead.
    $record = runClone($source, 'forum-clone.test');

    expect($record->status->value)->toBe('failed')
        ->and($record->target_application_id)->toBeNull()
        ->and($record->finished_at)->not->toBeNull()
        ->and(Application::where('domain', 'forum-clone.test')->exists())->toBeFalse();
});

it('allocates a fresh port for a node clone rather than reusing the source port', function () {
    fakeCloneServer();

    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Status',
        'slug' => 'status', 'domain' => 'status.test', 'site_type' => 'uptimekuma',
        'serving_profile' => 'node', 'status' => 'active', 'node_version' => '22',
        'app_port' => 3001, 'start_command' => 'node server.js',
    ]);

    $record = runClone($source, 'status-clone.test');

    $clone = Application::find($record->target_application_id);

    expect($clone->app_port)->not->toBeNull()->and($clone->app_port)->not->toBe(3001);
});

it('refuses without manage permission', function () {
    fakeCloneServer();
    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Docs',
        'slug' => 'docs', 'domain' => 'docs2.test', 'site_type' => 'static',
        'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
    ]);

    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_clone', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson("/api/applications/{$source->id}/clone", ['domain' => 'docs2-clone.test'])
        ->assertStatus(403);
});

/*
 * The failure path, which had no test — every one above fakes a server where
 * nothing goes wrong. A clone creates its target Application row before it
 * provisions, and `domain` is unique, so a row left behind by a failure meant
 * the same clone could never be retried and the panel listed a site that had
 * never been built.
 */
describe('when a clone fails partway', function () {
    it('leaves no half-made site behind and lets the same clone be retried', function () {
        fakeCloneServer(testPasses: false);

        $source = Application::forceCreate([
            'system_user_id' => $this->systemUser->id,
            'name' => 'Docs',
            'slug' => 'docs', 'domain' => 'docs.test', 'site_type' => 'static',
            'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
        ]);

        $failed = runClone($source, 'docs-clone.test');

        // The SiteClone record is the history and stays; the application does not.
        expect($failed->status->value)->toBe('failed')
            ->and($failed->target_application_id)->toBeNull()
            ->and(Application::where('domain', 'docs-clone.test')->exists())->toBeFalse()
            ->and(Application::where('cloned_from_application_id', $source->id)->exists())->toBeFalse();

        // The whole point: the same domain is free, so the retry works.
        fakeCloneServer();

        $retried = runClone($source, 'docs-clone.test');

        expect($retried->status->value)->toBe('completed')
            ->and(Application::find($retried->target_application_id))->not->toBeNull();
    });

    it('still records why it failed', function () {
        fakeCloneServer(testPasses: false);

        $source = Application::forceCreate([
            'system_user_id' => $this->systemUser->id,
            'name' => 'Docs',
            'slug' => 'docs', 'domain' => 'docs.test', 'site_type' => 'static',
            'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
        ]);

        // Cleaning up must not cost the user the reason — without it the panel
        // can only say the clone failed, which is the half of the message they
        // already know.
        $record = runClone($source, 'docs-clone.test');

        expect($record->reason)->not->toBeEmpty();
    });
});
