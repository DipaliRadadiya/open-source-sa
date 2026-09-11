<?php

use App\Models\ActivityLog;
use App\Models\FirewallRule;
use App\Models\User;
use App\Services\Server\Firewall\ProtectedRuleGuard;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // Pinned to an empty directory so the SSH port these tests see is the
    // configured default, not whatever the machine running the suite happens
    // to have in /etc/ssh/sshd_config.d.
    $this->sshd = sys_get_temp_dir().'/fw-sshd-'.uniqid();
    mkdir($this->sshd);
    config(['server.sshd_config_dir' => $this->sshd]);
});

/** Move SSH to `$port` by writing a sshd drop-in the panel will read. */
function moveSshTo(int $port): void
{
    file_put_contents(test()->sshd.'/60-port.conf', "Port {$port}\n");
}

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

it('refuses an edit that turns a rule into a copy of another', function () {
    // Creating an identical rule was already rejected by CreateFirewallRule.
    // *Editing* one onto the same port/protocol/action/source was not, so the
    // list ended up showing the same line twice — and because ufw is
    // idempotent, deleting one of the pair changes nothing visible, which
    // reads as a broken delete.
    fakeUfw('active');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertCreated();

    $second = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8081, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertCreated()
        ->json('rule.id');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$second}", ['port_from' => 8080])
        ->assertStatus(422)
        ->assertJsonValidationErrors('port_from');

    expect(FirewallRule::where('port_from', 8080)->count())->toBe(1);
});

it('lets a rule be saved when only its description changed', function () {
    // The check must not compare a rule against itself, or every edit that
    // left the ports alone would be refused as a duplicate of the row being
    // edited. The fields the request does not send come from the stored row,
    // so this comparison sees 8081 either way.
    fakeUfw('active');

    $created = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8081, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertCreated()
        ->json('rule.id');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$created}", ['description' => 'API traffic'])
        ->assertOk();
});

it('does not treat a different source as the same rule', function () {
    // `source_ip` null means "from anywhere", and a rule scoped to one address
    // is a different rule — editing onto it must not be refused.
    fakeUfw('active');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8082, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertCreated();

    $scoped = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', [
            'port_from' => 9000, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => '10.0.0.5',
        ])
        ->assertCreated()
        ->json('rule.id');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$scoped}", ['port_from' => 8082])
        ->assertOk();
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

it('returns the rules it just seeded, so the screen has something to show', function () {
    // The seeding was never the bug; not saying so was. This response used to
    // carry only `enabled` and `default_policy`, so on a freshly set-up server
    // the rules list stayed empty while the note said incoming traffic was
    // blocked — and the four rules only appeared after switching the firewall
    // off and on again, which happened to trigger a refetch.
    fakeUfw('active');

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/firewall/toggle', ['enabled' => true])
        ->assertOk();

    expect(collect($response->json('rules'))->pluck('port_from')->sort()->values()->all())
        ->toBe([22, 80, 443]);
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

describe('enabling with a default rule switched off', function () {
    /*
     * `ProtectedRuleGuard` lets a seeded rule be switched off while the
     * firewall is not enforcing — that escape hatch is deliberate, and is the
     * only way to shut port 80 on a server that needs it shut. But enabling
     * used to apply every default rule regardless of its `enabled` flag, so
     * the port came back open while the panel still showed the rule off, and
     * the guard then locked it in that state the moment ufw was enforcing.
     * A screen saying a port is closed while it is open is the 2026-09-08 bug
     * with the direction reversed.
     */
    it('leaves a web port the user switched off closed', function () {
        fakeUfw('active');
        FirewallRule::create([
            'port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow',
            'origin' => 'default', 'enabled' => false,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/firewall/toggle', ['enabled' => true])
            ->assertOk();

        // Not re-opened on the box…
        Process::assertNotRan(fn ($p) => $p->command === ['ufw', 'allow', '80/tcp']);
        // …and not quietly re-enabled in the table either, so the two agree.
        expect(FirewallRule::where('port_from', 80)->value('enabled'))->toBeFalsy();

        // The other defaults are untouched by one rule being off.
        Process::assertRan(fn ($p) => $p->command === ['ufw', 'allow', '443/tcp']);
        Process::assertRan(fn ($p) => $p->command === ['ufw', '--force', 'enable']);
    });

    it('switches the SSH rule back on rather than enforcing without it', function () {
        // The exception, and it has to be one: SSH is the way back in, and a
        // box whose only door is recorded shut is one `ufw enable` away from
        // being unreachable.
        fakeUfw('active');
        FirewallRule::create([
            'port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow',
            'origin' => 'default', 'enabled' => false,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/firewall/toggle', ['enabled' => true])
            ->assertOk();

        Process::assertRan(fn ($p) => $p->command === ['ufw', 'allow', '22/tcp']);
        expect(FirewallRule::where('port_from', 22)->value('enabled'))->toBeTruthy();
    });

    it('follows SSH to the port it is actually on', function () {
        // The recovery rule is identified by the resolved port, the same way
        // `RecordDefaultRules` seeds it — so on a moved SSH the exception
        // still lands on the door and not on port 22.
        moveSshTo(2222);
        fakeUfw('active');
        FirewallRule::create([
            'port_from' => 2222, 'protocol' => 'tcp', 'action' => 'allow',
            'origin' => 'default', 'enabled' => false,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/firewall/toggle', ['enabled' => true])
            ->assertOk();

        Process::assertRan(fn ($p) => $p->command === ['ufw', 'allow', '2222/tcp']);
        expect(FirewallRule::where('port_from', 2222)->value('enabled'))->toBeTruthy();
    });

    it('records the SSH rule it switched back on', function () {
        // Made in the user's name without being asked, so it goes in the
        // activity trail rather than happening silently.
        fakeUfw('active');
        FirewallRule::create([
            'port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow',
            'origin' => 'default', 'enabled' => false,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/firewall/toggle', ['enabled' => true])
            ->assertOk();

        expect(ActivityLog::where('type', 'firewall')->where('action', 'rule_enabled')->exists())
            ->toBeTrue();
    });
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

it('seeds the port SSH is actually on, not the configured default', function () {
    fakeUfw('active');
    moveSshTo(2222);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson('/api/firewall/toggle', ['enabled' => true])
        ->assertOk();

    // 2222, not 22 — seeding the default would have shut the only way in.
    expect(FirewallRule::where('origin', 'default')->pluck('port_from')->sort()->values()->all())
        ->toBe([80, 443, 2222]);
    Process::assertRan(fn ($p) => $p->command === ['ufw', 'allow', '2222/tcp']);
    Process::assertNotRan(fn ($p) => $p->command === ['ufw', 'allow', '22/tcp']);
});

it('refuses to delete the last SSH rule even when the user made it', function () {
    fakeUfw('active');
    // origin `user`, so the protected-rule check does not fire — this is the
    // rule a hand-made port 22 entry becomes after firstOrCreate keeps it.
    $rule = FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/firewall/rules/{$rule->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rule');

    expect(FirewallRule::find($rule->id))->not->toBeNull();
    Process::assertNotRan(fn ($p) => in_array('delete', $p->command, true));
});

it('allows deleting an SSH rule when another rule still covers the port', function () {
    fakeUfw('active');
    $rule = FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);
    FirewallRule::create(['port_from' => 20, 'port_to' => 30, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/firewall/rules/{$rule->id}")
        ->assertNoContent();

    expect(FirewallRule::find($rule->id))->toBeNull();
});

it('allows deleting the last SSH rule while the firewall is off', function () {
    fakeUfw('inactive');
    $rule = FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/firewall/rules/{$rule->id}")
        ->assertNoContent();
});

it('refuses to switch off the last SSH rule', function () {
    fakeUfw('active');
    $rule = FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$rule->id}", ['enabled' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rule');

    expect(FirewallRule::find($rule->id)->enabled)->toBeTrue();
});

describe('a rule the panel seeded for itself', function () {
    /** The seeded web rule, plus an SSH rule so SshLockoutGuard is never the refuser. */
    function seededWebRule(): FirewallRule
    {
        FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

        return FirewallRule::create(['port_from' => 443, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);
    }

    // Each of these used to answer 200 and run `ufw delete allow 443/tcp`,
    // while *deleting* the identical rule answered 422 — same effect on the
    // running firewall, opposite answers. With deny-incoming that is every
    // site on the box going dark.
    it('cannot be switched off while the firewall is on', function () {
        fakeUfw('active');
        $rule = seededWebRule();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['enabled' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rule');

        expect(FirewallRule::find($rule->id)->enabled)->toBeTrue();
        Process::assertDidntRun(fn ($p) => in_array('delete', $p->command, true));
    });

    it('cannot be turned into a deny rule', function () {
        fakeUfw('active');
        $rule = seededWebRule();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['action' => 'deny'])
            ->assertUnprocessable();

        expect(FirewallRule::find($rule->id)->action)->toBe('allow');
    });

    it('cannot be narrowed to a single source', function () {
        fakeUfw('active');
        $rule = seededWebRule();

        // Scoping 443 to one address takes the site off the public internet
        // just as thoroughly as switching the rule off.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['source_ip' => '10.0.0.5'])
            ->assertUnprocessable();

        expect(FirewallRule::find($rule->id)->source_ip)->toBeNull();
    });

    it('cannot be moved to another port', function () {
        fakeUfw('active');
        $rule = seededWebRule();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['port_from' => 8443])
            ->assertUnprocessable();

        expect(FirewallRule::find($rule->id)->port_from)->toBe(443);
    });

    it('names the port it is refusing about, not the one that was typed', function () {
        fakeUfw('active');
        $rule = seededWebRule();

        // The model is already filled by the time the guard runs, so reading
        // the live attribute would report 8443 — and send someone looking for
        // a row that does not exist.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['port_from' => 8443])
            ->assertUnprocessable()
            ->assertJsonFragment(['rule' => [__('errors/firewall.protected_rule_edit', ['ports' => '443'])]]);
    });

    it('can still be renamed, because a description never reaches ufw', function () {
        fakeUfw('active');
        $rule = seededWebRule();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['description' => 'HTTPS'])
            ->assertOk();

        expect(FirewallRule::find($rule->id)->description)->toBe('HTTPS');
    });

    it('is editable again once the firewall is off', function () {
        // The escape hatch, and the reason the lock is not permanent: nothing
        // is being enforced, so nothing can be cut off.
        fakeUfw('inactive');
        $rule = seededWebRule();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['enabled' => false])
            ->assertOk();

        expect(FirewallRule::find($rule->id)->enabled)->toBeFalse();
    });

    it('does not lock a rule the user made on the same port', function () {
        // The guard asks who owns the rule, not which port it is on.
        fakeUfw('active');
        FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);
        $mine = FirewallRule::create(['port_from' => 443, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$mine->id}", ['enabled' => false])
            ->assertOk();
    });

    it('ignores an origin sent by the client', function () {
        // `origin` is fillable but is not in the request's rules, so
        // `validated()` drops it before the action ever sees it. This is the
        // outer of the two defences; the guard's own is below.
        fakeUfw('active');
        $rule = seededWebRule();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/firewall/rules/{$rule->id}", ['origin' => 'user', 'description' => 'mine'])
            ->assertOk();

        expect(FirewallRule::find($rule->id)->origin)->toBe('default');
    });

    it('reads the stored origin, not a filled one', function () {
        // Driven at the guard rather than the endpoint on purpose: validation
        // already strips `origin`, so an HTTP test here passes whether the
        // guard reads the stored value or the submitted one, and proves
        // nothing about the guard. If the request rules ever gain `origin`,
        // this is the test that stays honest.
        fakeUfw('active');
        $rule = seededWebRule();
        $rule->fill(['origin' => 'user', 'enabled' => false]);

        expect(fn () => app(ProtectedRuleGuard::class)->assertEditable($rule))
            ->toThrow(ValidationException::class);
    });
});

it('rejects a partial update that inverts an existing port range', function () {
    fakeUfw('active');
    $rule = FirewallRule::create(['port_from' => 9000, 'port_to' => 9100, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

    // port_to is absent from the request, so `gte:port_from` had nothing to
    // compare and 9500:9100 used to be saved.
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/firewall/rules/{$rule->id}", ['port_from' => 9500])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('port_to');

    expect(FirewallRule::find($rule->id)->port_from)->toBe(9000);
});

it('rejects a source that matches every address', function () {
    fakeUfw();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => '0.0.0.0/0'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_ip');
});

it('reports an unreadable firewall state as unknown, never as disabled', function () {
    // The rule this has always protected: `false` would read as "nothing is
    // being blocked". What changed is the audience -- this is a screen, and
    // failing the whole request left nothing to look at on precisely the
    // server someone opens it to investigate. Third state instead.
    Process::fake(fn ($process) => in_array('status', $process->command, true)
        ? Process::result(exitCode: 1, errorOutput: 'ERROR: could not read /etc/ufw')
        : Process::result(exitCode: 0));

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/firewall')
        ->assertOk();

    expect($response->json('enabled'))->toBeNull()
        ->and($response->json('enabled'))->not->toBeFalse()
        // A reference to quote, since something on this server is wrong.
        ->and($response->json('status_reference'))->not->toBeEmpty()
        // And the rest of the page still renders.
        ->and($response->json('ssh_port'))->not->toBeNull()
        ->and($response->json())->toHaveKey('rules');
});

it('still refuses to delete a protected rule when the state cannot be read', function () {
    // The half that must not move. `DeleteFirewallRule` asks whether the
    // firewall is on before letting a protected rule go, and an unreadable
    // state has to stop it rather than be read as "off, so deleting is safe".
    // The screen degrades; the guard does not.
    $rule = FirewallRule::create([
        'port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow',
        'source_ip' => null, 'origin' => 'system', 'enabled' => true,
    ]);

    Process::fake(fn ($process) => in_array('status', $process->command, true)
        ? Process::result(exitCode: 1, errorOutput: 'ERROR: could not read /etc/ufw')
        : Process::result(exitCode: 0));

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/firewall/rules/{$rule->id}")
        ->assertStatus(500);

    expect(FirewallRule::find($rule->id))->not->toBeNull();
});

it('denies a viewer without manage from adding a rule', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'firewall', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/firewall/rules', ['port_from' => 8080, 'protocol' => 'tcp', 'action' => 'allow'])
        ->assertForbidden();
});
