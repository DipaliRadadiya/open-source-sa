<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\ServerCapability;
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

function fakeCloneServer(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
}

it('clones a static site (no database needed) generically', function () {
    fakeCloneServer();

    $source = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Docs', 'domain' => 'docs.test', 'site_type' => 'static',
        'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
    ]);

    $response = $this->withHeaders(cloneHeaders())
        ->postJson("/api/applications/{$source->id}/clone", ['domain' => 'docs-clone.test'])
        ->assertCreated()
        ->assertJsonPath('clone.domain', 'docs-clone.test')
        ->assertJsonPath('clone.cloned_from_application_id', $source->id);

    $clone = Application::find($response->json('clone.id'));

    expect($clone)->not->toBeNull()
        ->and($clone->status->value)->toBe('active')
        ->and($clone->webhook_enabled)->toBeFalse()
        ->and(ActivityLog::where('type', 'application')->where('action', 'cloned')->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'rsync');
});

it('clones a WordPress site including its database', function () {
    fakeCloneServer();

    $source = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'domain' => 'shop.test', 'site_type' => 'wordpress',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $database = Database::create(['name' => 'shop_db', 'engine' => 'mysql', 'application_id' => $source->id]);
    DatabaseUser::create(['database_id' => $database->id, 'username' => 'shop_user', 'password' => 'secret', 'connection_preference' => 'localhost', 'host' => 'localhost']);

    $response = $this->withHeaders(cloneHeaders())
        ->postJson("/api/applications/{$source->id}/clone", ['domain' => 'shop-clone.test'])
        ->assertCreated();

    $clone = Application::find($response->json('clone.id'));

    expect(Database::where('application_id', $clone->id)->exists())->toBeTrue();

    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'mysqldump');
    Process::assertRan(fn ($p) => in_array('runuser', $p->command, true) && in_array('search-replace', $p->command, true));
});

it('refuses to clone a database-needing type with no clone recipe', function () {
    fakeCloneServer();

    $source = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Forum', 'domain' => 'forum.test', 'site_type' => 'nodebb',
        'serving_profile' => 'node', 'status' => 'active', 'node_version' => '22', 'app_port' => 4000,
    ]);

    $this->withHeaders(cloneHeaders())
        ->postJson("/api/applications/{$source->id}/clone", ['domain' => 'forum-clone.test'])
        ->assertStatus(500);

    expect(Application::where('domain', 'forum-clone.test')->exists())->toBeFalse();
});

it('allocates a fresh port for a node clone rather than reusing the source port', function () {
    fakeCloneServer();

    $source = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Status', 'domain' => 'status.test', 'site_type' => 'uptimekuma',
        'serving_profile' => 'node', 'status' => 'active', 'node_version' => '22',
        'app_port' => 3001, 'start_command' => 'node server.js',
    ]);

    $response = $this->withHeaders(cloneHeaders())
        ->postJson("/api/applications/{$source->id}/clone", ['domain' => 'status-clone.test'])
        ->assertCreated();

    $clone = Application::find($response->json('clone.id'));

    expect($clone->app_port)->not->toBeNull()->and($clone->app_port)->not->toBe(3001);
});

it('refuses without manage permission', function () {
    fakeCloneServer();
    $source = Application::create([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Docs', 'domain' => 'docs2.test', 'site_type' => 'static',
        'serving_profile' => 'static', 'status' => 'active', 'web_root' => '/',
    ]);

    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_clone', view: true, manage: false);

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson("/api/applications/{$source->id}/clone", ['domain' => 'docs2-clone.test'])
        ->assertStatus(403);
});
