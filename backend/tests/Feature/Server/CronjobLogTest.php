<?php

use App\Models\Cronjob;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->base = sys_get_temp_dir().'/sv-oss-cronlog-'.getmypid();
    File::deleteDirectory($this->base);
    File::makeDirectory("{$this->base}/cron.d", 0755, true);
    File::makeDirectory("{$this->base}/logs", 0755, true);

    config([
        'server.cron_d' => "{$this->base}/cron.d",
        'server.cronjob_log_dir' => "{$this->base}/logs",
        'server.cronjob_logrotate_file' => "{$this->base}/logrotate-cronjobs",
        // No other log sources, so the catalog is just this job's.
        'server.logs' => [],
        'server.php_dir' => '/nonexistent-php-dir',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->base));

/**
 * Capture every command the manager runs, and what was piped into it.
 *
 * An ArrayObject rather than an array: the caller holds it while the fake
 * fills it, so it has to be the same object on both sides.
 */
function captureCron(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $runs[] = ['command' => $process->command, 'input' => (string) $process->input];

        return Process::result(exitCode: 0);
    });

    return $runs;
}

function cronHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function createCronjob(array $overrides = []): TestResponse
{
    return test()->withHeaders(cronHeaders())->postJson('/api/cronjobs', array_merge([
        'name' => 'Nightly Backup',
        'username' => 'deploy',
        'command' => '/usr/bin/php /var/www/app/artisan backup:run',
        'expression' => '0 3 * * *',
    ], $overrides));
}

it('redirects a job\'s output to its own log file', function () {
    $runs = captureCron();

    createCronjob()->assertCreated();

    $written = collect($runs)->firstWhere(fn ($run) => $run['command'][0] === 'tee'
        && str_ends_with($run['command'][1], 'cron.d/nightly-backup'));

    // Cron mails a job's output by default, and a box with no MTA drops it —
    // so a failing job leaves no trace unless we redirect it ourselves.
    expect($written['input'])->toContain(">> {$this->base}/logs/nightly-backup.log 2>&1");
});

it('captures every part of a compound command, not just the last one', function () {
    $runs = captureCron();

    createCronjob(['command' => 'cd /var/www/app && /usr/bin/php artisan backup:run'])->assertCreated();

    $written = collect($runs)->firstWhere(fn ($run) => $run['command'][0] === 'tee'
        && str_ends_with($run['command'][1], 'cron.d/nightly-backup'));

    // Without the subshell the redirect binds to the last command only, and
    // everything before it escapes to cron's mail — that is, to nowhere.
    expect($written['input'])
        ->toContain('( cd /var/www/app && /usr/bin/php artisan backup:run ) >>');
});

it('records the exit status, so a silent failure is still visible', function () {
    $runs = captureCron();

    createCronjob()->assertCreated();

    $written = collect($runs)->firstWhere(fn ($run) => $run['command'][0] === 'tee'
        && str_ends_with($run['command'][1], 'cron.d/nightly-backup'));

    // "Printed nothing" and "failed instantly" look identical in a bare
    // output log, and the second is the one you need to know about.
    expect($written['input'])->toContain('echo "--- exit=$? at $(date -Is)"');
});

it('escapes a percent sign, which cron would otherwise treat as end-of-command', function () {
    $runs = captureCron();

    createCronjob(['command' => '/usr/bin/tar -czf /backup/db-$(date +%Y%m%d).tgz /data'])->assertCreated();

    $written = collect($runs)->firstWhere(fn ($run) => $run['command'][0] === 'tee'
        && str_ends_with($run['command'][1], 'cron.d/nightly-backup'));

    // Unescaped, everything from the % becomes the job's stdin — truncating
    // the command *and* the redirect, so the breakage is invisible too.
    expect($written['input'])->toContain('+\%Y\%m\%d')
        ->and($written['input'])->toContain('2>&1');
});

it('creates the log owned by the account the job runs as', function () {
    $runs = captureCron();

    createCronjob(['username' => 'deploy'])->assertCreated();

    $commands = collect($runs)->pluck('command');
    $log = "{$this->base}/logs/nightly-backup.log";

    // Cron's `>>` would create it as whoever ran first, in a directory they
    // may not be able to write to at all.
    expect($commands)->toContain(['touch', $log])
        // The user, no group. `user:user` assumed every account has a group
        // of its own name — true for root and for panel-created users, false
        // for the arbitrary accounts this feature accepts (`nobody`'s group is
        // `nogroup`), where it failed and took the whole creation with it.
        ->and($commands)->toContain(['chown', 'deploy', $log])
        ->and($commands)->toContain(['chmod', '0640', $log]);
});

it('installs a rotation policy alongside the very first job', function () {
    $runs = captureCron();

    createCronjob()->assertCreated();

    $rotation = collect($runs)->firstWhere(fn ($run) => $run['command'] === ['tee', "{$this->base}/logrotate-cronjobs"]);

    // Capturing output without bounding it is a disk-fill on a delay: a job
    // running every minute appends forever.
    expect($rotation)->not->toBeNull()
        ->and($rotation['input'])->toContain("{$this->base}/logs/*.log")
        // The job holds the file open, so rotating by rename would leave it
        // writing to a file nobody can see.
        ->and($rotation['input'])->toContain('copytruncate')
        ->and($rotation['input'])->toContain('maxsize');
});

it('exposes the log through the existing logs endpoints once it has output', function () {
    // A cron log is 0640 owned by the job's user, so the panel reads it
    // through sudo rather than opening it directly. The fake serves `tail`
    // from the same file the test wrote, or this asserts nothing about the
    // round trip — only that a fake returns what it was told to.
    Process::fake(function ($process) {
        $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($command[0] ?? '') === 'tail') {
            $path = end($command);

            return File::exists($path)
                ? Process::result(output: File::get($path))
                : Process::result(errorOutput: 'No such file or directory', exitCode: 1);
        }

        if (($command[0] ?? '') === 'test' && ($command[1] ?? '') === '-f') {
            return Process::result(exitCode: File::exists($command[2] ?? '') ? 0 : 1);
        }

        return Process::result(exitCode: 0);
    });
    createCronjob()->assertCreated();

    // Nothing has run yet, so there is nothing to look at.
    $this->withHeaders(cronHeaders())->getJson('/api/cronjobs')
        ->assertJsonPath('cronjobs.0.log_key', null);

    File::put("{$this->base}/logs/nightly-backup.log", "backup complete\n--- exit=0 at 2026-07-29T03:00:01+00:00\n");

    $key = $this->withHeaders(cronHeaders())->getJson('/api/cronjobs')->json('cronjobs.0.log_key');
    expect($key)->toBe('cronjob_nightly-backup');

    // Same reader, same viewer, same polling cursor as every other log.
    $this->withHeaders(cronHeaders())->getJson("/api/logs/{$key}")
        ->assertOk()
        ->assertJsonPath('log.label', 'Cron — Nightly Backup')
        ->assertJsonPath('log.lines.0', 'backup complete');
});

it('carries the output history over when a job is renamed', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);
    $id = createCronjob()->json('cronjob.id');

    $runs = captureCron();

    $this->withHeaders(cronHeaders())->putJson("/api/cronjobs/{$id}", ['name' => 'Nightly Dump'])->assertOk();

    // Renaming must not strand past output under a name nothing points at.
    expect(collect($runs)->pluck('command'))->toContain([
        'mv', '-f', "{$this->base}/logs/nightly-backup.log", "{$this->base}/logs/nightly-dump.log",
    ]);
});

it('keeps the log when a job is deactivated but removes it when deleted', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);
    $id = createCronjob()->json('cronjob.id');
    $log = "{$this->base}/logs/nightly-backup.log";

    $runs = captureCron();
    $this->withHeaders(cronHeaders())->putJson("/api/cronjobs/{$id}", ['active' => false])->assertOk();

    // A paused job's history is exactly what you read to decide whether to
    // switch it back on.
    expect(collect($runs)->pluck('command'))->not->toContain(['rm', '-f', $log]);

    $runs = captureCron();
    $this->withHeaders(cronHeaders())->deleteJson("/api/cronjobs/{$id}")->assertNoContent();

    expect(collect($runs)->pluck('command'))->toContain(['rm', '-f', $log]);
    expect(Cronjob::find($id))->toBeNull();
});
