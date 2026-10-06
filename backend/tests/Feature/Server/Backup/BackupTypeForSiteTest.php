<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Rules\BackupTypeForSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * A backup type must match the kind of site it is configured for.
 *
 * Tested against the rule rather than through `PUT /backup-target`, because
 * container sites do not reach that route yet: `CheckPermission` 404s when a
 * site does not `supports('app_backup')`, and both container site types still
 * strip that feature — on purpose, until the volume steps exist. The rule is
 * where the decision lives, so the rule is what is asserted; the endpoint test
 * covers the hosted direction, which is reachable today.
 *
 * Both directions matter because both fail quietly. `volumes` on a WordPress
 * site would upload an archive containing nothing and mark it verified;
 * `filesystem` on a container site would archive a lone compose file.
 */
function siteWithProfile(string $profile): Application
{
    $user = SystemUser::firstOrCreate(
        ['username' => 'owner_'.$profile],
        ['home_path' => '/home/owner_'.$profile],
    );

    return Application::forceCreate([
        'system_user_id' => $user->id,
        'name' => 'Site '.$profile,
        'slug' => 'site-'.$profile,
        'domain' => $profile.'.example.test',
        'site_type' => $profile === 'docker' ? 'ghost' : 'php',
        'serving_profile' => $profile,
        'status' => 'active',
    ]);
}

function failsFor(string $profile, string $type): bool
{
    return Validator::make(
        ['type' => $type],
        ['type' => [new BackupTypeForSite(siteWithProfile($profile))]],
    )->fails();
}

it('accepts a container type on a container site', function (string $type) {
    expect(failsFor('docker', $type))->toBeFalse();
})->with(['volumes', 'config', 'volumes_config']);

it('refuses a host-served type on a container site', function (string $type) {
    expect(failsFor('docker', $type))->toBeTrue();
})->with(['filesystem', 'database', 'full']);

it('accepts a host-served type on a site served from a folder', function (string $type) {
    expect(failsFor('php', $type))->toBeFalse();
})->with(['filesystem', 'database', 'full']);

it('refuses a container type on a site served from a folder', function (string $type) {
    expect(failsFor('php', $type))->toBeTrue();
})->with(['volumes', 'config', 'volumes_config']);

it('leaves an unknown value to the enum rule, rather than adding a second message', function () {
    // `Rule::enum` already refuses it in the FormRequest. Failing here too
    // would print two errors for one mistake.
    expect(failsFor('php', 'nonsense'))->toBeFalse();
});

it('says which family the site needs, in words, not a translation key', function () {
    $validator = Validator::make(
        ['type' => 'filesystem'],
        ['type' => [new BackupTypeForSite(siteWithProfile('docker'))]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('type'))
        ->not->toContain('backup.errors')
        ->toContain('containers');
});
