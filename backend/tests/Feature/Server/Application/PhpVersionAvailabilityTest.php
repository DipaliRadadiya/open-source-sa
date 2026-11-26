<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Doctor\Checks\PhpIsolationCheck;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * A site may only be created on a PHP version this server actually has.
 *
 * nginx and Apache catch a missing version in the pool step (the pool file goes into a
 * non-existent `/etc/php/<version>`). **OpenLiteSpeed has no pool step**
 * (`PoolManager::supported()` is true only for the FPM stack), so nothing checked: the vhost
 * named an absent `lsphp` binary, `openlitespeed -t` passed, and the site went Active and
 * answered 503.
 *
 * Written against the LSPHP stack because that is where the hole was, and because its
 * "installed versions" come from a directory this test can build.
 */
beforeEach(function () {
    // An ordinary server: the catalog refuses a database-backed type when no engine answers.
    fakeUsableSqlEngine();

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

/**
 * Craft's required fields, so a range test fails on the range and nothing else.
 *
 * Named for this file rather than `craft()`: Pest helpers share one global namespace, and a
 * collision is a fatal error that takes the whole run down.
 */
function craftPayload(array $overrides = []): array
{
    return array_merge([
        'site_type' => 'craftcms',
        'site_name' => 'Craft Site',
        'admin_user' => 'admin',
        'admin_email' => 'admin@example.com',
        'admin_password' => 'a-long-password',
    ], $overrides);
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

it('stores the server default for a PHP site that names no version', function () {
    config(['server.default_php_version' => '8.3']);

    createPhpVersionSite(['domain' => 'plain.example.com'])
        ->assertSuccessful()
        ->assertJsonPath('application.php_version', '8.3');

    // Stored, not resolved at every render: the list showed "-" for it, and a
    // later change to the default would have moved the site to another PHP.
    config(['server.default_php_version' => '8.4']);

    expect(Application::query()->value('php_version'))->toBe('8.3');
});

it('stores no PHP version for a site that does not run PHP', function () {
    config(['server.default_php_version' => '8.3']);

    createPhpVersionSite(['domain' => 'static.example.com', 'site_type' => 'static'])->assertSuccessful();

    expect(Application::query()->value('php_version'))->toBeNull();
});

it('does not refuse anything when no versions could be detected', function () {
    // An empty list is also what an unreadable stack directory looks like. Refusing every site then
    // would turn an unreadable directory into a server that cannot host anything; a box with
    // genuinely no PHP is already refused by the runtime capability check.
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
    // `PHP_VERSION=8.3 ./install.sh` installs exactly one PHP (8.3) but left
    // `server.default_php_version` on its config default of 8.4, so sites created without an
    // explicit version pointed at a PHP that was never installed. `PANEL_PHP_VERSION` is a different
    // decision: the panel's own interpreter, read by the self-updater alone.
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

describe('the version the application itself can run on', function () {
    /*
     * Installed is not the same question as supported. The PHP field is one shared `phpFields()`
     * select offering every version on the box, and `defaultPhpVersion()` opens on the newest
     * (`FpmPhpStack::versions()` sorts descending).
     *
     * So a server whose newest PHP was 8.5 pre-selected 8.5 for PrestaShop, whose 8.2.1 release
     * vendors the monolithic `symfony/symfony`; the install died in `ProxyCacheWarmer->warmUp()`
     * during kernel boot, after the download, unpack, chown and database creation.
     *
     * PrestaShop now installs 9.x on 8.1 – 8.5 and every catalog range tops out at 8.5, so the
     * "very new" PHP here is 8.6: the same bug shape, one version further on.
     */

    beforeEach(function () {
        // A box carrying the old and the very new, which is the shape that
        // produced the bug.
        installLsphpBuild('8.1');
        installLsphpBuild('8.6');
    });

    it('refuses a PHP newer than the application supports', function () {
        createPhpVersionSite([
            'site_type' => 'prestashop',
            'domain' => 'shop-new.example.com',
            'php_version' => '8.6',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'a-long-password',
            'shop_name' => 'Shop',
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'User',
        ])->assertJsonValidationErrors('php_version');

        expect(Application::query()->count())->toBe(0);
    });

    it('names the range rather than calling the value invalid', function () {
        $response = createPhpVersionSite([
            'site_type' => 'prestashop',
            'domain' => 'shop-msg.example.com',
            'php_version' => '8.6',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'a-long-password',
            'shop_name' => 'Shop',
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'User',
        ]);

        // The user's next move is to pick a different version, so the message
        // has to say which ones would work.
        expect($response->json('errors.php_version.0'))->toContain('7.2 – 8.5');
    });

    it('accepts a PHP inside the range', function () {
        createPhpVersionSite([
            'site_type' => 'prestashop',
            'domain' => 'shop-ok.example.com',
            'php_version' => '8.1',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'a-long-password',
            'shop_name' => 'Shop',
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'User',
        ])->assertSuccessful();

        expect(Application::query()->value('php_version'))->toBe('8.1');
    });

    it('refuses a PHP older than the application supports', function () {
        // The other direction, and why this is a range: Statamic 6 requires 8.3+, so 8.1 is wrong and
        // 8.6 is right — the inverse of PrestaShop on the same box.
        createPhpVersionSite([
            'site_type' => 'statamic',
            'domain' => 'flat.example.com',
            'php_version' => '8.1',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'a-long-password',
            'shop_name' => 'Shop',
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'User',
        ])->assertJsonValidationErrors('php_version');

        createPhpVersionSite([
            'site_type' => 'statamic',
            'domain' => 'flat2.example.com',
            'php_version' => '8.6',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'a-long-password',
            'shop_name' => 'Shop',
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'User',
        ])->assertSuccessful();
    });

    it('refuses a PHP that would silently install a different Craft major', function () {
        // Craft declared no range at all: `composer create-project craftcms/craft` is unpinned,
        // `craftcms/cms` 5.x requires ^8.2 and 4.x ^8.0.2, so on 8.1 Composer resolves *backwards* and
        // installs Craft 4 — a different major — and nothing fails.
        // Every other required field is supplied, so `php_version` is the only thing that can fail.
        createPhpVersionSite(craftPayload(['domain' => 'craft-old.example.com', 'php_version' => '8.1']))
            ->assertJsonValidationErrors('php_version');

        expect(Application::query()->where('site_type', 'craftcms')->count())->toBe(0);
    });

    it('accepts a PHP Craft 5 actually supports', function () {
        // No ceiling: Craft states none, so the newest on the box is fine.
        createPhpVersionSite(craftPayload(['domain' => 'craft-ok.example.com', 'php_version' => '8.6']))
            ->assertSuccessful();

        expect(Application::query()->where('site_type', 'craftcms')->value('php_version'))->toBe('8.6');
    });

    it('opens the form on a version the application can run, not the newest', function () {
        // The half that stops anyone meeting the rule at all. Left to itself
        // the select pre-selected 8.6 for everything.
        $catalog = collect(app(SiteTypeManager::class)->catalog())->keyBy('name');

        $default = fn (string $type) => collect($catalog[$type]['fields'])
            ->firstWhere('name', 'php_version')['default'] ?? null;

        // The box has 8.1, 8.3 (the file's own fixture) and 8.6: the newest
        // inside PrestaShop's 7.2 – 8.5, never the 8.6 above it.
        expect($default('prestashop'))->toBe('8.3')
            ->and($default('statamic'))->toBe('8.6')
            // A type with no opinion still gets the newest, unchanged.
            ->and($default('php'))->toBe('8.6');
    });

    it('says nothing about a type that has no opinion', function () {
        createPhpVersionSite(['php_version' => '8.6', 'domain' => 'blank.example.com'])
            ->assertSuccessful();
    });

    describe('an empty version field', function () {
        it('is checked against the version it will actually resolve to', function () {
            // An empty field is not "no version": it is `server.default_php_version`, the newest PHP on the
            // box and so exactly the version a ceiling exists to exclude. The rule returned early on blank,
            // so clearing the field walked past it: green form, install dead inside PrestaShop's vendored
            // Symfony.
            config(['server.default_php_version' => '8.6']);

            $response = createPhpVersionSite([
                'site_type' => 'prestashop',
                'domain' => 'shop-blank.example.com',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'a-long-password',
                'shop_name' => 'Shop',
                'admin_first_name' => 'Admin',
                'admin_last_name' => 'User',
            ])->assertJsonValidationErrors('php_version');

            // Naming the default is the whole message: the user did not choose
            // anything, so an error about "the version you picked" is about
            // something they cannot see.
            expect($response->json('errors.php_version.0'))
                ->toContain('8.6')
                ->toContain('7.2 – 8.5');

            expect(Application::query()->where('site_type', 'prestashop')->count())->toBe(0);
        });

        it('is accepted when the default is inside the range', function () {
            config(['server.default_php_version' => '8.1']);

            createPhpVersionSite([
                'site_type' => 'prestashop',
                'domain' => 'shop-default-ok.example.com',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'a-long-password',
                'shop_name' => 'Shop',
                'admin_first_name' => 'Admin',
                'admin_last_name' => 'User',
            ])->assertSuccessful();
        });

        it('stays accepted for a type with no range at all', function () {
            // A server default out of *somebody's* range must not start
            // refusing types that never declared one.
            config(['server.default_php_version' => '8.6']);

            createPhpVersionSite(['domain' => 'plain-default.example.com'])->assertSuccessful();
        });
    });
});

it('offers only the PHP versions the site type runs on, keeping the current one', function () {
    // WordPress (7.4+) used to list an installed 7.0, and choosing it was refused with a 422 — the
    // menu must not offer what the endpoint rejects.
    installLsphpBuild('7.0');
    installLsphpBuild('8.2');

    $site = Application::forceCreate([
        'system_user_id' => $this->su->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.3', 'web_root' => '/', 'status' => 'active',
    ]);

    $versions = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->getJson("/api/applications/{$site->id}/php")
        ->assertOk()
        ->json('php.available_versions');

    expect($versions)->toContain('8.3')->toContain('8.2')->not->toContain('7.0');

    // And the one it lists is one the endpoint accepts.
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->putJson("/api/applications/{$site->id}/php", ['php_version' => '7.0'])
        ->assertStatus(422);
});

it('refuses to move a site to a PHP that is still installing', function () {
    // Its directory exists as soon as apt starts. A switch then wrote the site's pool into the
    // half-installed PHP, apt's own php-fpm start failed on the socket the old version still held,
    // and both the install and the switch failed.
    installLsphpBuild('8.2');
    app(InstallTracker::class)->start('php', '8.2');

    $site = Application::forceCreate([
        'system_user_id' => $this->su->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'php', 'serving_profile' => 'php',
        'php_version' => '8.3', 'web_root' => '/', 'status' => 'active',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->putJson("/api/applications/{$site->id}/php", ['php_version' => '8.2'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['php_version' => 'still being installed']);
});

/** Make `$target` the server's default PHP, the way "Make default" does on OpenLiteSpeed. */
function pointServerDefaultAt(?string $target): void
{
    @unlink(test()->link);

    if ($target !== null) {
        symlink($target, test()->link);
    }

    config(['server.php_path_link' => test()->link]);
}

/*
 * "Make default" on the PHP screen is the default for new sites too: it moves the `php` command,
 * whereas new sites used to start from install.sh's SERVER_DEFAULT_PHP_VERSION regardless.
 */
describe('the server default chosen on the PHP screen', function () {
    beforeEach(function () {
        installLsphpBuild('8.2');
        // install.sh's value; the server default below says otherwise.
        config(['server.default_php_version' => '8.3']);
        $this->link = $this->lsws.'/php-link';
    });

    it('is what a new site gets when it names no version', function () {
        pointServerDefaultAt($this->lsws.'/lsphp82/bin/php');

        createPhpVersionSite(['domain' => 'plain.example.com'])
            ->assertSuccessful()
            ->assertJsonPath('application.php_version', '8.2');
    });

    it('is what the create form preselects', function () {
        pointServerDefaultAt($this->lsws.'/lsphp82/bin/php');

        $fields = collect(app(SiteTypeManager::class)->catalog())->firstWhere('name', 'php')['fields'];

        expect(collect($fields)->firstWhere('name', 'php_version')['default'] ?? null)->toBe('8.2');
    });

    it('falls back to the configured version when the server has none the panel recognises', function () {
        pointServerDefaultAt('/usr/bin/not-a-php-the-panel-installed');

        createPhpVersionSite(['domain' => 'plain.example.com'])
            ->assertSuccessful()
            ->assertJsonPath('application.php_version', '8.3');
    });

    it('never moves a site that already has a version', function () {
        pointServerDefaultAt($this->lsws.'/lsphp83/bin/php');
        createPhpVersionSite(['domain' => 'old.example.com'])->assertSuccessful();

        pointServerDefaultAt($this->lsws.'/lsphp82/bin/php');
        app()->forgetScopedInstances();

        expect(Application::query()->where('domain', 'old.example.com')->value('php_version'))->toBe('8.3');
    });
});
