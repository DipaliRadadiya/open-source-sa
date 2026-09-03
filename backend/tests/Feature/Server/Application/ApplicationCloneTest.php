<?php

use App\Enums\DomainType;
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

    // No second `handle()`. The suite runs QUEUE_CONNECTION=sync, so
    // `RunClone::dispatch` inside the POST above has already run the whole
    // clone — calling it again ran every clone twice and asserted on the
    // second pass. Harmless only while nothing in the flow was unique per
    // application; the moment the clone started writing its primary domain
    // row, the second run collided with the first on
    // `application_domains.domain` and every clone test failed.
    return $clone->fresh();
}

function fakeCloneServer(bool $testPasses = true): void
{
    Process::fake(function ($process) use ($testPasses) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
            && str_contains((string) $process->input, 'information_schema.schemata')) {
            return Process::result(output: '1');
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
        'name' => 'My Extremely Long Online Shop Application Name',
        'slug' => 'shop', 'domain' => 'shop.test', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $database = Database::create(['name' => 'shop_db', 'engine' => 'mysql', 'application_id' => $source->id]);
    DatabaseUser::create(['database_id' => $database->id, 'username' => 'shop_user', 'password' => 'secret', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    $record = runClone($source, 'shop-clone.test');

    expect($record->status->value)->toBe('completed');

    $clone = Application::find($record->target_application_id);

    $cloneDatabase = Database::where('application_id', $clone->id)->with('users')->first();

    expect($cloneDatabase)->not->toBeNull()
        ->and($cloneDatabase->name)->toStartWith('clone_')
        ->and(strlen($cloneDatabase->name))->toBeLessThanOrEqual(32)
        ->and($cloneDatabase->name)->toMatch('/_[a-z0-9]{6}$/')
        ->and($cloneDatabase->users->first()->username)->toBe($cloneDatabase->name);

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

    // This used to assert the refusal arriving as a *failed record* after a
    // 202 — the request accepted work it already knew it could not do, and
    // the user watched a clone fail for a reason the panel had up front.
    // `app_clone` is no longer among a database-backed type's features, so
    // `CheckPermission` closes the route: 404, nothing created, nothing queued.
    test()->withHeaders(cloneHeaders())
        ->postJson("/api/applications/{$source->id}/clone", ['domain' => 'forum-clone.test'])
        ->assertNotFound();

    expect(SiteClone::count())->toBe(0)
        ->and(Application::where('domain', 'forum-clone.test')->exists())->toBeFalse();
});

it('still refuses inside the job if a strategy-less clone is ever reached directly', function () {
    fakeCloneServer();

    // Defense in depth. The route is closed above, but `CloneManager`'s own
    // guard is what protects a clone dispatched by anything that is not that
    // route — and it is the guard the feature list was derived from, so it
    // stays covered rather than being trusted because the door is shut.
    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Forum Direct',
        'slug' => 'forum-direct', 'domain' => 'forum-direct.test', 'site_type' => 'nodebb',
        'serving_profile' => 'node', 'status' => 'active', 'node_version' => '22', 'app_port' => 4001,
    ]);

    $clone = SiteClone::create([
        'source_application_id' => $source->id,
        'name' => 'Forum Copy',
        'domain' => 'forum-copy.test',
        'status' => 'pending',
    ]);

    app()->call([new RunClone($clone->id, $source->id), 'handle']);

    expect($clone->fresh()->status->value)->toBe('failed')
        ->and($clone->fresh()->target_application_id)->toBeNull()
        ->and(Application::where('domain', 'forum-copy.test')->exists())->toBeFalse();
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

    it('does not report success when copied files cannot be owned by the site user', function () {
        $filesCopied = false;

        Process::fake(function ($process) use (&$filesCopied) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
                return Process::result(exitCode: 0);
            }

            if (($args[0] ?? '') === 'rsync') {
                $filesCopied = true;

                return Process::result(exitCode: 0);
            }

            if ($filesCopied && ($args[0] ?? '') === 'chown' && ($args[1] ?? '') === '-R') {
                return Process::result(exitCode: 1, errorOutput: 'ownership denied');
            }

            return Process::result(exitCode: 0);
        });

        $source = Application::forceCreate([
            'system_user_id' => $this->systemUser->id,
            'name' => 'Docs',
            'slug' => 'docs', 'domain' => 'docs.test', 'site_type' => 'static',
            'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
        ]);

        $record = runClone($source, 'docs-clone.test');

        expect($record->status->value)->toBe('failed')
            ->and($record->target_application_id)->toBeNull()
            ->and(Application::where('domain', 'docs-clone.test')->exists())->toBeFalse();
    });

    it('does not keep a WordPress clone whose secret config cannot be secured', function (string $command) {
        Process::fake(function ($process) use ($command) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
                return Process::result(exitCode: 0);
            }

            if (in_array(($args[0] ?? ''), ['mysql', 'mariadb'], true)
                && str_contains((string) $process->input, 'information_schema.schemata')) {
                return Process::result(output: '1');
            }

            if (($args[0] ?? '') === $command && str_ends_with((string) end($args), '/wp-config.php')) {
                return Process::result(exitCode: 1, errorOutput: 'permission denied');
            }

            return Process::result(exitCode: 0);
        });

        $source = Application::forceCreate([
            'system_user_id' => $this->systemUser->id,
            'name' => 'Shop',
            'slug' => 'shop', 'domain' => 'shop.test', 'site_type' => 'wordpress',
            'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        ]);

        $database = Database::create([
            'name' => 'shop_db',
            'engine' => 'mysql',
            'application_id' => $source->id,
        ]);
        DatabaseUser::create([
            'database_id' => $database->id,
            'username' => 'shop_user',
            'password' => 'secret',
            'connection_preference' => 'localhost',
            'host' => 'localhost',
        ]);

        $record = runClone($source, 'shop-clone.test');

        expect($record->status->value)->toBe('failed')
            ->and($record->target_application_id)->toBeNull()
            ->and(Application::where('domain', 'shop-clone.test')->exists())->toBeFalse();
    })->with(['chmod', 'chown']);
});

it('gives the clone a primary domain row, not just the mirror column', function () {
    // The Domains screen reads `application_domains`; `applications.domain` is
    // only the mirror of whichever row is primary. CreateApplication writes
    // that row and CloneManager does not go through it, so a clone came up
    // serving its domain with a completely empty Domains section.
    fakeCloneServer();

    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Docs primary',
        'slug' => 'docs-primary', 'domain' => 'docs-primary.test', 'site_type' => 'static',
        'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
    ]);

    $clone = Application::find(runClone($source, 'docs-primary-clone.test')->target_application_id);

    $primary = $clone->domains()->where('type', 'primary')->first();

    expect($primary)->not->toBeNull()
        ->and($primary->domain)->toBe('docs-primary-clone.test')
        // The clone's own domain, not the source's — the mirror column and the
        // row have to name the same host or the vhost and the screen disagree.
        ->and($clone->domain)->toBe($primary->domain);
});

it('keeps serving its own domain after an alias is added', function () {
    // This is why the missing row was worse than cosmetic. `serverNames()`
    // falls back to `applications.domain` *only while the relation is empty*.
    // With no primary row, adding one alias made the relation non-empty
    // without the site's own domain in it — and the next vhost dropped it.
    fakeCloneServer();

    $source = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Docs alias',
        'slug' => 'docs-alias', 'domain' => 'docs-alias.test', 'site_type' => 'static',
        'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
    ]);

    $clone = Application::find(runClone($source, 'docs-alias-clone.test')->target_application_id);

    $clone->domains()->create([
        'domain' => 'extra.docs-alias.test',
        'type' => DomainType::Alias,
        'is_test' => false,
    ]);

    expect($clone->fresh()->load('domains')->serverNames())
        ->toContain('docs-alias-clone.test')
        ->toContain('extra.docs-alias.test');
});
