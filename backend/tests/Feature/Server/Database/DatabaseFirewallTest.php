<?php

use App\Models\Database;
use App\Models\FirewallRule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * The firewall rule a remote database user gets. It was opened as a `default`
 * rule — the origin of the panel's own SSH/HTTP rules — so it could not be
 * removed while the firewall was on, and nothing ever closed it: deleting the
 * user left the database port open to that address for good (nginx test box,
 * 2026-09-26).
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    Process::fake(function ($process) {
        if (($process->command[0] ?? '') === 'ufw' && in_array('status', $process->command, true)) {
            return Process::result(output: "Status: active\nDefault: deny (incoming), allow (outgoing), disabled (routed)\n");
        }

        $sql = (string) ($process->input ?? '');

        return match (true) {
            str_contains($sql, 'VERSION()') => Process::result(output: '10.11.8-MariaDB'),
            // Already listening on every address, so no restart is asked for.
            str_contains($sql, 'bind_address') => Process::result(output: "bind_address\t0.0.0.0"),
            str_contains($sql, 'SELECT COALESCE') => Process::result(output: '0'),
            default => Process::result(exitCode: 0),
        };
    });
});

function fwApi(string $method, string $url, array $body = [])
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->json($method, $url, $body);
}

function remoteUser(Database $db, string $username, string $host)
{
    return fwApi('POST', "/api/databases/{$db->id}/users", [
        'username' => $username,
        'password' => 'Remote-Pass-123',
        'connection_preference' => 'remote',
        'host' => $host,
        'restart_cluster' => true,
    ])->assertCreated();
}

function ufwDeleteFor(string $source): Closure
{
    return fn ($p) => ($p->command[0] ?? '') === 'ufw'
        && in_array('delete', $p->command, true)
        && in_array($source, $p->command, true);
}

it('opens a rule the administrator may remove while the firewall is on', function () {
    $db = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    remoteUser($db, 'shop_remote', '203.0.113.7');

    $rule = FirewallRule::query()->where('port_from', 3306)->sole();
    expect($rule->origin)->toBe('db_user')
        ->and($rule->source_ip)->toBe('203.0.113.7');

    fwApi('DELETE', "/api/firewall/rules/{$rule->id}")->assertNoContent();
    expect(FirewallRule::query()->count())->toBe(0);
});

it('closes the rule when the last remote user for that address is deleted', function () {
    $db = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    $user = remoteUser($db, 'shop_remote', '203.0.113.7')->json('user.id');

    fwApi('DELETE', "/api/databases/{$db->id}/users/{$user}")->assertNoContent();

    expect(FirewallRule::query()->where('port_from', 3306)->exists())->toBeFalse();
    Process::assertRan(ufwDeleteFor('203.0.113.7'));
});

it('keeps the rule while another user on the same port and address still needs it', function () {
    // MySQL and MariaDB share 3306: a user on either keeps the rule open.
    $shop = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    $blog = Database::create(['name' => 'blog', 'engine' => 'mysql']);
    $first = remoteUser($shop, 'shop_remote', '203.0.113.7')->json('user.id');
    remoteUser($blog, 'blog_remote', '203.0.113.7');

    fwApi('DELETE', "/api/databases/{$shop->id}/users/{$first}")->assertNoContent();

    expect(FirewallRule::query()->where('port_from', 3306)->exists())->toBeTrue();
    Process::assertDidntRun(ufwDeleteFor('203.0.113.7'));
});

it('closes the rule when the database holding the remote user is deleted', function () {
    $db = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    remoteUser($db, 'shop_remote', '203.0.113.7');

    fwApi('DELETE', "/api/databases/{$db->id}")->assertNoContent();

    expect(FirewallRule::query()->where('port_from', 3306)->exists())->toBeFalse();
});

it('closes the old address when a remote user is moved to another one', function () {
    $db = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    $user = remoteUser($db, 'shop_remote', '203.0.113.7')->json('user.id');

    fwApi('PATCH', "/api/databases/{$db->id}/users/{$user}", [
        'connection_preference' => 'remote',
        'host' => '198.51.100.9',
    ])->assertOk();

    expect(FirewallRule::query()->where('port_from', 3306)->pluck('source_ip')->all())->toBe(['198.51.100.9']);
});

it('never closes a rule the administrator wrote for the same port and address', function () {
    FirewallRule::create(['port_from' => 3306, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => '203.0.113.7', 'origin' => 'user']);
    $db = Database::create(['name' => 'shop', 'engine' => 'mariadb']);
    $user = remoteUser($db, 'shop_remote', '203.0.113.7')->json('user.id');

    fwApi('DELETE', "/api/databases/{$db->id}/users/{$user}")->assertNoContent();

    expect(FirewallRule::query()->where('port_from', 3306)->value('origin'))->toBe('user');
});

it('still protects the panel\'s own seeded rules', function () {
    $ssh = FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

    fwApi('DELETE', "/api/firewall/rules/{$ssh->id}")->assertUnprocessable();
});
