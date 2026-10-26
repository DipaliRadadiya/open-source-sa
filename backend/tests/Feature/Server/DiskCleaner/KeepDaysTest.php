<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\DiskCleanerRun;
use App\Models\DiskCleanerSchedule;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\DiskCleaner\Targets\AptCacheTarget;
use App\Services\Server\DiskCleaner\Targets\RotatedLogsTarget;
use App\Services\Server\DiskCleaner\Targets\TmpTarget;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/*
 * One keep choice for the whole clean (Dipali, 2026-10-26): delete all, or
 * keep the last 1/3/7/14/30 days — the same meaning on every category, in a
 * manual clean and on the schedule. No choice = what each category did before.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->logDir = sys_get_temp_dir().'/sv-oss-kd-'.uniqid();
    File::ensureDirectoryExists($this->logDir);
    config(['server.disk_cleaner.service_log_globs' => [$this->logDir.'/*.log']]);
});

afterEach(function () {
    File::deleteDirectory($this->logDir);
    Carbon::setTestNow();
});

/** A file of `$bytes` last written `$daysAgo` days ago. */
function dcAgedFile(string $path, int $bytes, float $daysAgo): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, str_repeat('x', $bytes));
    touch($path, (int) (time() - $daysAgo * 86400));
}

/**
 * Every command the cleaner runs, with `sudo -n` stripped; real files are
 * listed through answerFind(), everything else succeeds.
 */
function dcRecordCommands(): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran) {
        $args = ($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran->append($args);

        if ($found = answerFind($process)) {
            return $found;
        }

        return ($args[0] ?? '') === 'df'
            ? Process::result(output: "fs 1B-blocks Used Avail Cap Mount\n/dev/vda1 100 90 10 90% /\n")
            : Process::result();
    });

    return $ran;
}

/** @return array<int, array<int, string>> */
function dcCommandsStarting(ArrayObject $ran, string $binary): array
{
    return collect($ran)->filter(fn (array $c) => ($c[0] ?? '') === $binary)->values()->all();
}

function dcSiteWithLogs(string $home): Application
{
    $user = SystemUser::create(['username' => 'shopuser', 'home_path' => $home, 'shell' => '/bin/bash']);

    return Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
    ]);
}

describe('the request', function () {
    it('accepts every listed choice and nothing else', function (mixed $keepDays, bool $valid) {
        dcAgedFile($this->logDir.'/access.log', 10, 0);
        dcRecordCommands();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs'], 'keep_days' => $keepDays]);

        $valid ? $response->assertOk() : $response->assertUnprocessable()->assertJsonValidationErrors('keep_days');
    })->with([
        'delete all' => [0, true],
        '1 day' => [1, true],
        '30 days' => [30, true],
        'no choice' => [null, true],
        'not in the list' => [2, false],
        'negative' => [-1, false],
        'too long' => [365, false],
        'not a number' => ['abc', false],
    ]);

    it('takes the same choices on the schedule, delete all included', function () {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/disk-cleaner/schedule', ['enabled' => true, 'frequency' => 'daily', 'categories' => ['journal'], 'keep_days' => 0, 'threshold_percent' => 85])
            ->assertOk()
            ->assertJsonPath('schedule.keep_days', 0);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/disk-cleaner/schedule', ['enabled' => true, 'frequency' => 'daily', 'categories' => ['journal'], 'keep_days' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('keep_days');

        expect(DiskCleanerSchedule::first()->keep_days)->toBe(0);
    });

    it('forbids a viewer from cleaning with a choice', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'disk_cleaner', view: true, manage: false);

        $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs'], 'keep_days' => 0])
            ->assertForbidden();
    });
});

describe('the preview', function () {
    it('prices every choice for every category before anything runs', function () {
        dcAgedFile($this->logDir.'/today.log', 100, 0);
        dcAgedFile($this->logDir.'/stale.log', 400, 10);
        dcRecordCommands();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/disk-cleaner')->assertOk()
            ->assertJsonPath('keep_days.choices', [0, 1, 3, 7, 14, 30])
            ->assertJsonPath('keep_days.recommended', 7);

        $service = collect($response->json('categories'))->firstWhere('key', 'service_logs');

        expect($service['keeps_days'])->toBeTrue()
            ->and($service['reclaimable'])->toBe(500)   // no choice: every current file, as before
            ->and(collect($service['reclaimable_by_keep_days'])->pluck('reclaimable', 'keep_days')->all())
            ->toBe([0 => 500, 1 => 400, 3 => 400, 7 => 400, 14 => 0, 30 => 0]);
    });

    it('says unused packages cannot keep by age', function () {
        dcRecordCommands();

        $orphans = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/disk-cleaner')->json('categories'))->firstWhere('key', 'apt_orphans');

        if ($orphans === null) {
            $this->markTestSkipped('apt-get is not installed here');
        }

        expect($orphans['keeps_days'])->toBeFalse();
    });
});

describe('no choice is exactly what each category did before', function () {
    it('runs the old commands', function () {
        $ran = dcRecordCommands();

        app(RotatedLogsTarget::class)->clean();
        app(TmpTarget::class)->clean();
        app(AptCacheTarget::class)->clean();

        [$rotated, $tmp] = dcCommandsStarting($ran, 'find');

        // Every archive, with no age test.
        expect($rotated)->not->toContain('-mmin')->and(end($rotated))->toBe('-delete')
            // The week tmp always kept, with the very test it always used.
            ->and($tmp)->toContain('-mtime')->and($tmp[array_search('-mtime', $tmp, true) + 1])->toBe('+7')
            ->and(dcCommandsStarting($ran, 'apt-get'))->toBe([['apt-get', 'clean']]);
    });

    it('empties every current service log and nothing else', function () {
        dcAgedFile($this->logDir.'/today.log', 10, 0);
        dcAgedFile($this->logDir.'/stale.log', 10, 10);
        $ran = dcRecordCommands();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs']])->assertOk();

        expect(dcCommandsStarting($ran, 'truncate'))
            ->toBe([['truncate', '--no-create', '-s', '0', $this->logDir.'/stale.log', $this->logDir.'/today.log']])
            ->and(dcCommandsStarting($ran, 'rm'))->toBe([]);
    });

    it("leaves a site's rotated copies alone", function () {
        $site = dcSiteWithLogs($this->logDir.'/home');
        dcAgedFile($site->logsPath().'/access.log', 10, 0);
        dcAgedFile($site->logsPath().'/access.log.3.gz', 10, 20);
        $ran = dcRecordCommands();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['site_logs']])->assertOk();

        expect(dcCommandsStarting($ran, 'rm'))->toBe([]);
    });

    it('runs a schedule saved before the choice existed the old way', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:30:00'));
        $ran = dcRecordCommands();
        DiskCleanerSchedule::create(['enabled' => true, 'frequency' => 'hourly', 'categories' => ['journal'], 'last_run_at' => null]);
        DiskCleanerSchedule::query()->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('disk-cleaner:run')->assertExitCode(0);

        expect(dcCommandsStarting($ran, 'journalctl'))->toBe([['journalctl', '--vacuum-time=7d']])
            ->and(DiskCleanerRun::first()->keep_days)->toBeNull();
    });
});

describe('a choice', function () {
    it('empties only the logs nobody has written to for that long', function () {
        dcAgedFile($this->logDir.'/today.log', 10, 0);
        dcAgedFile($this->logDir.'/stale.log', 10, 10);
        $ran = dcRecordCommands();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs'], 'keep_days' => 7])->assertOk();

        expect(dcCommandsStarting($ran, 'truncate'))->toBe([['truncate', '--no-create', '-s', '0', $this->logDir.'/stale.log']]);
    });

    it('empties every current log for delete all', function () {
        dcAgedFile($this->logDir.'/today.log', 10, 0);
        dcAgedFile($this->logDir.'/stale.log', 10, 10);
        $ran = dcRecordCommands();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs'], 'keep_days' => 0])->assertOk();

        expect(dcCommandsStarting($ran, 'truncate')[0])->toContain($this->logDir.'/today.log', $this->logDir.'/stale.log');
    });

    it("removes a site's rotated copies older than the choice, which nothing cleaned before", function () {
        $site = dcSiteWithLogs($this->logDir.'/home');
        $logs = $site->logsPath();
        dcAgedFile($logs.'/access.log', 10, 0);
        dcAgedFile($logs.'/access.log.1', 10, 1.5);
        dcAgedFile($logs.'/access.log.9.gz', 10, 9);
        $ran = dcRecordCommands();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['site_logs'], 'keep_days' => 7])->assertOk();

        expect(dcCommandsStarting($ran, 'rm'))->toBe([['rm', '-f', '--', $logs.'/access.log.9.gz']])
            ->and(dcCommandsStarting($ran, 'truncate'))->toBe([]);
    });

    it("removes all of a site's rotated copies and empties its logs for delete all", function () {
        $site = dcSiteWithLogs($this->logDir.'/home');
        $logs = $site->logsPath();
        dcAgedFile($logs.'/access.log', 10, 0);
        dcAgedFile($logs.'/access.log.1', 10, 1.5);
        dcAgedFile($logs.'/error.log.2.gz', 10, 9);
        $ran = dcRecordCommands();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['site_logs'], 'keep_days' => 0])->assertOk();

        expect(dcCommandsStarting($ran, 'rm'))->toBe([['rm', '-f', '--', $logs.'/access.log.1', $logs.'/error.log.2.gz']])
            ->and(dcCommandsStarting($ran, 'truncate'))->toBe([['truncate', '--no-create', '-s', '0', $logs.'/access.log']]);
    });

    it('selects the right rotated archives when run for real', function () {
        // The age test is the risky part, so the shipped command is run against
        // a tree shaped like /var/log, the way the binary-log guard is.
        $root = $this->logDir.'/varlog';
        dcAgedFile($root.'/nginx/access.log.1', 1, 1);
        dcAgedFile($root.'/nginx/access.log.2.gz', 1, 8);
        dcAgedFile($root.'/syslog.1', 1, 31);
        dcAgedFile($root.'/nginx/access.log', 1, 40);   // current: never this category's
        $ran = dcRecordCommands();

        app(RotatedLogsTarget::class)->clean(7);

        $argv = array_map(fn (string $arg) => match ($arg) {
            '/var/log' => $root,
            '/var/log/mysql/*' => $root.'/mysql/*',
            default => $arg,
        }, dcCommandsStarting($ran, 'find')[0]);
        exec(implode(' ', array_map('escapeshellarg', $argv)));

        expect(file_exists($root.'/nginx/access.log.1'))->toBeTrue()
            ->and(file_exists($root.'/nginx/access.log.2.gz'))->toBeFalse()
            ->and(file_exists($root.'/syslog.1'))->toBeFalse()
            ->and(file_exists($root.'/nginx/access.log'))->toBeTrue();
    });

    it('never treats a temp file in use as left behind, even for delete all', function () {
        $ran = dcRecordCommands();

        app(TmpTarget::class)->clean(0);
        app(TmpTarget::class)->clean(3);

        [$all, $three] = dcCommandsStarting($ran, 'find');

        expect(array_slice($all, -3))->toBe(['-mmin', '+60', '-delete'])
            ->and(array_slice($three, -3))->toBe(['-mmin', '+4320', '-delete']);
    });

    it('keeps recent package downloads by when they arrived, not by their date stamp', function () {
        // apt stamps a download with the mirror's Last-Modified, so -mmin
        // would call a package fetched this morning a year old.
        $ran = dcRecordCommands();

        app(AptCacheTarget::class)->clean(7);
        app(AptCacheTarget::class)->clean(0);

        expect(dcCommandsStarting($ran, 'find')[0])->toContain('-cmin', '+10080', '-delete')->not->toContain('-mmin')
            ->and(dcCommandsStarting($ran, 'apt-get'))->toBe([['apt-get', 'clean']]);
    });

    it('runs the saved choice on the schedule, delete all included', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:30:00'));
        $ran = dcRecordCommands();
        DiskCleanerSchedule::create(['enabled' => true, 'frequency' => 'hourly', 'categories' => ['journal'], 'keep_days' => 0, 'last_run_at' => null]);
        DiskCleanerSchedule::query()->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('disk-cleaner:run')->assertExitCode(0);

        expect(dcCommandsStarting($ran, 'journalctl'))->toBe([['journalctl', '--rotate', '--vacuum-size=1']])
            ->and(DiskCleanerRun::first()->keep_days)->toBe(0);
    });
});

describe('the record of a clean', function () {
    it('reports what it freed by the same choice it cleaned with', function () {
        dcAgedFile($this->logDir.'/today.log', 100, 0);
        dcAgedFile($this->logDir.'/stale.log', 400, 10);

        // truncate done for real, so the after-estimate sees the result.
        Process::fake(function ($process) {
            $args = ($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if ($found = answerFind($process)) {
                return $found;
            }

            if ($args[0] === 'truncate') {
                foreach (array_slice($args, 4) as $path) {
                    $mtime = filemtime($path);
                    file_put_contents($path, '');
                    touch($path, $mtime);
                }
            }

            return $args[0] === 'df'
                ? Process::result(output: "fs 1B-blocks Used Avail Cap Mount\n/dev/vda1 100 90 10 90% /\n")
                : Process::result();
        });

        // Measured against "no choice" afterwards, the untouched 100 bytes of
        // today's log would be subtracted from what the clean freed.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs'], 'keep_days' => 7])
            ->assertOk()
            ->assertJsonPath('freed_total', 400);
    });

    it('names the choice in the run history and the activity log', function () {
        dcAgedFile($this->logDir.'/today.log', 10, 0);
        dcRecordCommands();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs'], 'keep_days' => 0])->assertOk();
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/disk-cleaner/clean', ['categories' => ['service_logs'], 'keep_days' => 7])->assertOk();

        $kept = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/disk-cleaner/runs')->assertOk()->json('runs'))->pluck('keep_days')->sort()->values()->all();

        expect($kept)->toBe([0, 7]);

        $descriptions = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/server/activity-log')->json('activity_log'))->pluck('description');

        expect($descriptions->implode("\n"))->toContain('deleted all')->toContain('kept 7 days');
    });

    it('reads a row from before the choice as the standard retention, not a bare placeholder', function () {
        ActivityLog::create([
            'type' => 'disk_cleaner', 'action' => 'cleaned', 'user_id' => $this->admin->id,
            'properties' => ['categories' => 'journal', 'freed' => '1 MB'],
        ]);

        $description = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/server/activity-log')->json('activity_log.0.description');

        expect($description)->toContain('standard retention')->not->toContain(':kept');
    });
});

it('adds and removes the keep columns cleanly', function () {
    $migration = require database_path('migrations/2026_10_26_000001_add_keep_days_to_disk_cleaner_tables.php');

    $migration->down();
    expect(Schema::hasColumn('disk_cleaner_schedules', 'keep_days'))->toBeFalse()
        ->and(Schema::hasColumn('disk_cleaner_runs', 'keep_days'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('disk_cleaner_schedules', 'keep_days'))->toBeTrue()
        ->and(Schema::hasColumn('disk_cleaner_runs', 'keep_days'))->toBeTrue();
});
