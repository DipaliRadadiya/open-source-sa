<?php

use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\SystemUsers\SystemUsernameGenerator;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    // `getent passwd <name>` exits 2 when the account is absent, which is the
    // *answer*, not a failure. Everything else — useradd above all — succeeds.
    // A blanket `exitCode: 2` fake would make account creation fail too, and
    // the test would be asserting against a broken server rather than the code.
    Process::fake(fn ($process) => str_contains(commandOf($process), 'getent passwd')
        ? Process::result(exitCode: 2)
        : Process::result(exitCode: 0));
    Queue::fake();
});

function commandOf($process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
}

function createPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Company Blog',
        'domain' => 'blog.example.com',
        'site_type' => 'static',
        'generate_system_user' => true,
    ], $overrides);
}

it('creates the account and owns the application with it', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/applications', createPayload())
        ->assertCreated();

    $application = Application::find($response->json('application.id'));
    $user = SystemUser::find($application->system_user_id);

    expect($user)->not->toBeNull();
    // Named after the site, because this string is what somebody reads in `ps`
    // or `ls -l` while working out which site an account belongs to.
    expect($user->username)->toBe('company-blog');

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'useradd'
    ));
});

it('still accepts an existing system user', function () {
    $existing = SystemUser::create([
        'username' => 'picked', 'home_path' => '/home/picked', 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/applications', createPayload([
            'generate_system_user' => false,
            'system_user_id' => $existing->id,
        ]))
        ->assertCreated();

    expect(Application::find($response->json('application.id'))->system_user_id)->toBe($existing->id);

    // Nothing was created: an id the caller chose is not an invitation to make
    // another account.
    Process::assertNotRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'useradd'
    ));
});

it('refuses both at once rather than silently honouring one', function () {
    $existing = SystemUser::create([
        'username' => 'picked', 'home_path' => '/home/picked', 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    // A client that sends both is confused about its own intent, and picking
    // one for it is how a site ends up owned by an account nobody chose.
    $this->actingAs($this->admin)
        ->postJson('/api/applications', createPayload(['system_user_id' => $existing->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('system_user_id');
});

it('still requires a system user when not generating one', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/applications', createPayload(['generate_system_user' => false]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('system_user_id');
});

it('refuses to generate for someone who may not create system users', function () {
    // Can manage applications, cannot manage system users — exactly the role
    // the form hides the "Create system user" link from.
    $role = Role::create(['name' => 'App manager', 'slug' => 'app-manager']);
    $role->permissions()->attach(
        Permission::where('name', 'application')->value('id'),
        ['view' => true, 'manage' => true],
    );

    $limited = User::factory()->create();
    $limited->roles()->attach($role->id);

    $response = $this->actingAs($limited)
        ->postJson('/api/applications', createPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('generate_system_user');

    expect($response->json('errors.generate_system_user.0'))->toContain('permission');

    // And nothing was created on the box for a request that was refused.
    Process::assertNotRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'useradd'
    ));
});

describe('the generated name', function () {
    it('survives everything useradd refuses', function (string $appName, string $expected) {
        expect(app(SystemUsernameGenerator::class)->forApplication($appName))->toBe($expected);
    })->with([
        'spaces and case' => ['Company Blog', 'company-blog'],
        'punctuation' => ['Acme, Inc. — Store!', 'acme-inc-store'],
        // useradd wants a letter first, so a year cannot lead.
        'leading digits' => ['2024 Campaign', 'campaign'],
        // Str::slug transliterates, so the word survives instead of vanishing.
        'accents' => ['Café Blog', 'cafe-blog'],
        // 32 is useradd's ceiling on most distributions.
        'over length' => ['A Very Long Application Name That Keeps Going And Going', 'a-very-long-application-name-tha'],
    ]);

    it('never hands back a reserved account name', function () {
        // `root` and `root-a1b2` are different accounts and only one of them is
        // refused, so this prefixes rather than suffixing.
        expect(app(SystemUsernameGenerator::class)->forApplication('root'))->toBe('app-root');
        expect(app(SystemUsernameGenerator::class)->forApplication('www-data'))->toBe('app-www-data');
    });

    it('invents one when the name transliterates to nothing', function () {
        // Refusing to create the application would be the worse answer.
        $generated = app(SystemUsernameGenerator::class)->forApplication('🎉🎉🎉');

        expect($generated)->toStartWith('app-');
        expect($generated)->toMatch('/^[a-z_][a-z0-9_-]{0,31}$/');
    });

    it('suffixes around a name the panel already knows', function () {
        SystemUser::create([
            'username' => 'company-blog', 'home_path' => '/home/company-blog', 'shell' => '/bin/bash', 'sudo' => false,
        ]);

        $generated = app(SystemUsernameGenerator::class)->forApplication('Company Blog');

        expect($generated)->not->toBe('company-blog');
        expect($generated)->toStartWith('company-blog-');
        expect($generated)->toMatch('/^[a-z_][a-z0-9_-]{0,31}$/');
    });

    it('suffixes around a name that exists only on the box', function () {
        // The panel's table and /etc/passwd can disagree — an account made by
        // hand has no row here. Checking only the table would hand out a name
        // useradd then refuses, turning a solvable collision into a failure.
        Process::fake(function ($process) {
            $command = commandOf($process);

            if (! str_contains($command, 'getent passwd')) {
                return Process::result(exitCode: 0);
            }

            // Only the exact base name is taken; the suffixed candidates are not.
            return str_ends_with($command, 'getent passwd company-blog')
                ? Process::result(output: 'company-blog:x:1001:1001::/home/company-blog:/bin/bash')
                : Process::result(exitCode: 2);
        });

        expect(app(SystemUsernameGenerator::class)->forApplication('Company Blog'))
            ->toStartWith('company-blog-');
    });

    it('keeps the suffix inside useradd\'s length ceiling', function () {
        SystemUser::create([
            'username' => 'a-very-long-application-name-tha',
            'home_path' => '/home/x', 'shell' => '/bin/bash', 'sudo' => false,
        ]);

        $generated = app(SystemUsernameGenerator::class)
            ->forApplication('A Very Long Application Name That Keeps Going And Going');

        // Truncating *after* adding the suffix is how two sites collide again.
        expect(strlen($generated))->toBeLessThanOrEqual(32);
        expect($generated)->toMatch('/^[a-z_][a-z0-9_-]{0,31}$/');
    });

    it('refuses to guess when the probe cannot answer', function () {
        // sudo refusing, or getent missing. Reading that as "the name is free"
        // means a useradd that fails for a reason nobody can see — so every
        // candidate reads as taken, the attempts run out, and the user gets a
        // sentence rather than a 500 or a colliding account.
        Process::fake(fn () => Process::result(errorOutput: 'sudo: a password is required', exitCode: 1));

        expect(fn () => app(SystemUsernameGenerator::class)->forApplication('Company Blog'))
            ->toThrow(ValidationException::class);
    });
});
