<?php

use App\Models\FirewallRule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

/** Fake ufw with a given status ('active'|'inactive'); other commands succeed. */
function fakeUfw(string $status = 'active'): void
{
    Process::fake(function ($process) use ($status) {
        if (in_array('status', $process->command, true)) {
            return Process::result(output: "Status: {$status}\nDefault: deny (incoming), allow (outgoing), disabled (routed)\n");
        }

        return Process::result(exitCode: 0);
    });
}

it('reports live status and rules', function () {
    fakeUfw('active');
    FirewallRule::create(['port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/firewall')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('default_policy.incoming', 'deny')
        ->assertJsonPath('default_policy.outgoing', 'allow')
        ->assertJsonCount(1, 'rules')
        ->assertJsonPath('rules.0.protected', true);
});

it('adds an allow rule for a port, applying it via ufw', function () {
    fakeUfw();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow']);

    $response->assertCreated()
        ->assertJsonPath('rule.origin', 'user')
        ->assertJsonPath('rule.protected', false)
        ->assertJsonPath('rule.summary', 'Allow 8080/tcp from Anywhere');

    Process::assertRan(fn ($p) => $p->command === ['ufw', 'allow', '8080/tcp']);
});

it('adds a rule with a CIDR source', function () {
    fakeUfw();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 443, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => '1.2.3.0/24'])
        ->assertCreated()
        ->assertJsonPath('rule.summary', 'Allow 443/tcp from 1.2.3.0/24');

    Process::assertRan(fn ($p) => $p->command === ['ufw', 'allow', 'from', '1.2.3.0/24', 'to', 'any', 'port', '443', 'proto', 'tcp']);
});

it('rejects an invalid source', function () {
    fakeUfw();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 443, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => 'not-an-ip'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_ip');
});

it('rejects a duplicate rule', function () {
    fakeUfw();
    FirewallRule::create(['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('port_from');
});

it('rejects an out-of-range port', function () {
    fakeUfw();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 70000, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('port_from');
});

it('deletes a user rule, removing it from ufw', function () {
    fakeUfw();
    $rule = FirewallRule::create(['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/firewall/rules/{$rule->id}")
        ->assertNoContent();

    expect(FirewallRule::find($rule->id))->toBeNull();
    Process::assertRan(fn ($p) => $p->command === ['ufw', 'delete', 'allow', '8080/tcp']);
});

it('refuses to delete a protected default rule while the firewall is enabled', function () {
    fakeUfw('active');
    $rule = FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/firewall/rules/{$rule->id}")
        ->assertUnprocessable();

    expect(FirewallRule::find($rule->id))->not->toBeNull();
});

it('enables the firewall, seeding default rules first', function () {
    fakeUfw('active');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/firewall/toggle', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('enabled', true);

    // SSH (22) + default web ports (80, 443) seeded as default-origin rules
    expect(FirewallRule::where('origin', 'default')->pluck('port_from')->sort()->values()->all())
        ->toBe([22, 80, 443]);
    // secure default policy set, then enabled
    Process::assertRan(fn ($p) => $p->command === ['ufw', 'default', 'deny', 'incoming']);
    Process::assertRan(fn ($p) => $p->command === ['ufw', 'default', 'allow', 'outgoing']);
    Process::assertRan(fn ($p) => $p->command === ['ufw', '--force', 'enable']);
});

it('refuses to enable the firewall when a default recovery rule cannot be applied', function () {
    Process::fake(function ($process) {
        return in_array('ufw', $process->command, true)
            && in_array('allow', $process->command, true)
            && in_array('22/tcp', $process->command, true)
            ? Process::result(exitCode: 1, errorOutput: 'rule failed')
            : Process::result(exitCode: 0);
    });

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/firewall/toggle', ['enabled' => true])
        ->assertStatus(500);

    Process::assertNotRan(fn ($p) => in_array('ufw', $p->command, true)
        && in_array('--force', $p->command, true)
        && in_array('enable', $p->command, true));
});

it('disables the firewall but keeps the rules', function () {
    fakeUfw('inactive');
    FirewallRule::create(['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/firewall/toggle', ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('enabled', false);

    expect(FirewallRule::count())->toBe(1);
    Process::assertRan(fn ($p) => $p->command === ['ufw', 'disable']);
});

it('returns localized service presets for the dropdown', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/firewall/presets');

    $response->assertOk()
        ->assertJsonPath('presets.0.key', 'ssh')
        ->assertJsonPath('presets.0.port', 22);

    $presets = $response->json('presets');
    expect(collect($presets)->pluck('key'))->toContain('http', 'https', 'mysql', 'custom');
    expect(collect($presets)->firstWhere('key', 'custom')['port'])->toBeNull();

    // labels are localized
    $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept-Language' => 'es'])
        ->getJson('/api/firewall/presets')
        ->assertJsonPath('presets.9.label', 'Puerto personalizado');
});

it('denies a viewer without manage from adding a rule', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'firewall', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertForbidden();
});
