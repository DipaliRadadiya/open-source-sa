<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Registry;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Registry credentials: the endpoints, and what must never come back out.
 *
 * The security argument for this feature is a shape, not a check — encrypted at
 * rest, one reader, scoped to one command — so most of what is asserted here is
 * absence: the token is not in a response, not in an activity row, and not in
 * argv.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);
});

function registryHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function makeRegistry(array $attributes = []): Registry
{
    // `forceCreate`, because the probe columns are deliberately not fillable —
    // `create()` drops them silently and a test that seeds a green tick would be
    // asserting against a row that never had one.
    return Registry::forceCreate(array_merge([
        'name' => 'GHCR',
        'registry' => 'ghcr.io',
        'username' => 'octocat',
        'config' => ['token' => 'ghp_SUPERSECRETVALUE'],
    ], $attributes));
}

/*
 * Storage. The column is ciphertext and the cast is the only thing that reads it.
 */

it('stores the token encrypted, never as plaintext in the column', function () {
    $registry = makeRegistry();

    $raw = (string) DB::table('registries')->where('id', $registry->id)->value('config');

    expect($raw)->not->toContain('ghp_SUPERSECRETVALUE')
        // And it really is the same value coming back, so the assertion above is
        // about encryption and not about a column that failed to save.
        ->and($registry->fresh()->configValue('token'))->toBe('ghp_SUPERSECRETVALUE');
});

it('answers null rather than throwing when the config cannot be decrypted', function () {
    $registry = makeRegistry();

    // What a database restored under a different APP_KEY looks like. The failure
    // must not be a TypeError deep inside a queue worker.
    DB::table('registries')->where('id', $registry->id)->update(['config' => 'not-ciphertext']);

    expect($registry->fresh()->configValue('token'))->toBeNull()
        ->and($registry->fresh()->hasCredentials())->toBeFalse();
});

/*
 * The response shape. Absence, not masking: a mask is a length disclosure and a
 * truncation is a prefix disclosure, and a PAT's prefix identifies its account.
 */

it('never puts the token in any response', function () {
    $registry = makeRegistry();

    foreach ([
        ['getJson', '/api/docker/registries'],
        ['getJson', '/api/docker/registries/'.$registry->id],
        ['patchJson', '/api/docker/registries/'.$registry->id],
    ] as [$method, $url]) {
        $response = $this->withHeaders(registryHeaders())->{$method}($url, []);

        expect($response->getContent())->not->toContain('ghp_SUPERSECRETVALUE')
            ->and($response->getContent())->not->toContain('token');
    }
});

it('reports that a credential exists without reporting what it is', function () {
    makeRegistry();

    $this->withHeaders(registryHeaders())
        ->getJson('/api/docker/registries')
        ->assertOk()
        ->assertJsonPath('registries.0.has_credentials', true)
        ->assertJsonPath('registries.0.username', 'octocat');
});

it('shows the key Docker will actually use, because Hub differs from what is typed', function () {
    // The single most confusing thing about this feature, so the panel says it
    // rather than leaving the user to wonder why `docker.io` did not work.
    $hub = makeRegistry(['name' => 'Hub', 'registry' => 'docker.io']);

    $this->withHeaders(registryHeaders())
        ->getJson('/api/docker/registries/'.$hub->id)
        ->assertOk()
        ->assertJsonPath('registry.auth_key', 'https://index.docker.io/v1/')
        ->assertJsonPath('registry.is_docker_hub', true);
});

/*
 * Normalisation, and the refusals that exist because a bad key is INVISIBLE.
 */

it('writes Hub credentials under the v1 index key, however it was typed', function (string $typed) {
    expect(makeRegistry(['registry' => $typed])->authKey())->toBe(Registry::HUB_KEY);
})->with(['docker.io', 'https://docker.io', 'index.docker.io', 'DOCKER.IO', 'docker.io/', 'https://index.docker.io/v1/']);

it('keys every other registry by its bare host', function (string $typed, string $expected) {
    expect(makeRegistry(['registry' => $typed])->authKey())->toBe($expected);
})->with([
    ['ghcr.io', 'ghcr.io'],
    ['https://ghcr.io', 'ghcr.io'],
    ['registry.example.com:5000', 'registry.example.com:5000'],
    ['GHCR.IO/', 'ghcr.io'],
]);

it('refuses an address with a namespace, which is the common mistake', function () {
    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries', [
            'name' => 'Mine', 'registry' => 'ghcr.io/my-org', 'username' => 'u', 'token' => 't',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('registry');
});

it('refuses an address with credentials embedded in it', function () {
    // Otherwise a password lands in a column that is not encrypted, beside one
    // that is.
    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries', [
            'name' => 'Mine', 'registry' => 'user:pass@ghcr.io', 'username' => 'u', 'token' => 't',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('registry');
});

it('refuses a token with a newline in it', function () {
    // A pasted token with a trailing newline authenticates nowhere and looks
    // correct in every screen — and this feature shows it in none of them, so
    // there would be nothing to look at.
    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries', [
            'name' => 'Mine', 'registry' => 'ghcr.io', 'username' => 'u', 'token' => "abc\ndef",
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('token');
});

/*
 * Partial updates. Omission preserves, or a rename wipes a working credential.
 */

it('keeps the stored token when an update does not send one', function () {
    $registry = makeRegistry();

    $this->withHeaders(registryHeaders())
        ->patchJson('/api/docker/registries/'.$registry->id, ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('registry.name', 'Renamed');

    expect($registry->fresh()->configValue('token'))->toBe('ghp_SUPERSECRETVALUE');
});

it('keeps the stored token when an update sends an explicit null', function () {
    // Two clients express "I did not touch the password box" two ways, and only
    // one of them omits the key.
    $registry = makeRegistry();

    $this->withHeaders(registryHeaders())
        ->patchJson('/api/docker/registries/'.$registry->id, ['token' => null])
        ->assertOk();

    expect($registry->fresh()->configValue('token'))->toBe('ghp_SUPERSECRETVALUE');
});

it('forgets a green tick when the token is rotated', function () {
    $registry = makeRegistry(['last_tested_at' => now(), 'last_test_success' => true]);

    $this->withHeaders(registryHeaders())
        ->patchJson('/api/docker/registries/'.$registry->id, ['token' => 'ghp_NEWVALUE'])
        ->assertOk()
        ->assertJsonPath('registry.status', 'never_tested');

    expect($registry->fresh()->last_test_success)->toBeNull();
});

it('forgets a green tick when the address changes', function () {
    // The tick described a credential against a host. Point it somewhere else and
    // it is a claim about something that was never tested.
    $registry = makeRegistry(['last_tested_at' => now(), 'last_test_success' => true]);

    $this->withHeaders(registryHeaders())
        ->patchJson('/api/docker/registries/'.$registry->id, ['registry' => 'ghcr.io.example.net'])
        ->assertOk()
        ->assertJsonPath('registry.status', 'never_tested');
});

it('keeps a green tick when only the name changed', function () {
    // The inverse, and it matters: forgetting on every save would make the tick
    // useless, since fixing a typo would clear it.
    $registry = makeRegistry(['last_tested_at' => now(), 'last_test_success' => true]);

    $this->withHeaders(registryHeaders())
        ->patchJson('/api/docker/registries/'.$registry->id, ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('registry.status', 'connected');
});

/*
 * The activity log is read by a wider audience than the people who may manage
 * Docker, so it gets the address and never the secret.
 */

it('records the address and the account, never the token', function () {
    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries', [
            'name' => 'Mine', 'registry' => 'ghcr.io', 'username' => 'octocat', 'token' => 'ghp_SUPERSECRETVALUE',
        ])
        ->assertCreated();

    $row = ActivityLog::where('type', 'registry')->where('action', 'created')->sole();

    expect(json_encode($row->properties))->not->toContain('ghp_SUPERSECRETVALUE')
        ->and($row->properties['username'])->toBe('octocat');
});

it('records that a token moved without recording where to', function () {
    $registry = makeRegistry();

    $this->withHeaders(registryHeaders())
        ->patchJson('/api/docker/registries/'.$registry->id, ['token' => 'ghp_NEWVALUE'])
        ->assertOk();

    $row = ActivityLog::where('type', 'registry')->where('action', 'updated')->sole();

    expect($row->properties['token_rotated'])->toBeTrue()
        ->and(json_encode($row->properties))->not->toContain('ghp_NEWVALUE');
});

/*
 * Deleting a credential must be possible while sites reference it — otherwise a
 * leaked token is unrevokable — and must not delete the sites.
 */

it('detaches the sites instead of deleting them or refusing', function () {
    $registry = makeRegistry();
    $user = SystemUser::create(['username' => 'shop', 'home_path' => '/home/shop']);

    $application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'ghcr.io/acme/app:1', 'registry_id' => $registry->id, 'app_port' => 20001,
        'status' => 'active',
    ]);

    $this->withHeaders(registryHeaders())
        ->deleteJson('/api/docker/registries/'.$registry->id)
        ->assertOk();

    expect(Registry::find($registry->id))->toBeNull()
        ->and($application->fresh())->not->toBeNull()
        ->and($application->fresh()->registry_id)->toBeNull();
});

it('records how many sites a delete detached', function () {
    $registry = makeRegistry();
    $user = SystemUser::create(['username' => 'shop', 'home_path' => '/home/shop']);

    Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'registry_id' => $registry->id, 'app_port' => 20001, 'status' => 'active',
    ]);

    $this->withHeaders(registryHeaders())->deleteJson('/api/docker/registries/'.$registry->id)->assertOk();

    expect(ActivityLog::where('type', 'registry')->where('action', 'deleted')->sole()->properties['applications_detached'])->toBe(1);
});

/*
 * Authorization. Creating one stores a credential that pulls private code onto
 * this box, which is not a read by any reading.
 */

it('refuses every mutation without docker manage', function () {
    $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer']);
    $role->permissions()->attach(
        Permission::where('name', 'docker')->sole()->id,
        // View without manage: the exact grant the picker needs and the one every
        // mutation below must still refuse.
        ['view' => true, 'manage' => false],
    );

    $viewer = User::factory()->create();
    $viewer->roles()->attach($role);

    $registry = makeRegistry();
    $headers = ['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken];

    $this->withHeaders($headers)->postJson('/api/docker/registries', [
        'name' => 'X', 'registry' => 'ghcr.io', 'username' => 'u', 'token' => 't',
    ])->assertForbidden();

    $this->withHeaders($headers)->patchJson('/api/docker/registries/'.$registry->id, ['name' => 'Y'])->assertForbidden();
    $this->withHeaders($headers)->deleteJson('/api/docker/registries/'.$registry->id)->assertForbidden();
    $this->withHeaders($headers)->postJson('/api/docker/registries/'.$registry->id.'/test')->assertForbidden();

    // But a viewer may read the list, because the site form's picker needs it.
    $this->withHeaders($headers)->getJson('/api/docker/registries')->assertOk();
});

it('is unavailable on a server that hosts no containers', function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true, 'serving_profiles' => ['php']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->withHeaders(registryHeaders())->getJson('/api/docker/registries')->assertStatus(409);
});

/*
 * The probe. A refused credential is a successful request with a failed verdict.
 */

it('persists a failed verdict as 200, not as an error', function () {
    // Measured wording, self-hosted registry v2 refusing a password.
    Process::fake(fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: login attempt to http://127.0.0.1:5000/v2/ failed with status: 401 Unauthorized',
        exitCode: 1,
    ));

    $registry = makeRegistry();

    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries/'.$registry->id.'/test')
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('registry.last_test_error', 'invalid_credentials')
        ->assertJsonPath('registry.status', 'failed');
});

it('calls an unreachable registry unreachable, not a bad password', function () {
    // Otherwise a hostname typo sends somebody to rotate a working token.
    Process::fake(fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: Get "https://nope.example.com/v2/": dial tcp: lookup nope.example.com: no such host',
        exitCode: 1,
    ));

    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries/'.makeRegistry()->id.'/test')
        ->assertOk()
        ->assertJsonPath('registry.last_test_error', 'unreachable');
});

it('records a success', function () {
    Process::fake(fn () => Process::result(output: 'Login Succeeded'));

    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries/'.makeRegistry()->id.'/test')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('registry.status', 'connected');
});

it('sends the token on stdin and never in a command argument', function () {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: 'Login Succeeded');
    });

    $this->withHeaders(registryHeaders())
        ->postJson('/api/docker/registries/'.makeRegistry()->id.'/test')
        ->assertOk();

    // argv is world-readable in `ps` for the life of the process. This is the
    // assertion that keeps it out — the flag must be `--password-stdin`, and the
    // value must appear in no argument of any command that ran.
    $flat = json_encode($commands);

    expect($flat)->not->toContain('ghp_SUPERSECRETVALUE')
        ->and($flat)->toContain('--password-stdin');
});

it('has every status and error reason translated in all eight locales', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        foreach ([
            'registry_status.connected', 'registry_status.never_tested', 'registry_status.failed',
            'registry_test_error.invalid_credentials', 'registry_test_error.unreachable',
            'registry_test_error.unknown',
        ] as $key) {
            $line = __('docker.'.$key, [], $locale);

            expect($line)->not->toBe('docker.'.$key)->and($line)->not->toBeEmpty();
        }
    }
});
