<?php

use App\Models\User;
use App\Services\Git\GitProviderManager;
use App\Support\PasswordPolicy;
use Database\Seeders\PermissionSeeder;

/**
 * Three findings from a QA pass, each about the API stating something it knows.
 */
beforeEach(fn () => $this->seed(PermissionSeeder::class));

it('tells the sign-up form what a password has to be', function () {
    // The rule existed and no endpoint stated it, so a form wanting to say
    // "10 characters, mixed case, a number" had to hardcode that sentence —
    // and would keep promising the old policy after the rule changed.
    $policy = $this->getJson('/api/basic-info')
        ->assertOk()
        ->json('basic_info.password_policy');

    expect($policy)->toBe([
        'min_length' => 10,
        'requires_mixed_case' => true,
        'requires_number' => true,
        'requires_symbol' => false,
    ]);
});

it('enforces exactly the policy it advertises', function () {
    // The two must not be able to drift: a described policy that validation
    // does not apply is worse than no description.
    $described = PasswordPolicy::describe();

    $this->postJson('/api/auth/register', [
        'name' => 'Ada', 'username' => 'ada',
        // One short of the advertised minimum, and otherwise valid.
        'password' => str_repeat('Aa1', 3), 'password_confirmation' => str_repeat('Aa1', 3),
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    expect(strlen(str_repeat('Aa1', 3)))->toBeLessThan($described['min_length']);
});

it('agrees with the register endpoint about whether registration is open', function () {
    // basic-info counted every user while registration is gated on non-system
    // ones, so a panel holding only machine accounts reported registration
    // closed while POST /auth/register would have accepted one — the sign-up
    // screen hidden on a panel with no administrator and no other way in.
    User::factory()->create(['is_system' => true]);

    $this->getJson('/api/basic-info')
        ->assertOk()
        ->assertJsonPath('basic_info.registration_open', true);
});

it('closes registration once a real user exists', function () {
    User::factory()->create(['is_system' => false]);

    $this->getJson('/api/basic-info')
        ->assertOk()
        ->assertJsonPath('basic_info.registration_open', false);
});

it('explains the fields that need explaining, and only those', function () {
    // The GitLab URL and the Bitbucket workspace had no field-scoped help;
    // their guidance was either absent or buried inside the *token's* help
    // string — text about one field, describing another.
    $catalog = collect(app(GitProviderManager::class)->catalog())->keyBy('name');

    $help = fn (string $provider, string $field) => collect($catalog[$provider]['fields'])
        ->firstWhere('name', $field)['help'] ?? null;

    expect($help('gitlab', 'host'))->toContain('self-hosted')
        ->and($help('bitbucket', 'workspace'))->toContain('bitbucket.org/')
        // Null, never the untranslated key: a missing translation showing as
        // `git.field_help.github.token` under an input is worse than no hint.
        ->and($help('github', 'token'))->toBeNull();
});
