<?php

use App\Enums\RestoreStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Backups\BackupRoot;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * The site copy a failed restore leaves beside the site (2026-10-18). The
 * runner tidies only after a restore that worked, so these stayed for good.
 * Kept a week, then removed — but never where it could be the only copy.
 */

/** Commands the sweep ran, as plain argv (sudo stripped). */
final class RollbackPruneHost
{
    /** @var list<list<string>> */
    public static array $ran = [];

    public static bool $siteRootPresent = true;

    public static function removed(): array
    {
        return array_values(array_map(
            fn (array $args) => $args[2],
            array_filter(self::$ran, fn (array $args) => ($args[0] ?? '') === 'rm' && ($args[1] ?? '') === '-rf'),
        ));
    }
}

beforeEach(function () {
    RollbackPruneHost::$ran = [];
    RollbackPruneHost::$siteRootPresent = true;

    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        RollbackPruneHost::$ran[] = $args;

        if (($args[0] ?? '') === 'test' && ($args[1] ?? '') === '-d') {
            return Process::result(exitCode: RollbackPruneHost::$siteRootPresent ? 0 : 1);
        }

        return Process::result(exitCode: 0);
    });

    $systemUser = SystemUser::create(['username' => 'pruneuser', 'home_path' => '/home/pruneuser']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Prune Me',
        'slug' => 'prune-me',
        'domain' => 'prune-me.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $destination = StorageDestination::create([
        'name' => 'Backups',
        'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    $this->pruneTarget = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'filesystem',
        'retention_count' => 3,
        'enabled' => true,
        'frequency' => 'daily',
    ]);

    $this->pruneBackup = Backup::create([
        'backup_target_id' => $this->pruneTarget->id,
        'application_id' => $this->application->id,
        'type' => 'filesystem',
        'status' => 'verified',
        'size_bytes' => 10,
        'manifest' => ['key' => 'backups/prune/1.tar.gz'],
        'verified_at' => now(),
    ]);

    $this->siteRoot = app(BackupRoot::class)->forBackup($this->pruneBackup, $this->application);
});

/** A restore that ended `$days` ago, holding its recorded copy, with a safety backup in `$safetyStatus` (null: none). */
function endedRestore(int $days, RestoreStatus $status = RestoreStatus::Failed, ?string $safetyStatus = 'verified', ?string $path = null): Restore
{
    $safety = $safetyStatus === null ? null : Backup::create([
        'backup_target_id' => test()->pruneTarget->id,
        'application_id' => test()->application->id,
        'type' => 'filesystem',
        'status' => $safetyStatus,
        'is_safety' => true,
        'size_bytes' => 10,
        'manifest' => ['key' => 'backups/prune/safety.tar.gz'],
    ]);

    $restore = Restore::create([
        'backup_id' => test()->pruneBackup->id,
        'application_id' => test()->application->id,
        'type' => 'filesystem',
        'status' => $status,
        'reference' => (string) Str::uuid(),
        'reason' => 'restart_process',
        'safety_backup_id' => $safety?->id,
        'finished_at' => now()->subDays($days),
    ]);

    $restore->update(['rollback_path' => $path ?? dirname(test()->siteRoot).'/.rollback-'.$restore->id]);

    return $restore;
}

it('removes a failed restore\'s copy after a week', function () {
    $restore = endedRestore(8);
    $path = $restore->rollback_path;

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect(RollbackPruneHost::removed())->toBe([$path])
        ->and($restore->fresh()->rollback_path)->toBeNull();
});

it('keeps it for the first week', function () {
    $restore = endedRestore(6);

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect(RollbackPruneHost::removed())->toBe([])
        ->and($restore->fresh()->rollback_path)->not->toBeNull();
});

it('keeps it when the site is not in its place — the copy may be the site', function () {
    RollbackPruneHost::$siteRootPresent = false;
    $restore = endedRestore(30);

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect(RollbackPruneHost::removed())->toBe([])
        ->and($restore->fresh()->rollback_path)->not->toBeNull();
});

it('keeps it when no verified safety backup holds the same site', function (?string $safety) {
    $restore = endedRestore(30, safetyStatus: $safety);

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect(RollbackPruneHost::removed())->toBe([])
        ->and($restore->fresh()->rollback_path)->not->toBeNull();
})->with(['no safety backup' => [null], 'safety backup failed' => ['failed']]);

it('keeps it when the safety backup was deleted since', function () {
    $restore = endedRestore(30);
    Backup::whereKey($restore->safety_backup_id)->delete();

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect(RollbackPruneHost::removed())->toBe([]);
});

it('only removes the exact path the panel recorded beside the site', function () {
    $restore = endedRestore(30, path: '/home/pruneuser');

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect(RollbackPruneHost::removed())->toBe([])
        ->and($restore->fresh()->rollback_path)->toBe('/home/pruneuser');
});

it('leaves a successful restore\'s copy to the runner', function () {
    $restore = endedRestore(30, RestoreStatus::Succeeded);

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect(RollbackPruneHost::removed())->toBe([])
        ->and($restore->fresh()->rollback_path)->not->toBeNull();
});

it('keeps the row pointing at the copy when rm fails', function () {
    $restore = endedRestore(30);
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return Process::result(exitCode: ($args[0] ?? '') === 'rm' ? 1 : 0);
    });

    $this->artisan('restores:prune-rollbacks')->assertSuccessful();

    expect($restore->fresh()->rollback_path)->not->toBeNull();
});

it('is scheduled daily', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('restores:prune-rollbacks')->assertSuccessful();
});
