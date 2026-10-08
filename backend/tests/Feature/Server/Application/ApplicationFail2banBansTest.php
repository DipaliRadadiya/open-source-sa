<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * FS-C45: an application's Fail2ban page can see and change what its own jail
 * has banned, under the application's permission — and only in that jail.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active',
        'fail2ban_jail_name' => 'panel-site-shop',
    ]);

    $this->f2b = new stdClass;
    $this->f2b->jails = ['sshd', 'panel-site-shop', 'panel-site-blog'];
    $this->f2b->banned = ['panel-site-shop' => ['203.0.113.9'], 'panel-site-blog' => ['198.51.100.7'], 'sshd' => ['192.0.2.4']];
    $this->f2b->ran = [];
});

function fakeSiteJails(): void
{
    Process::fake(function ($process) {
        $args = array_values(array_filter((array) $process->command, fn ($a) => ! in_array($a, ['sudo', '-n'], true)));
        test()->f2b->ran[] = implode(' ', $args);
        $state = test()->f2b;

        if (($args[0] ?? '') !== 'fail2ban-client') {
            return Process::result();
        }

        return match (true) {
            ($args[1] ?? '') === 'ping' => Process::result(output: 'Server replied: pong'),
            ($args[1] ?? '') === 'status' && ! isset($args[2]) => Process::result(output: "Status\n|- Number of jail:\t3\n`- Jail list:\t".implode(', ', $state->jails)."\n"),
            ($args[1] ?? '') === 'get' && ($args[3] ?? '') === 'banned' => Process::result(
                output: '['.implode(', ', array_map(fn ($ip) => "'{$ip}'", $state->banned[$args[2]] ?? [])).']',
            ),
            ($args[1] ?? '') === 'set' && ($args[3] ?? '') === 'banip' => tap(Process::result(output: '1'), function () use ($state, $args) {
                $state->banned[$args[2]][] = $args[4];
            }),
            ($args[1] ?? '') === 'set' && ($args[3] ?? '') === 'unbanip' => tap(Process::result(output: '1'), function () use ($state, $args) {
                $state->banned[$args[2]] = array_values(array_diff($state->banned[$args[2]] ?? [], [$args[4]]));
            }),
            default => Process::result(),
        };
    });
}

function siteBansUrl(string $suffix = ''): string
{
    return '/api/applications/'.test()->application->id.'/fail2ban/bans'.$suffix;
}

it('lists what this application\'s jail has banned, and nothing from other jails', function () {
    fakeSiteJails();

    $this->actingAs($this->admin)->getJson(siteBansUrl())
        ->assertOk()
        ->assertJsonPath('jail', 'panel-site-shop')
        ->assertJsonPath('banned', ['203.0.113.9']);
});

it('bans and unbans in this application\'s jail only', function () {
    fakeSiteJails();

    $this->actingAs($this->admin)->postJson(siteBansUrl(), ['ip' => '198.51.100.20'])
        ->assertOk()
        ->assertJsonPath('ban.jail', 'panel-site-shop');

    expect($this->f2b->ran)->toContain('fail2ban-client set panel-site-shop banip 198.51.100.20');

    $this->actingAs($this->admin)->deleteJson(siteBansUrl('/203.0.113.9'))->assertOk();

    expect($this->f2b->banned['panel-site-shop'])->toBe(['198.51.100.20'])
        ->and(ActivityLog::where('action', 'fail2ban_ip_banned')->exists())->toBeTrue();

    // An address banned by another site's jail is not this page's to release.
    $this->actingAs($this->admin)->deleteJson(siteBansUrl('/198.51.100.7'))->assertNotFound();
    expect($this->f2b->banned['panel-site-blog'])->toBe(['198.51.100.7']);
});

it('works with only the application\'s permission, view to list and manage to change', function () {
    fakeSiteJails();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'app_fail2ban');

    $this->actingAs($viewer)->getJson(siteBansUrl())->assertOk();
    $this->actingAs($viewer)->postJson(siteBansUrl(), ['ip' => '198.51.100.20'])->assertForbidden();
    $this->actingAs($viewer)->deleteJson(siteBansUrl('/203.0.113.9'))->assertForbidden();

    expect(collect($this->f2b->ran)->contains(fn ($c) => str_contains($c, 'banip')))->toBeFalse();
});

it('refuses the caller\'s own address and says when the jail is not running', function () {
    fakeSiteJails();

    $this->actingAs($this->admin)->postJson(siteBansUrl(), ['ip' => '127.0.0.1'])
        ->assertUnprocessable();

    $this->f2b->jails = ['sshd'];

    $this->actingAs($this->admin)->getJson(siteBansUrl())
        ->assertOk()
        ->assertJsonPath('jail', null)
        ->assertJsonPath('banned', []);
    $this->actingAs($this->admin)->postJson(siteBansUrl(), ['ip' => '198.51.100.20'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'jail_not_enabled');
});
