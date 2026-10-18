<?php

use App\Enums\BackupStatus;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * S-02: `volumes` was accepted for a container site with no volumes — IT-Tools
 * declares `"volumes": {}` — and every run then failed at `archive_files`.
 *
 * Docker is faked at the process, not by stubbing `VolumeSizes`, so these go
 * through the same `docker volume ls` reading the backup itself uses: the
 * refusal and the backup cannot disagree about whether a site has volumes.
 */
beforeEach(function () {
    // The real recipe, so the fixture is the site the bug was found on.
    expect(app(SiteTypeManager::class)->find('ittools')?->volumeRoles())->toBe([]);

    $this->seed(PermissionSeeder::class);
    $this->token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

    $user = SystemUser::create(['username' => 'tools', 'home_path' => '/home/tools']);

    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'IT-Tools', 'slug' => 'it-tools',
        'domain' => 'tools.example.test', 'site_type' => 'ittools', 'serving_profile' => 'docker',
        'status' => 'active', 'web_root' => '/',
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Offsite', 'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);
});

/**
 * `docker volume ls` answering with these volume names.
 *
 * Every other command fails, as on a box with nothing installed: answering them
 * all "yes" made the capability probes (`which node`) describe a different
 * server, whose routes this file is not about.
 */
function dockerVolumes(array $names, bool $answered = true): void
{
    Process::fake(function (PendingProcess $process) use ($names, $answered) {
        $command = implode(' ', (array) $process->command);

        if (! str_contains($command, 'docker volume ls')) {
            return Process::result(exitCode: 1);
        }

        if (! $answered) {
            return Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1);
        }

        return Process::result(output: implode("\n", array_map(
            fn (string $name): string => $name."\t/var/lib/docker/volumes/{$name}/_data",
            $names,
        )));
    });
}

function volumesTargetHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function volumesTargetPayload(string $type): array
{
    return [
        'storage_destination_id' => test()->destination->id,
        'type' => $type,
        'retention_count' => 7,
        'frequency' => 'daily',
        'schedule_time' => '02:00',
        'enabled' => true,
    ];
}

/** A target saved before the save refused it. */
function savedVolumesTarget(): BackupTarget
{
    return BackupTarget::create([
        'application_id' => test()->application->id,
        ...volumesTargetPayload('volumes'),
    ]);
}

describe('saving the target', function () {
    it('refuses volumes for a site with no volumes', function () {
        // Someone else's volume is on the box; none of it is this site's.
        dockerVolumes(['someone-elses']);

        $this->withHeaders(volumesTargetHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", volumesTargetPayload('volumes'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.type.0', __('backup.errors.target_no_volumes'));

        expect(BackupTarget::count())->toBe(0);
    });

    it('saves volumes for a site that has them', function () {
        dockerVolumes(['sv-app-'.$this->application->id.'_data']);

        $this->withHeaders(volumesTargetHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", volumesTargetPayload('volumes'))
            ->assertOk()
            ->assertJsonPath('backup_target.type', 'volumes');
    });

    it('counts a volume attached on the Container screen as the site\'s', function () {
        $this->application->forceFill(['volume_mounts' => [['volume' => 'shared-data', 'path' => '/data']]])->save();
        dockerVolumes(['shared-data']);

        $this->withHeaders(volumesTargetHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", volumesTargetPayload('volumes'))
            ->assertOk();
    });

    it('saves volumes_config for a site with no volumes', function () {
        // The default, and it works: the compose file is still worth keeping.
        dockerVolumes([]);

        $this->withHeaders(volumesTargetHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", volumesTargetPayload('volumes_config'))
            ->assertOk()
            ->assertJsonPath('backup_target.type', 'volumes_config');
    });

    it('does not refuse when Docker could not be asked', function () {
        // "Could not ask" is not "has none"; refusing would give a false reason.
        dockerVolumes([], answered: false);

        $this->withHeaders(volumesTargetHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", volumesTargetPayload('volumes'))
            ->assertOk();
    });
});

describe('running a saved volumes target', function () {
    it('refuses Back up now for a site with no volumes', function () {
        Queue::fake();
        dockerVolumes([]);
        savedVolumesTarget();

        $this->withHeaders(volumesTargetHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            ->assertUnprocessable()
            ->assertJsonPath('errors.application.0', __('backup.errors.target_no_volumes'));

        Queue::assertNotPushed(RunBackup::class);
    });

    it('refuses a retry for a site with no volumes', function () {
        Queue::fake();
        dockerVolumes([]);
        $target = savedVolumesTarget();

        $failed = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $this->application->id,
            'type' => 'volumes',
            'status' => BackupStatus::Failed->value,
            'reason' => 'archive_files',
        ]);

        $this->withHeaders(volumesTargetHeaders())
            ->postJson("/api/backups/{$failed->id}/retry")
            ->assertUnprocessable()
            ->assertJsonPath('errors.application.0', __('backup.errors.target_no_volumes'));

        Queue::assertNotPushed(RunBackup::class);
    });

    it('still runs once the site has a volume', function () {
        Queue::fake();
        dockerVolumes(['sv-app-'.$this->application->id.'_data']);
        savedVolumesTarget();

        $this->withHeaders(volumesTargetHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            ->assertAccepted();

        Queue::assertPushed(RunBackup::class);
    });
});

describe('what the form is told', function () {
    it('says whether a container site has volumes', function (array $names, bool $expected) {
        dockerVolumes(array_map(fn (string $name) => str_replace('{id}', (string) $this->application->id, $name), $names));

        $this->withHeaders(volumesTargetHeaders())
            ->getJson("/api/applications/{$this->application->id}/backup-target")
            ->assertOk()
            ->assertJsonPath('has_volumes', $expected);
    })->with([
        'none' => [[], false],
        'one of its own' => [['sv-app-{id}_data'], true],
    ]);

    it('says nothing when Docker could not be asked', function () {
        dockerVolumes([], answered: false);

        $this->withHeaders(volumesTargetHeaders())
            ->getJson("/api/applications/{$this->application->id}/backup-target")
            ->assertOk()
            ->assertJsonPath('has_volumes', null);
    });

    it('says nothing for a hosted site, and does not ask Docker', function () {
        dockerVolumes([]);
        $this->application->forceFill(['serving_profile' => 'php', 'site_type' => 'php'])->save();

        $this->withHeaders(volumesTargetHeaders())
            ->getJson("/api/applications/{$this->application->id}/backup-target")
            ->assertOk()
            ->assertJsonPath('has_volumes', null);

        Process::assertNotRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'docker'));
    });
});
