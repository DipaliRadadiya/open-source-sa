<?php

use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\FirewallRule;
use App\Services\Server\Firewall\UfwFirewall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/*
| Two rules switched off at the same moment both answered 200 and one stayed
| in ufw (frontend QA FS-C24, reproduced on Ubuntu 26.04). Every ufw change
| now waits for the one before it.
*/

beforeEach(function () {
    Process::fake();
    config(['server.firewall.lock_wait' => 1]);
    $this->rule = new FirewallRule(['port_from' => 45151, 'protocol' => 'tcp', 'action' => 'allow']);
});

it('does not touch ufw while another firewall change holds the lock', function (string $method) {
    $held = Cache::lock('firewall:ufw', 30);
    expect($held->get())->toBeTrue();

    try {
        $method === 'enable' || $method === 'disable'
            ? app(UfwFirewall::class)->{$method}()
            : app(UfwFirewall::class)->{$method}($this->rule);
        $this->fail('expected the change to wait and give up');
    } catch (FirewallOperationException $e) {
        expect($e->busy)->toBeTrue();
    } finally {
        $held->release();
    }

    Process::assertNothingRan();
})->with(['apply', 'remove', 'enable', 'disable']);

it('releases the lock after each change, so the next one runs', function () {
    $ufw = app(UfwFirewall::class);

    $ufw->remove($this->rule);
    $ufw->remove($this->rule);

    Process::assertRanTimes(fn ($p) => in_array('delete', $p->command, true), 2);
    expect(Cache::lock('firewall:ufw', 1)->get())->toBeTrue();
});

it('does not guess the default policy when ufw does not state it', function () {
    // An inactive ufw prints only "Status: inactive" (OLD-1).
    Process::fake(['*' => Process::result(output: "Status: inactive\n")]);

    expect(app(UfwFirewall::class)->status())
        ->toBe(['enabled' => false, 'default_policy' => ['incoming' => null, 'outgoing' => null]]);
});
