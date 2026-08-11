<?php

use App\Http\Requests\Server\Application\SavePhpSettingsRequest;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * The two `disable_functions` starting points the panel offers.
 *
 * The list that ships as "strict" was cleaned from a widely-copied hosting
 * one that contains a stray comma, two duplicates and four names that are not
 * PHP functions. None of that is visible at runtime — PHP silently ignores an
 * unknown name in `disable_functions` — so a typo there is a list that claims
 * protection it does not provide, and only a test will ever say so.
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

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'domain' => 'shop.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);
});

/**
 * @return list<string>
 */
function presetFunctions(string $list): array
{
    return array_values(array_filter(array_map('trim', explode(',', $list))));
}

describe('GET /applications/{application}/php', function () {

    it('offers both presets, safest first', function () {
        $presets = $this->actingAs($this->admin)
            ->getJson("/api/applications/{$this->application->id}/php")
            ->assertOk()
            ->json('php.disable_functions_presets');

        expect(array_column($presets, 'key'))->toBe(['safe', 'strict']);

        foreach ($presets as $preset) {
            expect($preset)->toHaveKeys(['key', 'title', 'description', 'functions']);
            expect($preset['title'])->not->toBe('');
        }
    });

    it('keeps the flat suggested key so an older client still works', function () {
        $this->actingAs($this->admin)
            ->getJson("/api/applications/{$this->application->id}/php")
            ->assertOk()
            ->assertJsonPath('php.suggested_disable_functions', ApplicationPhpSettings::SAFE_DISABLED_FUNCTIONS);
    });

    it('localises the preset titles', function () {
        $titles = fn (string $locale) => array_column(
            $this->actingAs($this->admin)
                ->getJson("/api/applications/{$this->application->id}/php", ['Accept-Language' => $locale])
                ->json('php.disable_functions_presets'),
            'title'
        );

        expect($titles('de'))->not->toBe($titles('en'));
    });
});

describe('the strict list', function () {

    it('contains no duplicates', function () {
        $functions = presetFunctions(ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS);

        expect($functions)->toHaveCount(count(array_unique($functions)));
    });

    it('contains no name that is not a PHP function', function () {
        // Extension-gated families are skipped: posix_* and socket_* are real
        // functions that simply may not be loaded in the test environment, and
        // disabling an unloaded function is harmless. Everything else must
        // exist, which is what catches `leak`, `source`, `listen`, `virtual`
        // and the `posix,_getppid` split.
        $unknown = array_values(array_filter(
            presetFunctions(ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS),
            fn (string $function): bool => ! str_starts_with($function, 'posix_')
                && ! str_starts_with($function, 'socket_')
                && ! function_exists($function)
        ));

        expect($unknown)->toBe([]);
    });

    it('never splits a name on a stray comma', function () {
        $functions = presetFunctions(ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS);

        // `posix,_getppid` in the source list produced exactly this shape:
        // a bare family prefix and a fragment starting with an underscore.
        expect($functions)->not->toContain('posix');

        foreach ($functions as $function) {
            expect($function)->not->toStartWith('_');
        }
    });

    it('covers everything the safe list covers', function () {
        expect(presetFunctions(ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS))
            ->toContain(...presetFunctions(ApplicationPhpSettings::SAFE_DISABLED_FUNCTIONS));
    });

    it('leaves out the functions that break working sites', function () {
        // Each of these appears in the hosting list this was derived from and
        // was dropped on purpose — see the constant's docblock for why.
        expect(presetFunctions(ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS))
            ->not->toContain('symlink')
            ->not->toContain('link')
            ->not->toContain('tmpfile')
            ->not->toContain('fpassthru')
            ->not->toContain('diskfreespace')
            ->not->toContain('escapeshellcmd')
            ->not->toContain('stream_socket_server');
    });

    it('passes the validation the save endpoint applies to it', function () {
        // A preset the user cannot then save would be a button that only ever
        // produces a 422. The length limit is the one at real risk: the list
        // is long enough that a few more entries would cross it.
        $rules = (new SavePhpSettingsRequest)->rules();

        $validator = validator(
            ['disable_functions' => ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS],
            ['disable_functions' => $rules['disable_functions']]
        );

        expect($validator->passes())->toBeTrue()
            ->and(strlen(ApplicationPhpSettings::STRICT_DISABLED_FUNCTIONS))->toBeLessThan(2000);
    });
});
