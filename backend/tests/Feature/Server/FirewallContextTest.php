<?php

use App\Models\FirewallRule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->sshd = sys_get_temp_dir().'/sv-oss-fw-sshd-'.getmypid();
    File::deleteDirectory($this->sshd);
    File::makeDirectory($this->sshd, 0755, true);
    config(['server.sshd_config_dir' => $this->sshd]);
});

afterEach(fn () => File::deleteDirectory($this->sshd));

/**
 * `ss -tulpnH` as an unprivileged user: the Process column is blank for
 * anything we don't own, which is nearly everything.
 */
function fakeFirewallEnv(string $ss = ''): void
{
    Process::fake(function ($process) use ($ss) {
        return match ($process->command[0] ?? '') {
            'ss' => Process::result(output: $ss),
            default => Process::result(output: "Status: active\nDefault: deny (incoming), allow (outgoing)\n"),
        };
    });
}

function firewall(): array
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/firewall')->json();
}

it('tells the caller its own address, so "only my IP" is one click', function () {
    fakeFirewallEnv();

    // Without it, people leave a port open to everyone rather than go and
    // find out what their address is.
    expect(firewall()['your_ip'])->toBe('127.0.0.1');
});

it('reports the SSH port itself, rather than making the UI ask Settings', function () {
    File::put("{$this->sshd}/60-panel.conf", "Port 2222\n");
    fakeFirewallEnv();

    // A firewall-only user calling Settings gets a 403 and falls back to 22 —
    // and being wrong about the SSH port here is how somebody locks
    // themselves out.
    expect(firewall()['ssh_port'])->toBe(2222);

    File::delete("{$this->sshd}/60-panel.conf");
    expect(firewall()['ssh_port'])->toBe(22);
});

it('reports what is listening, and where it is bound', function () {
    fakeFirewallEnv(
        "tcp LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:((\"nginx\",pid=1073,fd=6))\n"
        ."tcp LISTEN 0 511 127.0.0.1:6379 0.0.0.0:*\n"
    );

    $listening = collect(firewall()['listening'])->keyBy('port');

    // Bound to 0.0.0.0, a rule can expose it; bound to loopback, no rule can.
    // That distinction is most of the value of showing this at all.
    expect($listening[80]['public'])->toBeTrue()
        ->and($listening[80]['program'])->toBe('nginx')
        ->and($listening[6379]['public'])->toBeFalse()
        ->and($listening[6379]['address'])->toBe('127.0.0.1');
});

it('says the program is unknown rather than guessing it from the port', function () {
    // Unprivileged, `ss` omits the process for anything we do not own.
    fakeFirewallEnv("tcp LISTEN 0 511 0.0.0.0:3306 0.0.0.0:*\n");

    // Naming this "MySQL" because 3306 is conventional would be a guess, and
    // a wrong name on a firewall screen is worse than no name.
    expect(collect(firewall()['listening'])->firstWhere('port', 3306)['program'])->toBeNull();
});

it('counts a service bound to IPv4 and IPv6 once', function () {
    fakeFirewallEnv(
        "tcp LISTEN 0 511 0.0.0.0:443 0.0.0.0:*\n"
        ."tcp LISTEN 0 511 [::]:443 [::]:*\n"
    );

    // One thing is listening on 443; the screen should say so once.
    expect(collect(firewall()['listening'])->where('port', 443))->toHaveCount(1);
});

it('names risky ports from what is installed, with a reason', function () {
    fakeFirewallEnv();

    $risky = collect(firewall()['risky_ports'])->keyBy('port');

    // Derived here rather than hardcoded in the UI: the backend knows which
    // engines exist and on which port, so the warning is about this server.
    expect($risky->has(3306))->toBeTrue()
        ->and($risky[3306]['reason'])->toContain('database')
        ->and($risky->has(6379))->toBeTrue();
});

it('lets a rule be switched off without losing it', function () {
    fakeFirewallEnv();
    $rule = FirewallRule::create([
        'port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user', 'enabled' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$rule->id}", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('rule.enabled', false);

    // Still here — testing whether a rule matters should not mean deleting it
    // and hoping it gets retyped correctly.
    expect(FirewallRule::find($rule->id))->not->toBeNull();
});

it('does not touch the firewall when only the description changes', function () {
    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return Process::result(output: "Status: active\n");
    });

    $rule = FirewallRule::create([
        'port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user',
        'description' => 'Aplication', 'enabled' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$rule->id}", ['description' => 'Application'])
        ->assertOk();

    // Fixing a typo in a label has no business removing a live firewall rule.
    expect(collect($runs)->filter(fn ($c) => ($c[0] ?? '') === 'ufw' && in_array('delete', $c, true)))->toBeEmpty();
});

it('adds the replacement before removing the original when the rule changes', function () {
    $runs = new ArrayObject;
    Process::fake(function ($process) use ($runs) {
        $runs[] = $process->command;

        return Process::result(output: "Status: active\n");
    });

    $rule = FirewallRule::create([
        'port_from' => 8080, 'protocol' => 'tcp', 'action' => 'deny', 'origin' => 'user', 'enabled' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$rule->id}", ['port_from' => 9090])
        ->assertOk();

    $ufw = collect($runs)->filter(fn ($c) => ($c[0] ?? '') === 'ufw')->values();
    $addAt = $ufw->search(fn ($c) => ! in_array('delete', $c, true) && in_array('9090/tcp', $c, true));
    $deleteAt = $ufw->search(fn ($c) => in_array('delete', $c, true));

    // Deleting first leaves a window with neither rule in place; if the add
    // then failed, a deny rule would have quietly become "allowed".
    expect($addAt)->not->toBeFalse()
        ->and($deleteAt)->not->toBeFalse()
        ->and($addAt)->toBeLessThan($deleteAt);
});

it('denies editing to a view-only user', function () {
    fakeFirewallEnv();
    $rule = FirewallRule::create([
        'port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user',
    ]);

    $user = User::factory()->create();
    grantPermission($user, 'firewall', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/firewall')->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/firewall/rules/{$rule->id}", ['enabled' => false])
        ->assertForbidden();
});
