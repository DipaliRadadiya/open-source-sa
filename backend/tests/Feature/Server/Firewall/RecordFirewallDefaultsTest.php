<?php

use App\Models\FirewallRule;
use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // Pinned to an empty directory so the SSH port these tests see is the
    // configured default, not whatever the machine running the suite happens
    // to have in /etc/ssh/sshd_config.d.
    $this->sshd = sys_get_temp_dir().'/fw-record-sshd-'.uniqid();
    mkdir($this->sshd);
    config([
        'server.sshd_config_dir' => $this->sshd,
        'server.ssh_port' => 22,
        'server.default_firewall_ports' => [80, 443],
    ]);
});

it('records a default allow rule for ssh and the configured web ports', function () {
    $this->artisan('firewall:record-defaults')->assertSuccessful();

    expect(FirewallRule::query()->pluck('port_from')->all())->toEqualCanonicalizing([22, 80, 443]);

    $rule = FirewallRule::query()->where('port_from', 443)->sole();

    expect($rule->protocol)->toBe('tcp')
        ->and($rule->action)->toBe('allow')
        ->and($rule->source_ip)->toBeNull()
        ->and($rule->port_to)->toBeNull()
        ->and($rule->origin)->toBe('default')
        // The `ufw allow` has already run on the box by the time the installer
        // calls this, so the rule genuinely exists. Whether the firewall is
        // *enforcing* is a separate flag read live from ufw.
        ->and($rule->enabled)->toBeTrue();
});

it('is idempotent', function () {
    $this->artisan('firewall:record-defaults')->assertSuccessful();
    $this->artisan('firewall:record-defaults')->assertSuccessful();

    expect(FirewallRule::query()->count())->toBe(3);
});

it('records the port ssh is actually on, not the configured default', function () {
    file_put_contents($this->sshd.'/60-port.conf', "Port 2222\n");

    $this->artisan('firewall:record-defaults')->assertSuccessful();

    expect(FirewallRule::query()->pluck('port_from')->all())->toEqualCanonicalizing([2222, 80, 443])
        ->and(FirewallRule::query()->where('port_from', 22)->exists())->toBeFalse();
});

it('leaves a hand-made rule for the same port alone', function () {
    $existing = FirewallRule::create([
        'port_from' => 80,
        'protocol' => 'tcp',
        'action' => 'allow',
        'origin' => 'user',
        'description' => 'mine',
    ]);

    $this->artisan('firewall:record-defaults')->assertSuccessful();

    $existing->refresh();

    expect(FirewallRule::query()->where('port_from', 80)->count())->toBe(1)
        ->and($existing->origin)->toBe('user')
        ->and($existing->description)->toBe('mine');
});

it('never touches the firewall itself', function () {
    // The installer's rule is that installing the panel must not change what
    // the server lets through. A recorder that shelled out to ufw would walk
    // that back, so this asserts on the absence of any process at all.
    Process::fake();

    $this->artisan('firewall:record-defaults')->assertSuccessful();

    Process::assertNothingRan();
});

it('is wired into both the installer and the panel update script', function () {
    // The installer covers new servers. Every server installed before this
    // command existed has the empty rule table the command was written for,
    // and an update is the only thing that ever reaches those.
    expect(file_get_contents(base_path('../install.sh')))->toContain('artisan firewall:record-defaults')
        ->and(UpdateScript::STEPS)->toContain('record_firewall_defaults');

    // After migrations for the obvious reason, and before `optimize` so the
    // rows are written under the same config the rest of the update ran with.
    $steps = array_flip(UpdateScript::STEPS);
    expect($steps['record_firewall_defaults'])->toBeGreaterThan($steps['migrate'])
        ->and($steps['record_firewall_defaults'])->toBeLessThan($steps['optimize']);
});
