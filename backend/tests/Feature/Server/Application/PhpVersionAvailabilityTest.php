<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Doctor\Checks\PhpIsolationCheck;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * A site may only be created on a PHP version this server actually has.
 *
 * The two web-server families fail differently, which is why this went unseen.
 * On nginx and Apache a missing version is caught by the pool step: the pool
 * file is written into an `/etc/php/<version>` that does not exist and
 * provisioning stops with something to read. **OpenLiteSpeed has no pool step**
 * — `PoolManager::supported()` is true only for the FPM stack — so nothing
 * checked at all. The vhost was written naming an `lsphp` binary that is not on
 * the box, `openlitespeed -t` passed (it does not stat the interpreter), and the
 * site went Active and answered 503 on every request.
 *
 * Written against the LSPHP stack because that is where the hole was, and
 * because its "installed versions" come from a directory this test can build.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create([
        'username' => 'siteowner', 'home_path' => '/home/siteowner',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'ols', 'web_server' => 'openlitespeed',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->lsws = sys_get_temp_dir().'/sv-oss-lsphp-'.getmypid();
    File::deleteDirectory($this->lsws);

    config(['server.php_stacks.lsphp.dir' => $this->lsws]);

    // One version on the box, and it is not the one the form used to default
    // to: the installer installs exactly one lsphp, the panel's own.
    installLsphpBuild('8.3');
});

afterEach(fn () => File::deleteDirectory($this->lsws));

function installLsphpBuild(string $version): void
{
    $compact = str_replace('.', '', $version);
    $dir = test()->lsws."/lsphp{$compact}";

    File::makeDirectory($dir.'/bin', 0755, true);
    File::makeDirectory($dir."/etc/php/{$version}/litespeed", 0755, true);
    // Both, because the panel looks for the CLI and the LSAPI build
    // separately — a vhost pointed at `bin/php` serves nothing.
    File::put($dir.'/bin/php', '');
    File::put($dir.'/bin/lsphp', '');
}

function createPhpVersionSite(array $overrides = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/applications', array_merge([
            'system_user_id' => test()->su->id,
            'name' => 'Shop',
            'domain' => 'shop.example.com',
            'site_type' => 'php',
        ], $overrides));
}

it('refuses a PHP version the server does not have', function () {
    createPhpVersionSite(['php_version' => '8.4'])
        ->assertJsonValidationErrors('php_version');

    // And says which version, rather than "the selected value is invalid" —
    // the user's next move is to install it.
    expect(Application::query()->count())->toBe(0);
});

it('accepts the version that is installed', function () {
    createPhpVersionSite(['php_version' => '8.3'])->assertSuccessful();

    expect(Application::query()->value('php_version'))->toBe('8.3');
});

it('still accepts a site that names no version at all', function () {
    // Null means "use the server default" and is resolved later; refusing it
    // here would block every caller that does not send the field.
    createPhpVersionSite(['domain' => 'plain.example.com'])->assertSuccessful();
});

it('does not refuse anything when no versions could be detected', function () {
    // An empty list is also what an unreadable stack directory looks like.
    // Turning that into "no site may be created" would make a directory the
    // panel cannot read into a server that cannot host anything — and a box
    // with genuinely no PHP is already refused by the runtime capability check.
    File::deleteDirectory($this->lsws);

    createPhpVersionSite(['php_version' => '8.4', 'domain' => 'blind.example.com'])->assertSuccessful();
});

it('opens the create form on an installed version rather than a hardcoded one', function () {
    $fields = collect(app(SiteTypeManager::class)->catalog())
        ->firstWhere('name', 'wordpress')['fields'];

    $version = collect($fields)->firstWhere('name', 'php_version');

    // 8.4 was the literal default on every server whatever was installed, so
    // the form pre-selected a version the API now refuses.
    expect($version['default'])->toBe('8.3');
});

it('has the installer write the version it installed as the default for sites', function () {
    // `PHP_VERSION=8.3 ./install.sh` installed exactly one PHP — 8.3 — and left
    // `server.default_php_version` on its config default of 8.4, so every site
    // created without an explicit version pointed at a PHP that was never on
    // the box. `PANEL_PHP_VERSION` was written and is a different decision: it
    // is the panel's own interpreter, read by the self-updater alone.
    $installer = file_get_contents(base_path('../install.sh'));

    expect($installer)->toContain('set_env "${dir}/.env" SERVER_DEFAULT_PHP_VERSION "$PHP_VERSION"');
});

it('reports a site whose interpreter is missing, on the stack that has no pools', function () {
    Application::forceCreate([
        'system_user_id' => $this->su->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'web_root' => '/', 'status' => 'active',
    ]);

    $outcome = app(PhpIsolationCheck::class)->run();

    expect($outcome['status'])->toBe('fail')
        ->and($outcome['detail'])->toContain('shop.example.com')
        ->and($outcome['detail'])->toContain('8.4')
        ->and($outcome['fix'])->toBe('doctor.fixes.php_interpreter_missing');
});

it('passes when every site has its interpreter', function () {
    Application::forceCreate([
        'system_user_id' => $this->su->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.3', 'web_root' => '/', 'status' => 'active',
    ]);

    expect(app(PhpIsolationCheck::class)->run()['status'])->toBe('pass');
});

it('checks the sites that name no version against the server default', function () {
    // The site that inherits a default nobody installed is exactly the one a
    // check reading only explicit versions would miss.
    config(['server.default_php_version' => '8.4']);

    Application::forceCreate([
        'system_user_id' => $this->su->id,
        'name' => 'Plain', 'slug' => 'plain', 'domain' => 'plain.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => null, 'web_root' => '/', 'status' => 'active',
    ]);

    expect(app(PhpIsolationCheck::class)->run()['status'])->toBe('fail');
});
