<?php

use App\Models\User;
use App\Services\Server\Settings\SettingsManager;
use App\Support\ServerTimezone;
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
        'server.reboot_schedule.remembered' => $this->cronDir.'-remembered/reboot-schedule.json',
    ]);

    $this->file = $this->cronDir.'/panel-reboot';
});

afterEach(function () {
    File::deleteDirectory($this->cronDir);
    File::deleteDirectory($this->cronDir.'-remembered');
});

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

    expect(cronLine())
        ->toStartWith('10 3 * * * root ')
        ->toContain('/sbin/shutdown -r');
});

it('records the reboot in the activity log before performing it', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    $line = cronLine();

    expect($line)
        ->toContain('server:log-scheduled-reboot')
        // The log runs first; a record written after the machine has gone down
        // is a record that never gets written.
        ->and(strpos($line, 'log-scheduled-reboot'))->toBeLessThan(strpos($line, 'shutdown'));
});

it('separates the log command from the reboot with ; so logging cannot cancel it', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    // `&&` here would make an audit record a precondition of the restart: a
    // database the panel cannot reach would silently cancel the maintenance
    // the administrator scheduled.
    expect(cronLine())
        ->toContain('; /sbin/shutdown')
        ->not->toContain('&& /sbin/shutdown');
});

it('drops privileges for the log command but not for the reboot', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    // Artisan as root leaves root-owned files in storage/, which break the
    // panel on its next request. Only `shutdown` needs to be root.
    expect(cronLine())->toContain('runuser -u ');
});

it('still reads back a schedule written with the log command', function () {
    schedule(['enabled' => true, 'frequency' => 'weekly', 'hour' => 4, 'day_of_week' => 2])->assertOk();

    // The parser finds the line by looking for `shutdown` and taking the first
    // five fields, so a longer command must not confuse it.
    $response = test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/settings')->assertOk();

    $response->assertJsonPath('settings.reboot_schedule.enabled', true)
        ->assertJsonPath('settings.reboot_schedule.frequency', 'weekly')
        ->assertJsonPath('settings.reboot_schedule.hour', 4)
        ->assertJsonPath('settings.reboot_schedule.day_of_week', 2);
});

it('logs the scheduled reboot with no actor', function () {
    $this->artisan('server:log-scheduled-reboot')->assertSuccessful();

    // Nobody pressed anything — this reads as System, not as an admin.
    $this->assertDatabaseHas('activity_logs', [
        'type' => 'setting',
        'action' => 'auto_rebooted',
        'user_id' => null,
    ]);
});

it('writes a weekly reboot on the chosen day', function () {
    schedule(['enabled' => true, 'frequency' => 'weekly', 'hour' => 4, 'day_of_week' => 0])->assertOk();

    expect(cronLine())->toStartWith('10 4 * * 0 root');
});

it('writes a monthly reboot on the chosen date', function () {
    schedule(['enabled' => true, 'frequency' => 'monthly', 'hour' => 5, 'day_of_month' => 1])->assertOk();

    expect(cronLine())->toStartWith('10 5 1 * * root');
});

it('uses the operator\'s minute when one is configured', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    // The default is on the hour, as v7; SERVER_REBOOT_SCHEDULE_MINUTE moves
    // it off the hour for a box whose backups also run at :00.
    expect(cronLine())->toStartWith('10 3 ');
});

it('gives logged-in users warning instead of cutting them off', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    // `shutdown -r` sends the wall message and lets services stop; a bare
    // `reboot` does neither.
    expect(cronLine())->toContain('/sbin/shutdown -r now')
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

it("logs the reboot with the panel's own PHP, not a distro path that may not exist", function () {
    // On OpenLiteSpeed there is no /usr/bin/php8.4; PHP is under lsws. The
    // log half of the line failed on every scheduled reboot and the reboot
    // went unrecorded (seen live 2026-09-23).
    config(['panel_update.php_binary' => '/usr/local/lsws/lsphp84/bin/php']);

    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();

    expect(cronLine())
        ->toContain("'/usr/local/lsws/lsphp84/bin/php'")
        ->not->toContain('/usr/bin/php');
});

it('keeps the day and hour of a schedule that is switched off', function () {
    schedule(['enabled' => true, 'frequency' => 'weekly', 'hour' => 5, 'day_of_week' => 2])->assertOk();
    schedule(['enabled' => false])->assertOk();

    expect(File::exists(test()->file))->toBeFalse();

    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/settings')->assertOk()
        ->assertJsonPath('settings.reboot_schedule.enabled', false)
        ->assertJsonPath('settings.reboot_schedule.frequency', 'weekly')
        ->assertJsonPath('settings.reboot_schedule.hour', 5)
        ->assertJsonPath('settings.reboot_schedule.day_of_week', 2)
        ->assertJsonPath('settings.reboot_schedule.next_run', null);
});

it('remembers what a switch-off request sent over what the file said', function () {
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 3])->assertOk();
    schedule(['enabled' => false, 'frequency' => 'monthly', 'hour' => 4, 'day_of_month' => 15])->assertOk();

    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/settings')->assertOk()
        ->assertJsonPath('settings.reboot_schedule.frequency', 'monthly')
        ->assertJsonPath('settings.reboot_schedule.hour', 4)
        ->assertJsonPath('settings.reboot_schedule.day_of_month', 15);
});

it('ignores a remembered value that is out of range', function () {
    File::ensureDirectoryExists(dirname(config('server.reboot_schedule.remembered')));
    File::put(config('server.reboot_schedule.remembered'), json_encode(['frequency' => 'hourly', 'hour' => 99]));

    test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/settings')->assertOk()
        ->assertJsonPath('settings.reboot_schedule.frequency', 'daily')
        ->assertJsonPath('settings.reboot_schedule.hour', 3);
});

it('labels the schedule with the zone cron runs in when /etc/timezone is absent', function () {
    // Ubuntu 26.04 ships no /etc/timezone; /etc/localtime is the only record.
    $link = test()->cronDir.'/localtime';
    symlink('/usr/share/zoneinfo/Asia/Kolkata', $link);
    config([
        'server.timezone_file' => test()->cronDir.'/no-such-timezone',
        'server.localtime_link' => $link,
    ]);
    ServerTimezone::forget();

    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 4])->assertOk();

    $response = test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->getJson('/api/settings')->assertOk()
        ->assertJsonPath('settings.reboot_schedule.timezone', 'Asia/Kolkata');

    expect($response->json('settings.reboot_schedule.next_run'))->toEndWith('04:10:00');

    ServerTimezone::forget();
});

/*
 * Bug #8: picking 04:00 restarted at 04:11 — the schedule sat at :10 and
 * shutdown waited one more minute. v7 restarts at the hour picked.
 */
it('restarts at the hour picked, not ten minutes after it', function () {
    expect((require base_path('config/server.php'))['reboot_schedule']['minute'])->toBe(0);

    config(['server.reboot_schedule.minute' => 0]);
    schedule(['enabled' => true, 'frequency' => 'daily', 'hour' => 4])->assertOk();

    expect(cronLine())->toStartWith('0 4 * * * root')
        ->and(cronLine())->not->toContain('+1');
});
