<?php

use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Container login for a Docker app's own system user (RC-28): the RC-23 access
 * endpoint grows the app owner's state and the exact lines to type, and gains
 * POST (turn it on) and DELETE (turn it off).
 */
const LOGIN_TEST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH6m9k1QdummyBASE64keyForTestingPurposesXyz deploy@host';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->owner = SystemUser::create([
        'username' => 'adarsh-oc', 'home_path' => '/home/adarsh-oc', 'shell' => '/bin/bash',
        'ssh_access' => false, 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->owner->id,
        'name' => 'OpenClaw', 'slug' => 'openclaw', 'domain' => 'oc.test',
        'site_type' => 'openclaw', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'app_port' => 20001, 'container_port' => 18789, 'status' => 'active',
        'compose' => "services:\n  openclaw:\n    image: ghcr.io/openclaw/openclaw:2026.10.2\n    ports:\n      - \"127.0.0.1:20001:18789\"\n",
    ]);

    // The box: SSH on 22 with passwords on, a directly attached public address,
    // and an sshd that applies the Match block. Every command is kept.
    $this->port = 22;
    $this->passwords = 'yes';
    $this->enforced = true;
    $this->ran = [];

    Process::fake(function ($process) {
        $args = $process->command;
        $this->ran[] = $args;

        return match (true) {
            $args[0] === 'sshd' && ($args[1] ?? null) === '-T' && ($args[2] ?? null) === '-C' => Process::result(
                output: 'forcecommand '.($this->enforced ? '/usr/local/libexec/panel/app-login' : 'none')."\n",
            ),
            $args[0] === 'sshd' => Process::result(output: "port {$this->port}\npasswordauthentication {$this->passwords}\n"),
            $args[0] === 'ip' => Process::result(output: "1.1.1.1 via 10.0.0.1 dev eth0 src 93.184.216.34 uid 0\n"),
            $args[0] === 'cat' => Process::result(exitCode: 1, errorOutput: 'No such file or directory'),
            default => Process::result(exitCode: 0),
        };
    });
    Http::fake();
});

function loginHeaders(?User $user = null): array
{
    return ['Authorization' => 'Bearer '.($user ?? test()->admin)->createToken('t')->plainTextToken];
}

function loginUrl(?Application $application = null): string
{
    return '/api/applications/'.($application ?? test()->application)->id.'/container/access';
}

/** A user holding these permissions; `$manage` ones also at manage. */
function loginViewer(array $permissions, array $manage = []): User
{
    $role = Role::create(['name' => 'Login '.implode(' ', $permissions).' '.implode(' ', $manage), 'slug' => 'login-'.implode('-', [...$permissions, ...$manage])]);

    foreach ($permissions as $permission) {
        $role->permissions()->attach(
            Permission::where('name', $permission)->sole()->id,
            ['view' => true, 'manage' => in_array($permission, $manage, true)],
        );
    }

    $user = User::factory()->create();
    $user->roles()->attach($role);

    return $user;
}

function loginRan(array $command): bool
{
    return in_array($command, test()->ran, true);
}

describe('GET', function () {
    it('describes the owner and the exact lines to type, keeping the RC-23 fields', function () {
        $response = $this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk();

        expect($response->json())->toHaveKeys(['host', 'ssh_port', 'project', 'containers', 'ssh_users'])
            ->and($response->json('owner'))->toBe([
                'username' => 'adarsh-oc', 'ssh_access' => false, 'container_login' => false,
                'has_password' => false, 'ssh_keys' => 0, 'other_apps' => 0,
            ])
            ->and($response->json('password_login'))->toBeTrue()
            ->and($response->json('app'))->toBe('openclaw')
            ->and($response->json('commands'))->toBe([
                'shell' => 'ssh -t adarsh-oc@93.184.216.34',
                'exec_example' => "ssh adarsh-oc@93.184.216.34 'ls -la'",
                'logs' => 'ssh adarsh-oc@93.184.216.34 @logs --tail 100',
            ]);
    });

    it('adds -p for a port other than 22', function () {
        $this->port = 2222;

        expect($this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk()->json('commands'))->toBe([
            'shell' => 'ssh -t -p 2222 adarsh-oc@93.184.216.34',
            'exec_example' => "ssh -p 2222 adarsh-oc@93.184.216.34 'ls -la'",
            'logs' => 'ssh -p 2222 adarsh-oc@93.184.216.34 @logs --tail 100',
        ]);
    });

    it('picks the app with @<app> when the owner has several Docker apps', function () {
        Application::forceCreate([
            'system_user_id' => $this->owner->id,
            'name' => 'Tools', 'slug' => 'tools', 'domain' => 'tools.test',
            'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
            'image' => 'nginx:1.27-alpine', 'container_port' => 80, 'app_port' => 20002, 'status' => 'active',
        ]);

        $response = $this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk();

        expect($response->json('commands'))->toBe([
            'shell' => 'ssh -t adarsh-oc@93.184.216.34 @openclaw',
            'exec_example' => "ssh adarsh-oc@93.184.216.34 '@openclaw ls -la'",
            'logs' => 'ssh adarsh-oc@93.184.216.34 @logs openclaw --tail 100',
        ])->and($response->json('cli_examples.0.command'))->toBe("ssh adarsh-oc@93.184.216.34 '@openclaw openclaw devices list'");
    });

    it('gives the recipe\'s command examples as full one-liners', function () {
        expect($this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk()->json('cli_examples'))->toBe([
            ['label' => 'List pending browsers', 'command' => "ssh adarsh-oc@93.184.216.34 'openclaw devices list'"],
            ['label' => 'Approve a browser', 'command' => "ssh adarsh-oc@93.184.216.34 'openclaw devices approve <requestId>'"],
        ]);
    });

    it('has no examples for an app that is not a recipe, and counts the owner\'s other apps', function () {
        $this->application->forceFill(['site_type' => 'docker'])->save();
        Application::forceCreate([
            'system_user_id' => $this->owner->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
            'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => 'public_html', 'status' => 'active',
        ]);

        $response = $this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk();

        expect($response->json('cli_examples'))->toBe([])
            ->and($response->json('owner.other_apps'))->toBe(1);
    });

    it('reports password login off as the server has it', function () {
        $this->passwords = 'no';

        expect($this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk()->json('password_login'))->toBeFalse();
    });

    it('shows the owner block on the card\'s own permission, and the user list only with system_user', function () {
        $viewer = loginViewer(['application', 'app_compose']);

        $response = $this->withHeaders(loginHeaders($viewer))->getJson(loginUrl())->assertOk();

        expect($response->json('owner.username'))->toBe('adarsh-oc')
            ->and($response->json('ssh_users'))->toBe([])
            ->and($response->getContent())->not->toContain('home_path');
    });

    it('still reads sshd once for the port and the password setting together', function () {
        $this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk();
        $this->withHeaders(loginHeaders())->getJson(loginUrl())->assertOk();

        expect(collect($this->ran)->filter(fn (array $args): bool => $args[0] === 'sshd')->count())->toBe(1);
    });
});

describe('POST', function () {
    it('turns container login on with a key: key, map, group, check, then SSH', function () {
        $response = $this->withHeaders(loginHeaders())
            ->postJson(loginUrl(), ['ssh_key' => LOGIN_TEST_KEY])
            ->assertOk();

        $this->owner->refresh();

        expect($this->owner->container_login)->toBeTrue()
            ->and($this->owner->ssh_access)->toBeTrue()
            ->and($this->owner->sshKeys()->count())->toBe(1)
            ->and($response->json('owner.container_login'))->toBeTrue()
            ->and($response->json('owner.ssh_keys'))->toBe(1);

        $at = fn (array $command): int => (int) array_search($command, $this->ran, true);

        expect(loginRan(['groupadd', '-f', 'sv-app-shell']))->toBeTrue()
            ->and(loginRan(['mv', '-f', '/etc/panel-app-shell/map.json.new', '/etc/panel-app-shell/map.json']))->toBeTrue()
            // In the group before sshd is asked, and asked before SSH goes on.
            ->and($at(['usermod', '-aG', 'sv-app-shell', 'adarsh-oc']))
            ->toBeLessThan($at(['sshd', '-T', '-C', 'user=adarsh-oc,host=localhost,addr=127.0.0.1']))
            ->and($at(['sshd', '-T', '-C', 'user=adarsh-oc,host=localhost,addr=127.0.0.1']))
            ->toBeLessThan($at(['usermod', '-aG', 'ssh-users', 'adarsh-oc']))
            // No sudo grant, no docker group, ever.
            ->and(collect($this->ran)->contains(fn ($args) => in_array('docker', $args, true) || in_array('sudo', $args, true)))->toBeFalse();
    });

    it('sets a password in the same call', function () {
        $this->withHeaders(loginHeaders())
            ->postJson(loginUrl(), ['password' => 'Correct-Horse-Battery-9'])
            ->assertOk()
            ->assertJsonPath('owner.has_password', true);

        expect(loginRan(['chpasswd']))->toBeTrue()
            ->and($this->owner->refresh()->container_login)->toBeTrue();
    });

    it('gives a nologin user a shell sshd can run the forced command with', function () {
        $this->owner->update(['shell' => '/usr/sbin/nologin']);

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), [])->assertOk();

        expect(loginRan(['usermod', '-s', '/bin/bash', 'adarsh-oc']))->toBeTrue();
    });

    it('asks for confirmation when the owner also has non-Docker apps', function () {
        Application::forceCreate([
            'system_user_id' => $this->owner->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
            'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => 'public_html', 'status' => 'active',
        ]);

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_container_only');

        expect(loginRan(['usermod', '-aG', 'sv-app-shell', 'adarsh-oc']))->toBeFalse();

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), ['confirm_container_only' => true])->assertOk();
    });

    it('requires a key when password login is off and the user has none', function () {
        $this->passwords = 'no';

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), ['password' => 'Correct-Horse-Battery-9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ssh_key');

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), ['ssh_key' => LOGIN_TEST_KEY])->assertOk();
    });

    it('validates the key and the password like the System Users screen', function () {
        $this->withHeaders(loginHeaders())->postJson(loginUrl(), ['ssh_key' => 'not-a-key', 'password' => "short\n"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssh_key', 'password']);
    });

    it('backs out when sshd on this server would not apply the block', function () {
        $this->enforced = false;

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('container_login');

        $this->owner->refresh();

        expect(loginRan(['gpasswd', '-d', 'adarsh-oc', 'sv-app-shell']))->toBeTrue()
            ->and($this->owner->container_login)->toBeFalse()
            // SSH never went on: without the block, that would be a host shell.
            ->and($this->owner->ssh_access)->toBeFalse();
    });

    it('fails without touching the user when the server pieces cannot be installed', function () {
        Process::fake(function ($process) {
            $this->ran[] = $process->command;

            return match ($process->command[0]) {
                'visudo' => Process::result(exitCode: 1, errorOutput: 'syntax error'),
                'cat' => Process::result(exitCode: 1),
                'sshd' => Process::result(output: "port 22\npasswordauthentication yes\n"),
                default => Process::result(exitCode: 0),
            };
        });

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), [])->assertStatus(500);

        expect(loginRan(['usermod', '-aG', 'sv-app-shell', 'adarsh-oc']))->toBeFalse()
            ->and($this->owner->refresh()->container_login)->toBeFalse();
    });
});

describe('DELETE', function () {
    it('takes the user out of the group and leaves SSH as it was', function () {
        $this->owner->update(['container_login' => true, 'ssh_access' => true]);

        $this->withHeaders(loginHeaders())->deleteJson(loginUrl())
            ->assertOk()
            ->assertJsonPath('owner.container_login', false)
            ->assertJsonPath('owner.ssh_access', true);

        expect(loginRan(['gpasswd', '-d', 'adarsh-oc', 'sv-app-shell']))->toBeTrue()
            ->and($this->owner->refresh()->container_login)->toBeFalse();
    });
});

describe('who may', function () {
    it('is refused without system_user manage, either way', function () {
        $viewer = loginViewer(['application', 'app_compose', 'system_user']);

        $this->withHeaders(loginHeaders($viewer))->postJson(loginUrl(), [])->assertForbidden();
        $this->withHeaders(loginHeaders($viewer))->deleteJson(loginUrl())->assertForbidden();

        expect(loginRan(['usermod', '-aG', 'sv-app-shell', 'adarsh-oc']))->toBeFalse()
            ->and(loginRan(['gpasswd', '-d', 'adarsh-oc', 'sv-app-shell']))->toBeFalse();
    });

    it('is allowed with system_user manage, without being an administrator', function () {
        $manager = loginViewer(['application', 'app_compose', 'system_user'], manage: ['system_user']);

        $this->withHeaders(loginHeaders($manager))->postJson(loginUrl(), [])->assertOk();
        $this->withHeaders(loginHeaders($manager))->deleteJson(loginUrl())->assertOk();
    });

    it('is refused for a site that is not a container, like the read', function () {
        $this->application->forceFill(['serving_profile' => 'php'])->save();

        $this->withHeaders(loginHeaders())->postJson(loginUrl(), [])->assertStatus(422);
        $this->withHeaders(loginHeaders())->deleteJson(loginUrl())->assertStatus(422);

        $php = Application::forceCreate([
            'system_user_id' => $this->owner->id,
            'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
            'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => 'public_html', 'status' => 'active',
        ]);

        $this->withHeaders(loginHeaders())->postJson(loginUrl($php), [])->assertNotFound();
        $this->withHeaders(loginHeaders())->deleteJson(loginUrl($php))->assertNotFound();

        expect(loginRan(['usermod', '-aG', 'sv-app-shell', 'adarsh-oc']))->toBeFalse();
    });
});

describe('DELETE with disable_ssh — also turn off the owner\'s SSH (Dipali, 2026-10-30)', function () {
    // QA: "Turn off" made the card say Off while the same key still logged in, now
    // to a shell on the server. Container login off leaves SSH on by design; the
    // user is now asked, and the server — which knows the owner — does both.

    it('turns off container login and the owner\'s SSH when asked', function () {
        $this->owner->update(['container_login' => true, 'ssh_access' => true]);

        $this->withHeaders(loginHeaders())->deleteJson(loginUrl(), ['disable_ssh' => true])
            ->assertOk()
            ->assertJsonPath('owner.container_login', false)
            ->assertJsonPath('owner.ssh_access', false);

        expect(loginRan(['gpasswd', '-d', 'adarsh-oc', 'sv-app-shell']))->toBeTrue()
            ->and(loginRan(['gpasswd', '-d', 'adarsh-oc', 'ssh-users']))->toBeTrue()
            ->and($this->owner->refresh()->ssh_access)->toBeFalse();
    });

    it('leaves SSH exactly as it was when the flag is false', function () {
        $this->owner->update(['container_login' => true, 'ssh_access' => true]);

        $this->withHeaders(loginHeaders())->deleteJson(loginUrl(), ['disable_ssh' => false])
            ->assertOk()
            ->assertJsonPath('owner.ssh_access', true);

        expect(loginRan(['gpasswd', '-d', 'adarsh-oc', 'ssh-users']))->toBeFalse();
    });

    it('does not touch SSH that is already off', function () {
        $this->owner->update(['container_login' => true, 'ssh_access' => false]);

        $this->withHeaders(loginHeaders())->deleteJson(loginUrl(), ['disable_ssh' => true])->assertOk();

        expect(loginRan(['gpasswd', '-d', 'adarsh-oc', 'ssh-users']))->toBeFalse();
    });

    it('refuses a value that is not a boolean, and changes nothing', function () {
        $this->owner->update(['container_login' => true, 'ssh_access' => true]);

        $this->withHeaders(loginHeaders())->deleteJson(loginUrl(), ['disable_ssh' => 'banana'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('disable_ssh');

        expect(loginRan(['gpasswd', '-d', 'adarsh-oc', 'sv-app-shell']))->toBeFalse()
            ->and($this->owner->refresh()->container_login)->toBeTrue();
    });

    it('is refused without system_user manage, and runs nothing', function () {
        $this->owner->update(['container_login' => true, 'ssh_access' => true]);
        $viewer = loginViewer(['application', 'app_compose', 'system_user']);

        $this->withHeaders(loginHeaders($viewer))->deleteJson(loginUrl(), ['disable_ssh' => true])->assertForbidden();

        expect(loginRan(['gpasswd', '-d', 'adarsh-oc', 'ssh-users']))->toBeFalse()
            ->and($this->owner->refresh()->ssh_access)->toBeTrue();
    });

    it('keeps container login off and says so when the SSH step fails', function () {
        // After container login, on purpose: a failure here leaves today's state.
        $this->owner->update(['container_login' => true, 'ssh_access' => true]);
        $base = Process::fake(function ($process) {
            $args = $process->command;
            $this->ran[] = $args;

            return ($args[0] ?? '') === 'gpasswd' && in_array('ssh-users', $args, true)
                ? Process::result(exitCode: 1, errorOutput: 'gpasswd: failure')
                : Process::result(exitCode: 0);
        });

        $response = $this->withHeaders(loginHeaders())->deleteJson(loginUrl(), ['disable_ssh' => true]);

        expect($response->status())->toBeGreaterThanOrEqual(400)
            ->and($response->json('reference'))->not->toBeEmpty()
            ->and($this->owner->refresh()->container_login)->toBeFalse()
            ->and($this->owner->ssh_access)->toBeTrue();
    });

    it('tells the card when turning SSH off would NOT be enforced', function () {
        // This box's sshd has no AllowGroups: leaving the group stops nothing.
        $this->withHeaders(loginHeaders())->getJson(loginUrl())
            ->assertOk()
            ->assertJsonPath('ssh_access_enforced', false);
    });

    it('tells the card when it would be, reading sshd once for everything', function () {
        Process::fake(function ($process) {
            $args = $process->command;
            $this->ran[] = $args;

            return match (true) {
                $args[0] === 'sshd' && ($args[1] ?? null) === '-T' && ($args[2] ?? null) === '-C' => Process::result(output: "forcecommand /usr/local/libexec/panel/app-login\n"),
                // `sshd -T` prints a list keyword once per entry.
                $args[0] === 'sshd' => Process::result(output: "port 22\npasswordauthentication yes\nallowgroups sudo\nallowgroups ssh-users\n"),
                default => Process::result(exitCode: 0),
            };
        });

        $this->withHeaders(loginHeaders())->getJson(loginUrl())
            ->assertOk()
            ->assertJsonPath('ssh_access_enforced', true);

        expect(collect($this->ran)->filter(fn (array $a) => $a === ['sshd', '-T'])->count())->toBe(1);
    });
});
