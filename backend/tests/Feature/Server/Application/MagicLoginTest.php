<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\Permission;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-magic-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'wpuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'wordpress',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ]);

    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
    ]);

    // Magic Login refuses plain HTTP, so every test that expects to get past
    // that needs a certificate actually covering the site's primary name.
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'auto_renew' => true,
        'certificate_path' => '/etc/letsencrypt/live/shop.example.com/fullchain.pem',
        'private_key_path' => '/etc/letsencrypt/live/shop.example.com/privkey.pem',
        'issued_at' => now(),
        'expires_at' => now()->addDays(60),
    ]);

    $this->application->refresh();
});

/**
 * wp-cli, faked by subcommand. `multisite` decides whether
 * `core is-installed --network` succeeds — exit 0 means "yes, this is a
 * network", which is the shape the real command has.
 *
 * @param  array<int, array<string, mixed>>  $admins
 */
function fakeWpCli(array $admins, bool $multisite = false, bool $listFails = false): void
{
    Process::fake(function ($process) use ($admins, $multisite, $listFails) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($command, 'core is-installed --network')) {
            return Process::result(exitCode: $multisite ? 0 : 1);
        }

        if (str_contains($command, 'user list')) {
            return $listFails
                ? Process::result(errorOutput: 'Error: This does not seem to be a WordPress installation.', exitCode: 1)
                : Process::result(output: json_encode($admins));
        }

        return Process::result(exitCode: 0);
    });
}

function admins(): array
{
    return [
        ['ID' => 1, 'user_login' => 'owner', 'display_name' => 'Site Owner', 'user_email' => 'owner@example.com'],
        ['ID' => 7, 'user_login' => 'second', 'display_name' => 'Second Admin', 'user_email' => 'second@example.com'],
    ];
}

it('lists only the administrators wordpress reports', function () {
    fakeWpCli(admins());

    $response = $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/magic-login")
        ->assertOk();

    expect($response->json('administrators'))->toHaveCount(2);
    expect($response->json('administrators.0.login'))->toBe('owner');

    // The screen needs a name to pick between accounts and nothing more.
    // Shipping every administrator's address to it widens what this endpoint
    // discloses for no gain.
    expect($response->json('administrators.0'))->not->toHaveKey('email');
});

it('asks wordpress only for administrators, and survives a broken plugin', function () {
    fakeWpCli(admins());

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/magic-login")
        ->assertOk();

    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (! str_contains($command, 'user list')) {
            return false;
        }

        return str_contains($command, '--role=administrator')
            // The moment an operator most needs to get into a site is usually
            // the moment something on it is broken.
            && str_contains($command, '--skip-plugins')
            && str_contains($command, '--skip-themes')
            // Never as root: wp-cli runs as the account that owns the files.
            && str_contains($command, 'runuser -u wpuser');
    });
});

it('does not exist on a site that is not wordpress', function () {
    fakeWpCli(admins());

    $this->application->update(['site_type' => 'php']);

    // 404 rather than 403: for a PHP site this feature does not exist at all,
    // which is a different statement from "you may not".
    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/magic-login")
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 1])
        ->assertNotFound();
});

it('refuses a user who has not been granted magic login', function () {
    fakeWpCli(admins());

    // Deliberately a user with no roles at all: the point is that holding
    // other application permissions must not carry this one.
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 1])
        ->assertForbidden();
});

it('refuses over plain http rather than leaking an admin session', function () {
    fakeWpCli(admins());

    // No certificate: the site is served over http, so the token and the
    // cookie it buys would both cross the network in clear text.
    $this->application->certificate->delete();
    $this->application->refresh();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 1])
        ->assertStatus(422);

    expect($response->json('errors.magic_login.0'))->toContain('HTTPS');

    // Nothing was written to the site for a request that was refused.
    Process::assertNotRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'option update'
    ));
});

it('refuses a multisite network instead of signing in with less access than it looks like', function () {
    fakeWpCli(admins(), multisite: true);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 1])
        ->assertStatus(422);

    Process::assertNotRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'option update'
    ));
});

it('refuses an id that is not on the administrator list', function () {
    fakeWpCli(admins());

    // A subscriber's id, or an account demoted since the list was rendered.
    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 99])
        ->assertStatus(422)
        ->assertJsonValidationErrors('wp_user_id');

    expect($response->json('errors.wp_user_id.0'))->toContain('not an administrator');
});

it('stores only the hash of the token on the site, never the token', function () {
    fakeWpCli(admins());

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 7])
        ->assertStatus(201);

    $token = $response->json('magic_login.token');
    expect($token)->toBeString()->not->toBeEmpty();

    // The site keeps the SHA-256 and nothing else, so reading the site's
    // database does not yield a usable token — the same reason a password is
    // not stored. The panel keeps nothing at all.
    Process::assertRan(function ($process) use ($token) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (! str_contains($command, 'option update sv_magic_login_token')) {
            return false;
        }

        return str_contains($command, hash('sha256', $token))
            && ! str_contains($command, $token)
            // Read on exactly one request in its 60-second life; autoloading it
            // would fetch it on every page load of the whole site.
            && str_contains($command, '--autoload=no');
    });
});

it('installs the loader from this repository and never from a url', function () {
    fakeWpCli(admins());

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 1])
        ->assertStatus(201);

    // The implementation this replaces took a URL as a request parameter and
    // wget'd it into the customer's document root. Nothing here may fetch.
    Process::assertNotRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'wget') || str_contains($command, 'curl');
    });

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'mu-plugins'
    ));
});

it('names the account that was assumed in the activity log', function () {
    fakeWpCli(admins());

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/magic-login", ['wp_user_id' => 7])
        ->assertStatus(201);

    $entry = ActivityLog::where('action', 'magic_login')->latest('id')->first();

    expect($entry)->not->toBeNull();
    expect($entry->user_id)->toBe($this->admin->id);
    // "Someone used magic login" is not an audit trail. The question after the
    // fact is always *as whom*.
    expect($entry->properties['wp_user'])->toBe('second');
});

it('reports a broken wp-cli rather than an empty administrator list', function () {
    fakeWpCli(admins(), listFails: true);

    // An empty list would read as "this site has no administrators", which is
    // a statement about the site rather than about the failure.
    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}/magic-login")
        ->assertStatus(422);
});

it('is a permission that grants nothing in the sidebar', function () {
    $magic = collect(app(PermissionCatalog::class)->items())
        ->firstWhere('name', 'app_magic_login');

    expect($magic)->not->toBeNull();
    // Null url is what keeps it out of the nav while leaving it grantable in
    // the role editor — it is a button on the Dashboard, not a screen.
    expect($magic['url'])->toBeNull();

    // And it survives the round trip into the table the sidebar actually
    // reads, rather than only being null in the catalog array.
    expect(Permission::where('name', 'app_magic_login')->value('url'))->toBeNull();
});

it('is offered on wordpress and on nothing else', function () {
    expect($this->application->supports('app_magic_login'))->toBeTrue();

    foreach (['php', 'static', 'nodejs'] as $type) {
        $this->application->update(['site_type' => $type]);
        expect($this->application->fresh()->supports('app_magic_login'))->toBeFalse();
    }
});
