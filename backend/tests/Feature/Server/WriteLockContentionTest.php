<?php

use App\Actions\Server\Cronjob\CreateCronjob;
use App\Actions\Server\Cronjob\DeleteCronjob;
use App\Actions\Server\Cronjob\UpdateCronjob;
use App\Actions\Server\Firewall\CreateFirewallRule;
use App\Actions\Server\Firewall\DeleteFirewallRule;
use App\Actions\Server\Firewall\UpdateFirewallRule;
use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Exceptions\Server\Firewall\FirewallOperationException;
use App\Models\Cronjob;
use App\Models\FirewallRule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * No shell command may run inside an open database transaction.
 *
 * SQLite allows exactly one writer. A `ufw` reload takes seconds; a
 * transaction held across it blocks every other write in the panel for that
 * whole time, and the request that loses the race fails with "database is
 * locked" — pointing at itself rather than at the firewall edit that actually
 * caused it.
 *
 * The transaction was never buying atomicity to pay for that with. `ufw`
 * changes the running firewall the moment it returns and `tee` has already
 * written the file; rolling back afterwards could only ever undo the row,
 * leaving the two halves disagreeing anyway. So the row is committed quickly
 * and put back by hand if the command refuses — same end state, none of the
 * lock.
 *
 * These assert the property directly: a command never runs at a deeper
 * transaction level than the one the test harness itself opened.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * Records how many transactions the code opened around each command.
 *
 * @return ArrayObject<int, array{command: string, extra: int}>
 */
function recordTransactionDepth(?string $failOn = null): ArrayObject
{
    $seen = new ArrayObject;

    // The suite itself runs each test inside a transaction, so the baseline is
    // 1 rather than 0. What matters is that the action opens no *further* one
    // around the command — comparing to a hardcoded 0 would assert the test
    // harness, not the code.
    $baseline = DB::transactionLevel();

    Process::fake(function ($process) use ($seen, $failOn, $baseline) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $command = (string) ($args[0] ?? '');

        $seen->append([
            'command' => $command,
            'extra' => DB::transactionLevel() - $baseline,
        ]);

        // Only the command that materialises the change is failed. Failing
        // everything would break the probes these actions run first (checking
        // the user exists, reading ufw status) and the action would refuse for
        // the wrong reason.
        return $failOn !== null && $command === $failOn
            ? Process::result(exitCode: 1, errorOutput: 'refused')
            : Process::result(exitCode: 0);
    });

    return $seen;
}

/** The commands that actually touch the server, ignoring cheap probes. */
function serverCommands(ArrayObject $seen): array
{
    return array_values(array_filter(
        (array) $seen,
        fn (array $entry) => in_array($entry['command'], ['ufw', 'tee', 'rm', 'mv', 'chmod', 'chown', 'mkdir', 'touch'], true),
    ));
}

function makeCronjob(): Cronjob
{
    return app(CreateCronjob::class)->execute([
        'name' => 'Disk cleaner',
        'command' => '/usr/bin/php -v',
        'expression' => '0 3 * * *',
        'username' => 'root',
        'active' => true,
    ]);
}

function makeRule(): FirewallRule
{
    // Re-read, because `create()` does not populate database defaults into the
    // model — `enabled` would be null in memory. A real request gets the rule
    // from route-model binding, which reads it back the same way.
    return app(CreateFirewallRule::class)->execute([
        'port_from' => 8080,
        'protocol' => 'tcp',
        'action' => 'allow',
    ])->fresh();
}

it('creates a cronjob without holding a transaction across the write', function () {
    $seen = recordTransactionDepth();

    makeCronjob();

    expect(serverCommands($seen))->not->toBeEmpty();

    foreach (serverCommands($seen) as $entry) {
        expect($entry['extra'])->toBe(0, "{$entry['command']} ran inside a transaction");
    }
});

it('updates a cronjob without holding a transaction across the write', function () {
    recordTransactionDepth();
    $cronjob = makeCronjob();
    $seen = recordTransactionDepth();

    app(UpdateCronjob::class)->execute($cronjob, ['name' => 'Nightly cleaner']);

    foreach (serverCommands($seen) as $entry) {
        expect($entry['extra'])->toBe(0, "{$entry['command']} ran inside a transaction");
    }
});

it('deletes a cronjob without holding a transaction across the removal', function () {
    recordTransactionDepth();
    $cronjob = makeCronjob();
    $seen = recordTransactionDepth();

    app(DeleteCronjob::class)->execute($cronjob);

    foreach (serverCommands($seen) as $entry) {
        expect($entry['extra'])->toBe(0, "{$entry['command']} ran inside a transaction");
    }
});

it('adds a firewall rule without holding a transaction across ufw', function () {
    $seen = recordTransactionDepth();

    makeRule();

    expect(serverCommands($seen))->not->toBeEmpty();

    foreach (serverCommands($seen) as $entry) {
        expect($entry['extra'])->toBe(0, "{$entry['command']} ran inside a transaction");
    }
});

it('edits a firewall rule without holding a transaction across ufw', function () {
    recordTransactionDepth();
    $rule = makeRule();
    $seen = recordTransactionDepth();

    app(UpdateFirewallRule::class)->execute($rule, ['port_from' => 9090]);

    foreach (serverCommands($seen) as $entry) {
        expect($entry['extra'])->toBe(0, "{$entry['command']} ran inside a transaction");
    }
});

it('removes a firewall rule without holding a transaction across ufw', function () {
    recordTransactionDepth();
    $rule = makeRule();
    $seen = recordTransactionDepth();

    app(DeleteFirewallRule::class)->execute($rule);

    foreach (serverCommands($seen) as $entry) {
        expect($entry['extra'])->toBe(0, "{$entry['command']} ran inside a transaction");
    }
});

it('leaves no cronjob row behind when the file cannot be written', function () {
    recordTransactionDepth(failOn: 'tee');

    expect(fn () => makeCronjob())->toThrow(CronjobOperationException::class);

    // The compensating delete is what a rollback used to do. Without it a
    // failed create would leave a job the panel lists and cron never runs.
    expect(Cronjob::count())->toBe(0);
});

it('leaves no firewall rule behind when ufw refuses', function () {
    recordTransactionDepth(failOn: 'ufw');

    expect(fn () => makeRule())->toThrow(FirewallOperationException::class);

    expect(FirewallRule::where('origin', 'user')->count())->toBe(0);
});

it('puts a cronjob back the way it was when the rewrite fails', function () {
    recordTransactionDepth();
    $cronjob = makeCronjob();

    recordTransactionDepth(failOn: 'tee');

    expect(fn () => app(UpdateCronjob::class)->execute($cronjob, ['name' => 'Renamed']))
        ->toThrow(CronjobOperationException::class);

    // The row has to keep describing the file that is still on disk — the old
    // one, because the rewrite is attempted before the old file is removed.
    expect($cronjob->fresh()->name)->toBe('Disk cleaner');
});

it('puts a firewall rule back the way it was when ufw refuses', function () {
    recordTransactionDepth();
    $rule = makeRule();

    recordTransactionDepth(failOn: 'ufw');

    expect(fn () => app(UpdateFirewallRule::class)->execute($rule, ['port_from' => 9090]))
        ->toThrow(FirewallOperationException::class);

    expect((int) $rule->fresh()->port_from)->toBe(8080);
});
