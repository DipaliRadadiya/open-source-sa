<?php

use App\Models\User;
use App\Services\Server\Settings\SettingsManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->cronDir = sys_get_temp_dir().'/sv-oss-cron-'.getmypid();
    File::deleteDirectory($this->cronDir);
    File::makeDirectory($this->cronDir, 0755, true);

    config([
        'server.cron_d' => $this->cronDir,
        'server.reboot_schedule.file' => 'panel-reboot',
        'server.reboot_schedule.minute' => 10,
    ]);

    $this->file = $this->cronDir.'/panel-reboot';
});

afterEach(fn () => File::deleteDirectory($this->cronDir));

/**
 * `tee` and `rm` really run here rather than being faked, because the point
 * of the change is that these writes go through ServerOps at all.
 */
function schedule(array $body): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->putJson('/api/settings/reboot-schedule', $body);
}

function cronLine(): string
{
    $lines = array_filter(
        preg_split('/\r?\n/', (string) File::get(test()->file)) ?: [],
        fn (string $line) => str_contains($line, 'shutdown'),
    );

    return trim((string) reset($lines));
}

it('writes a daily reboot at the chosen hour', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    expect(cronLine())->toStartWith('10 3 * * * root /sbin/shutdown -r');
});

it('writes a weekly reboot on the chosen day', function () {
    schedule(['enabled' => true, 'frequency' => 'weekly', 'hour' => 4, 'day_of_week' => 0])->assertOk();

    expect(cronLine())->toStartWith('10 4 * * 0 root');
});

it('writes a monthly reboot on the chosen date', function () {
    schedule(['enabled' => true, 'frequency' => 'monthly', 'hour' => 5, 'day_of_month' => 1])->assertOk();

    expect(cronLine())->toStartWith('10 5 1 * * root');
});

it('does not schedule the reboot on the hour', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    // Every :00 cron job fires on the same tick. A reboot landing on top of a
    // running backup is a half-written archive.
    expect(cronLine())->not->toStartWith('0 3 ');
});

it('gives logged-in users warning instead of cutting them off', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    // `shutdown -r +1` sends the wall message and lets services stop; a bare
    // `reboot` does neither.
    expect(cronLine())->toContain('/sbin/shutdown -r +1')
        ->and(cronLine())->not->toContain('/sbin/reboot');
});

it('deletes the file when disabled rather than commenting it out', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();
    expect(File::exists($this->file))->toBeTrue();

    schedule(['enabled' => false])->assertOk();

    // A disabled schedule left in /etc/cron.d is one uncomment away from an
    // unexpected reboot.
    expect(File::exists($this->file))->toBeFalse();
});

it('treats disabling an absent schedule as done', function () {
    schedule(['enabled' => false])->assertOk();

    expect(File::exists($this->file))->toBeFalse();
});

it('reads back what is on disk, not what it last wrote', function () {
    // Root can edit the file. The screen should show the truth.
    File::put($this->file, "# hand-edited\n30 7 * * 6 root /sbin/shutdown -r +1 \"x\"\n");

    $values = app(SettingsManager::class)->find('reboot_schedule')->read();

    expect($values['enabled'])->toBeTrue()
        ->and($values['frequency'])->toBe('weekly')
        ->and($values['hour'])->toBe(7)
        ->and($values['day_of_week'])->toBe(6);
});

it('reports when the next reboot will actually happen', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    $values = app(SettingsManager::class)->find('reboot_schedule')->read();

    expect($values['next_run'])->toMatch('/^\d{2}-\d{2}-\d{4} 03:10:00$/')
        ->and($values['next_run_human'])->not->toBeNull()
        // cron runs in server-local time; saying which removes the "why did
        // it fire an hour early" ticket.
        ->and($values['timezone'])->not->toBeEmpty();
});

it('refuses a free-form cron expression', function () {
    // Every other scheduling surface takes one. This one restarts the server,
    // and `* * * * *` is a reboot loop nobody can log in to stop.
    schedule(['enabled' => true, 'frequency' => '* * * * *', 'hour' => 3])
        ->assertUnprocessable()->assertJsonValidationErrors('frequency');

    schedule(['enabled' => true, 'frequency' => 'hourly', 'hour' => 3])
        ->assertUnprocessable()->assertJsonValidationErrors('frequency');
});

it('refuses an hour that is not an hour', function () {
    foreach ([24, -1, 'midnight'] as $hour) {
        schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => $hour])
            ->assertUnprocessable()->assertJsonValidationErrors('hour');
    }
});

it('caps the monthly day at 28 so it fires every month', function () {
    // The 31st silently skips February and the short months — a "monthly"
    // reboot that happens seven times a year.
    schedule(['enabled' => true, 'frequency' => 'monthly', 'hour' => 3, 'day_of_month' => 31])
        ->assertUnprocessable()->assertJsonValidationErrors('day_of_month');
});

it('serves the frequency list translated, so the frontend hardcodes nothing', function () {
    $presets = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept-Language', 'de')
        ->getJson('/api/settings/reboot-schedule/presets')->assertOk();

    expect($presets->json('frequencies'))->toBe([
        ['value' => 'daily', 'label' => 'Täglich'],
        ['value' => 'weekly', 'label' => 'Wöchentlich'],
        ['value' => 'monthly', 'label' => 'Monatlich'],
    ])
        ->and($presets->json('hours'))->toHaveCount(24)
        ->and($presets->json('days_of_week.0'))->toBe(['value' => 0, 'label' => 'Sonntag']);
});

it('reports a write failure with a reference instead of leaking the path', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'permission denied')]);

    $response = schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertStatus(500);

    // Not `file_put_contents(/etc/cron.d/panel-reboot): Failed to open
    // stream` — that hands an internal path to the caller and leaves support
    // nothing to trace.
    expect($response->json('reference'))->not->toBeEmpty()
        ->and($response->json('message'))->not->toContain('/etc/')
        ->and($response->json('message'))->not->toContain('file_put_contents');
});

it('denies a view-only user', function () {
    $user = User::factory()->create();
    grantPermission($user, 'setting', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/settings/reboot-schedule/presets')->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/settings/reboot-schedule', ['enabled' => true, 'frequency' => 'daily', 'hour' => 3])
        ->assertForbidden();
});

it('does not reboot under a logged-in administrator unless asked', function () {
    $path = sys_get_temp_dir().'/sv-oss-uu-'.getmypid();
    config(['server.unattended_upgrades_file' => $path]);

    $this->withHeader('Authorization', 'Bearer '.$this->token)->putJson('/api/settings/updates', [
        'security_updates_enabled' => true,
        'auto_reboot' => true,
        'reboot_time' => '02:00',
    ])->assertOk();

    // unattended-upgrades defaults Automatic-Reboot-WithUsers to true, which
    // restarts the box under an admin mid-SSH-session. Absent from the
    // request means false here — the surprising behaviour has to be chosen.
    expect(File::get($path))->toContain('Unattended-Upgrade::Automatic-Reboot-WithUsers "false"');

    $this->withHeader('Authorization', 'Bearer '.$this->token)->putJson('/api/settings/updates', [
        'security_updates_enabled' => true,
        'auto_reboot' => true,
        'reboot_time' => '02:00',
        'reboot_with_users' => true,
    ])->assertOk();

    expect(File::get($path))->toContain('Unattended-Upgrade::Automatic-Reboot-WithUsers "true"');

    File::delete($path);
});
