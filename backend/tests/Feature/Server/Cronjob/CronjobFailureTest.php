<?php

use App\Models\Cronjob;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/*
 * Creating a cron job runs seven privileged steps: make the log directory,
 * create the log, hand it to the right account, lock its mode, install the
 * rotation policy, write the cron.d file, lock its mode. Every one of them
 * produced the same sentence — "Failed to apply the cron job on the server" —
 * so a full disk, a missing group and a read-only /etc were indistinguishable
 * to the person who had to fix one of them.
 *
 * And nothing recorded the attempt. The row is deleted on failure, so a failed
 * creation left no trace anywhere in the panel: a reference number, and an
 * activity log that showed nobody had ever tried.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    grantPermission($this->user, 'cronjob', view: true, manage: true);

    $this->systemUser = SystemUser::factory()->create(['username' => 'siteowner']);
});

/**
 * Fake every privileged command as succeeding, except the one whose argv
 * contains `$failOn` — which is how a single step is made to fail without
 * touching the others.
 */
function failCronStep(?string $failOn): void
{
    Process::fake(function ($process) use ($failOn) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if ($failOn !== null && str_contains($command, $failOn)) {
            return Process::result(exitCode: 1, errorOutput: 'no space left on device');
        }

        return Process::result(exitCode: 0);
    });
}

/** @return array<string, mixed> */
function cronPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nightly cleanup',
        'command' => 'php /home/siteowner/site/artisan cleanup',
        'expression' => '0 3 * * *',
        'system_user_id' => test()->systemUser->id,
        'active' => true,
    ], $overrides);
}

describe('when a step fails', function () {

    /*
     * The step is the whole point. Without it every one of these returns the
     * same words and the user is left guessing which of seven things broke.
     */
    it('names the log directory when that is what could not be made', function () {
        failCronStep('mkdir');

        $response = $this->postJson('/api/cronjobs', cronPayload())->assertStatus(500);

        expect($response->json('code'))->toBe('cronjob_log_dir')
            ->and($response->json('message'))->toContain('log directory')
            ->and($response->json('reference'))->not->toBeEmpty();
    });

    it('names the cron file when that is what could not be written', function () {
        failCronStep('tee /etc/cron.d');

        $response = $this->postJson('/api/cronjobs', cronPayload())->assertStatus(500);

        expect($response->json('code'))->toBe('cronjob_write')
            ->and($response->json('message'))->toContain('cron file');
    });

    /*
     * Rotation is worth its own sentence: the job is refused *because* its
     * output would grow without limit, which is a decision rather than a
     * malfunction, and the message should say so.
     */
    it('names log rotation, and explains why that stops the job', function () {
        failCronStep('logrotate');

        $response = $this->postJson('/api/cronjobs', cronPayload())->assertStatus(500);

        expect($response->json('code'))->toBe('cronjob_rotation')
            ->and($response->json('message'))->toContain('without limit');
    });

    it('never renders a translation key at the user', function () {
        foreach (['mkdir', 'touch', 'chown', 'tee /etc/logrotate.d', 'tee /etc/cron.d'] as $step) {
            failCronStep($step);

            $message = $this->postJson('/api/cronjobs', cronPayload(['name' => 'Job '.$step]))
                ->assertStatus(500)->json('message');

            expect($message)->not->toContain('errors/cronjob', "step {$step} leaked its key");
        }
    });
});

describe('what the failure leaves behind', function () {

    it('records the attempt, so a failure is not invisible', function () {
        failCronStep('mkdir');

        $this->postJson('/api/cronjobs', cronPayload(['name' => 'Nightly cleanup']))->assertStatus(500);

        $this->assertDatabaseHas('activity_logs', [
            'type' => 'cronjob',
            'action' => 'create_failed',
            'user_id' => $this->user->id,
        ]);
    });

    /*
     * The row goes, because a cron job the server does not have is worse than
     * no cron job: the panel would list a schedule nothing runs.
     */
    it('leaves no half-made job behind', function () {
        failCronStep('tee /etc/cron.d');

        $this->postJson('/api/cronjobs', cronPayload())->assertStatus(500);

        expect(Cronjob::count())->toBe(0);
    });

    it('keeps the job when every step succeeds', function () {
        failCronStep(null);

        $this->postJson('/api/cronjobs', cronPayload())->assertStatus(201);

        expect(Cronjob::count())->toBe(1);
        $this->assertDatabaseMissing('activity_logs', ['type' => 'cronjob', 'action' => 'create_failed']);
    });
});

/*
 * A group of the same name as the user exists for root and for every account
 * the panel creates — but this feature accepts any account `getent` resolves,
 * and `nobody`'s group on Debian is `nogroup`. `chown nobody:nobody` fails, and
 * took the whole creation with it at step three.
 */
it('chowns the log to the user without assuming a group of the same name', function () {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return Process::result(exitCode: 0);
    });

    $this->postJson('/api/cronjobs', cronPayload())->assertStatus(201);

    $chown = collect($commands)->first(fn (string $c) => str_starts_with($c, 'chown '));

    expect($chown)->toContain('chown siteowner ')
        ->and($chown)->not->toContain('siteowner:siteowner');
});
