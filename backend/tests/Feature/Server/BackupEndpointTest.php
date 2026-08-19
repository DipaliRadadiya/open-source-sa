<?php

use App\Enums\BackupStatus;
use App\Http\Requests\Server\Backup\BulkDeleteBackupsRequest;
use App\Jobs\RunBackup;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Backups\Storage\DestinationDisk;
use Database\Seeders\PermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
    ]);

    $this->destination = StorageDestination::create([
        'name' => 'Offsite',
        'endpoint' => '',
        'region' => 'us-east-1',
        'bucket' => 'backups',
        'access_key' => 'k',
        'secret_key' => 's',
    ]);
});

function backupHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function targetPayload(array $overrides = []): array
{
    return array_merge([
        'storage_destination_id' => test()->destination->id,
        'type' => 'full',
        'retention_count' => 7,
        'frequency' => 'daily',
        'enabled' => true,
    ], $overrides);
}

it('returns null when backups are not configured for an application', function () {
    $this->withHeaders(backupHeaders())
        ->getJson("/api/applications/{$this->application->id}/backup-target")
        ->assertOk()
        ->assertJsonPath('backup_target', null);
});

it('configures backups for an application', function () {
    $response = $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload());

    $response->assertOk()
        ->assertJsonPath('backup_target.type', 'full')
        ->assertJsonPath('backup_target.frequency', 'daily')
        ->assertJsonPath('backup_target.retention_count', 7);

    // Localized labels come from the backend so the frontend never holds a
    // frequency or type list.
    expect($response->json('backup_target.frequency_title'))->toBe('Daily')
        ->and($response->json('backup_target.type_title'))->toBe('Files and database');
});

it('edits rather than duplicating when saved twice', function () {
    $url = "/api/applications/{$this->application->id}/backup-target";

    $this->withHeaders(backupHeaders())->putJson($url, targetPayload())->assertOk();
    $this->withHeaders(backupHeaders())->putJson($url, targetPayload(['frequency' => 'weekly']))->assertOk();

    // The table has a unique on application_id — a second row would be a
    // constraint violation, not a second schedule.
    expect(BackupTarget::count())->toBe(1)
        ->and(BackupTarget::first()->frequency)->toBe('weekly');
});

it('refuses a retention of zero', function () {
    // Zero would mean every run prunes the backup it just took, which reads
    // as "backups silently do nothing".
    $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload(['retention_count' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('retention_count');
});

it('refuses an unknown frequency', function () {
    $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload(['frequency' => 'hourly']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('frequency');
});

it('refuses a storage destination that does not exist', function () {
    $this->withHeaders(backupHeaders())
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload(['storage_destination_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('storage_destination_id');
});

describe('running a backup', function () {
    it('queues the job', function () {
        Queue::fake();

        $this->withHeaders(backupHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
            ->assertOk();

        $this->withHeaders(backupHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            // 202: the run happens on the queue, and the Backup row does not
            // exist until a worker picks it up.
            ->assertStatus(202);

        // The queue, not just the job. `assertPushed` alone passes for a job
        // sent anywhere, which is how this shipped pointing at a `backups`
        // queue that no worker consumed — every backup was accepted, stored
        // and never run, with no error to show for it.
        //
        // Null rather than 'default': naming no queue is what makes the job
        // land on the connection's default, and it is the bare `queue:work`
        // in install.sh that drains it.
        Queue::assertPushed(RunBackup::class, fn (RunBackup $job): bool => $job->queue === null);
    });

    it('refuses when backups are not configured', function () {
        Queue::fake();

        $this->withHeaders(backupHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    });

    it('refuses a second run while one is in flight', function () {
        Queue::fake();

        $this->withHeaders(backupHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
            ->assertOk();

        Backup::create([
            'backup_target_id' => BackupTarget::first()->id,
            'application_id' => $this->application->id,
            'type' => 'full',
            'status' => BackupStatus::Running,
        ]);

        // Two archives of the same site at once compete for the same disk.
        $this->withHeaders(backupHeaders())
            ->postJson("/api/applications/{$this->application->id}/backups")
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    });
});

it('lists backups across every application', function () {
    $target = BackupTarget::create(array_merge(
        ['application_id' => $this->application->id],
        targetPayload(),
    ));

    Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $this->application->id,
        'type' => 'full',
        'status' => BackupStatus::Verified,
    ]);

    $response = $this->withHeaders(backupHeaders())->getJson('/api/backups');

    $response->assertOk()
        ->assertJsonPath('backups.0.status', 'verified')
        ->assertJsonStructure(['backups', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);

    expect($response->json('backups.0.status_title'))->toBe('Complete');
});

describe('filtering the restore list', function () {
    beforeEach(function () {
        $this->target = BackupTarget::create(array_merge(
            ['application_id' => $this->application->id],
            targetPayload(),
        ));

        $this->makeBackup = function (string $type, string $createdAt): Backup {
            $backup = Backup::create([
                'backup_target_id' => $this->target->id,
                'application_id' => $this->application->id,
                'type' => $type,
                'status' => BackupStatus::Verified,
            ]);

            // created_at is what the range filters on, and the factory-less
            // create() stamps it as now.
            $backup->forceFill(['created_at' => $createdAt])->save();

            return $backup;
        };
    });

    it('filters by type', function () {
        ($this->makeBackup)('database', '2026-03-03 02:00:00');
        ($this->makeBackup)('full', '2026-03-04 02:00:00');

        $response = $this->withHeaders(backupHeaders())
            ->getJson('/api/backups?filter[type]=database')
            ->assertOk();

        expect($response->json('backups'))->toHaveCount(1)
            ->and($response->json('backups.0.type'))->toBe('database');
    });

    it('filters by date range, inclusive at both ends', function () {
        ($this->makeBackup)('full', '2026-03-02 23:00:00');
        ($this->makeBackup)('full', '2026-03-03 02:00:00');
        // Late on the last day of the range: `to` is taken as end-of-day, so
        // asking "to the 4th" must not silently drop the 4th.
        ($this->makeBackup)('full', '2026-03-04 23:30:00');
        ($this->makeBackup)('full', '2026-03-05 02:00:00');

        $response = $this->withHeaders(backupHeaders())
            ->getJson('/api/backups?filter[from]=2026-03-03&filter[to]=2026-03-04')
            ->assertOk();

        expect($response->json('backups'))->toHaveCount(2);
    });

    it('combines type and date range', function () {
        ($this->makeBackup)('database', '2026-03-03 02:00:00');
        ($this->makeBackup)('full', '2026-03-03 02:00:00');
        ($this->makeBackup)('database', '2026-03-09 02:00:00');

        $response = $this->withHeaders(backupHeaders())
            ->getJson('/api/backups?filter[type]=database&filter[from]=2026-03-01&filter[to]=2026-03-05')
            ->assertOk();

        expect($response->json('backups'))->toHaveCount(1);
    });

    it('rejects an unknown status instead of returning an empty list', function () {
        // Silently returning nothing reads to the user as "there are no
        // backups", which is a different and much more alarming statement.
        $this->withHeaders(backupHeaders())
            ->getJson('/api/backups?filter[status]=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filter.status');
    });

    it('rejects a reversed date range', function () {
        $this->withHeaders(backupHeaders())
            ->getJson('/api/backups?filter[from]=2026-03-09&filter[to]=2026-03-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filter.to');
    });
});

describe('the cross-application overview', function () {
    beforeEach(function () {
        // A second site, deliberately left unconfigured: the whole point of
        // the overview is that it shows up.
        $this->unprotected = Application::forceCreate([
            'system_user_id' => $this->application->system_user_id,
            'name' => 'Blog',
            'slug' => 'blog',
            'domain' => 'blog.example.test',
            'site_type' => 'php',
            'serving_profile' => 'php',
            'status' => 'active',
        ]);
    });

    it('lists configured and unconfigured applications alike', function () {
        $target = BackupTarget::create(array_merge(
            ['application_id' => $this->application->id],
            targetPayload(),
        ));

        $response = $this->withHeaders(backupHeaders())->getJson('/api/backup-targets');

        // Ordered by name: Blog before Shop.
        $response->assertOk()
            ->assertJsonPath('backup_targets.0.application_name', 'Blog')
            ->assertJsonPath('backup_targets.0.application_domain', 'blog.example.test')
            ->assertJsonPath('backup_targets.0.backup_target', null)
            ->assertJsonPath('backup_targets.0.last_backup', null)
            ->assertJsonPath('backup_targets.1.application_id', $this->application->id)
            ->assertJsonPath('backup_targets.1.backup_target.id', $target->id)
            ->assertJsonPath('backup_targets.1.backup_target.frequency', 'daily')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.protected', 1)
            ->assertJsonPath('meta.unprotected', 1);
    });

    it('reports the newest backup, however it ended', function () {
        $target = BackupTarget::create(array_merge(
            ['application_id' => $this->application->id],
            targetPayload(),
        ));

        Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $this->application->id,
            'type' => 'full',
            'status' => BackupStatus::Verified,
        ]);

        // The most recent run failed. A screen that answers "am I protected?"
        // must show this one, not skip back to the last success.
        $failed = Backup::create([
            'backup_target_id' => $target->id,
            'application_id' => $this->application->id,
            'type' => 'full',
            'status' => BackupStatus::Failed,
            'reason' => 'upload_artifact',
        ]);

        $this->withHeaders(backupHeaders())
            ->getJson('/api/backup-targets')
            ->assertOk()
            ->assertJsonPath('backup_targets.1.last_backup.id', $failed->id)
            ->assertJsonPath('backup_targets.1.last_backup.status', 'failed');
    });

    it('denies a user without the backup permission', function () {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/backup-targets')
            ->assertForbidden();
    });
});

describe('the next scheduled run', function () {
    it('is computed for every scheduled frequency', function (string $frequency, string $expected) {
        $this->travelTo('2026-03-10 12:00:00');

        $response = $this->withHeaders(backupHeaders())
            ->putJson(
                "/api/applications/{$this->application->id}/backup-target",
                targetPayload(['frequency' => $frequency]),
            );

        $response->assertOk()->assertJsonPath('backup_target.next_run_at', $expected);

        expect($response->json('backup_target.next_run_at_human'))->not->toBeNull();
    })->with([
        // Tuesday 12:00 → tonight, the coming Sunday, the 1st of next month.
        ['daily', '11-03-2026 02:00:00'],
        ['weekly', '15-03-2026 02:00:00'],
        ['monthly', '01-04-2026 02:00:00'],
    ]);

    it('reports a brand-new target as due, not as scheduled for tomorrow', function () {
        $this->travelTo('2026-03-10 10:00:00');

        // A target that has never run is picked up on the next scheduler
        // tick, within a minute — so a UI showing only next_run_at would
        // name tomorrow at exactly the moment the first backup happens.
        $this->withHeaders(backupHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
            ->assertOk()
            ->assertJsonPath('backup_target.is_due', true)
            ->assertJsonPath('backup_target.next_run_at', '11-03-2026 02:00:00');
    });

    it('stops reporting due once a run has been recorded', function () {
        $this->travelTo('2026-03-10 10:00:00');

        $this->withHeaders(backupHeaders())
            ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
            ->assertOk();

        BackupTarget::first()->update(['last_run_at' => now()]);

        $this->withHeaders(backupHeaders())
            ->getJson("/api/applications/{$this->application->id}/backup-target")
            ->assertOk()
            ->assertJsonPath('backup_target.is_due', false);
    });

    it('is never due when manual or disabled', function () {
        $this->withHeaders(backupHeaders())
            ->putJson(
                "/api/applications/{$this->application->id}/backup-target",
                targetPayload(['frequency' => 'manual']),
            )
            ->assertJsonPath('backup_target.is_due', false);

        $this->withHeaders(backupHeaders())
            ->putJson(
                "/api/applications/{$this->application->id}/backup-target",
                targetPayload(['enabled' => false]),
            )
            ->assertJsonPath('backup_target.is_due', false);
    });

    it('is null for a manual target', function () {
        $this->withHeaders(backupHeaders())
            ->putJson(
                "/api/applications/{$this->application->id}/backup-target",
                targetPayload(['frequency' => 'manual']),
            )
            ->assertOk()
            ->assertJsonPath('backup_target.next_run_at', null)
            ->assertJsonPath('backup_target.next_run_at_human', null);
    });

    it('is null for a disabled target', function () {
        // A disabled target has a frequency but no next run. Showing one
        // would promise a backup that is never taken.
        $this->withHeaders(backupHeaders())
            ->putJson(
                "/api/applications/{$this->application->id}/backup-target",
                targetPayload(['enabled' => false]),
            )
            ->assertOk()
            ->assertJsonPath('backup_target.next_run_at', null);
    });
});

describe('downloading a backup', function () {
    beforeEach(function () {
        $this->target = BackupTarget::create(array_merge(
            ['application_id' => $this->application->id],
            targetPayload(),
        ));

        $this->backup = Backup::create([
            'backup_target_id' => $this->target->id,
            'application_id' => $this->application->id,
            'type' => 'full',
            'status' => BackupStatus::Verified,
            'size_bytes' => 5382914,
            'manifest' => ['key' => 'a1b2c3.tar.gz'],
        ]);

        // A disk that answers exists()/temporaryUrl() without an S3 endpoint.
        // Mocked as FilesystemAdapter rather than the Filesystem contract on
        // purpose: only the adapter declares temporaryUrl (the contract has
        // no signing), and DestinationDisk::for() returns the contract — an
        // anonymous stub would fail its return type.
        $this->fakeDestinationDisk = function (bool $exists = true) {
            $disk = Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('exists')->andReturn($exists);
            $disk->shouldReceive('temporaryUrl')
                ->andReturn('https://bucket.example.com/a1b2c3.tar.gz?X-Amz-Signature=deadbeef');

            $this->app->bind(
                DestinationDisk::class,
                fn () => new DestinationDisk(builder: fn (array $config) => $disk),
            );
        };
    });

    it('returns a signed url, an expiry and a filename someone can recognise', function () {
        ($this->fakeDestinationDisk)();
        $this->travelTo('2026-03-10 12:00:00');

        $response = $this->withHeaders(backupHeaders())
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertOk();

        expect($response->json('download.url'))->toContain('X-Amz-Signature')
            ->and($response->json('download.expires_at'))->toBe('10-03-2026 12:05:00')
            ->and($response->json('download.size_bytes'))->toBe(5382914)
            // The object key is a uuid — useless once four of them are side
            // by side in a downloads folder.
            ->and($response->json('download.filename'))->toContain('shop-example-test')
            ->and($response->json('download.filename'))->toEndWith('-full.tar.gz');
    });

    it('records who asked, without putting the signed url in the log', function () {
        ($this->fakeDestinationDisk)();

        $this->withHeaders(backupHeaders())
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertOk();

        $entry = ActivityLog::where('type', 'backup')->where('action', 'downloaded')->first();

        expect($entry)->not->toBeNull()
            ->and($entry->user_id)->toBe($this->admin->id)
            // The URL carries a working credential for five minutes. It has
            // no business being replayable out of the audit trail.
            ->and(json_encode($entry->properties))->not->toContain('X-Amz-Signature');
    });

    it('refuses when the upload never finished', function () {
        ($this->fakeDestinationDisk)();

        $this->backup->update(['manifest' => []]);

        $this->withHeaders(backupHeaders())
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup');
    });

    it('refuses when the archive is gone from the destination', function () {
        // Saying so beats handing over a link that 404s in the browser,
        // where it looks like the panel is broken rather than the bucket.
        ($this->fakeDestinationDisk)(false);

        $this->withHeaders(backupHeaders())
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup');
    });

    it('allows a failed backup to be downloaded for forensics', function () {
        // Unlike restore: downloading overwrites nothing, and a partial
        // archive is sometimes exactly what explains the failure.
        ($this->fakeDestinationDisk)();

        $this->backup->update(['status' => BackupStatus::Failed, 'reason' => 'verify_artifact']);

        $this->withHeaders(backupHeaders())
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertOk();
    });

    it('denies a user who can view backups but not manage them', function () {
        ($this->fakeDestinationDisk)();

        // The restore tier, not the read tier: this URL is every file on the
        // site plus the database.
        $user = User::factory()->create();
        grantPermission($user, 'backup');
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/backups/{$this->backup->id}/download")
            ->assertForbidden();
    });
});

it('denies a user without the backup permissions', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/backups')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/applications/{$this->application->id}/backup-target", targetPayload())
        ->assertForbidden();
});

it('denies an unauthenticated request', function () {
    $this->getJson('/api/backups')->assertUnauthorized();
});

it('has copy for every step, status, type and frequency in every locale', function () {
    foreach (config('app.available_locales') as $locale) {
        app()->setLocale($locale);

        foreach (['dump_database', 'archive_files', 'upload_artifact', 'verify_artifact', 'prune_old_backups'] as $step) {
            expect(__('backup.steps.'.$step))->not->toBe('backup.steps.'.$step);
            // Every step is also a failure reason, because `reason` holds the
            // step that failed.
            expect(__('backup.errors.'.$step))->not->toBe('backup.errors.'.$step);
        }

        foreach (BackupStatus::cases() as $status) {
            expect(__('backup.status.'.$status->value))->not->toBe('backup.status.'.$status->value);
        }

        foreach (BackupTarget::FREQUENCIES as $frequency) {
            expect(__('backup.frequency.'.$frequency))->not->toBe('backup.frequency.'.$frequency);
        }
    }
});

describe('deleting backups in bulk', function () {
    beforeEach(function () {
        $this->target = BackupTarget::create(array_merge(
            ['application_id' => $this->application->id],
            targetPayload(),
        ));

        $this->makeBackup = function (BackupStatus $status = BackupStatus::Verified, string $key = 'a.tar.gz') {
            return Backup::create([
                'backup_target_id' => $this->target->id,
                'application_id' => $this->application->id,
                'type' => 'full',
                'status' => $status,
                'size_bytes' => 100,
                'manifest' => ['key' => $key],
            ]);
        };

        $this->fakeDisk = function (bool $deletes = true) {
            $disk = Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('exists')->andReturn(true);
            $deletes
                ? $disk->shouldReceive('delete')->andReturn(true)
                : $disk->shouldReceive('delete')->andThrow(new RuntimeException('bucket unreachable'));

            $this->app->bind(
                DestinationDisk::class,
                fn () => new DestinationDisk(builder: fn (array $config) => $disk),
            );
        };
    });

    it('deletes every selected backup and its archive', function () {
        ($this->fakeDisk)();
        $a = ($this->makeBackup)();
        $b = ($this->makeBackup)();

        $response = $this->withHeaders(backupHeaders())
            ->deleteJson('/api/backups', ['ids' => [$a->id, $b->id]]);

        $response->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('failed', []);

        expect(Backup::query()->whereIn('id', [$a->id, $b->id])->count())->toBe(0);
    });

    it('deletes the rest and names the one it could not, rather than failing the batch', function () {
        // Twenty backups where one is mid-run must delete the nineteen. Failing
        // the whole request hides deletions the user would then repeat; a quiet
        // success hides an archive still being paid for.
        ($this->fakeDisk)();
        $ok = ($this->makeBackup)();
        $running = ($this->makeBackup)(BackupStatus::Running);

        $response = $this->withHeaders(backupHeaders())
            ->deleteJson('/api/backups', ['ids' => [$ok->id, $running->id]]);

        $response->assertOk()
            ->assertJsonPath('deleted', false)
            ->assertJsonPath('succeeded', [$ok->id])
            ->assertJsonPath('failed.0.id', $running->id)
            ->assertJsonPath('failed.0.reason', 'running');

        expect(Backup::find($ok->id))->toBeNull()
            ->and(Backup::find($running->id))->not->toBeNull();
    });

    it('keeps the row when the archive could not be removed', function () {
        // The order the single delete established: archive first, row second.
        // A row surviving a failed storage delete is visible and retryable; the
        // reverse leaves an object nothing in the panel can find.
        ($this->fakeDisk)(deletes: false);
        $backup = ($this->makeBackup)();

        $this->withHeaders(backupHeaders())
            ->deleteJson('/api/backups', ['ids' => [$backup->id]])
            ->assertOk()
            ->assertJsonPath('deleted', false)
            ->assertJsonPath('failed.0.reason', 'artifact');

        expect(Backup::find($backup->id))->not->toBeNull();
    });

    it('refuses an empty selection rather than reporting nothing deleted', function () {
        $this->withHeaders(backupHeaders())
            ->deleteJson('/api/backups', ['ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    });

    it('refuses more than one page of backups in a single request', function () {
        $ids = range(1, BulkDeleteBackupsRequest::MAX + 1);

        $this->withHeaders(backupHeaders())
            ->deleteJson('/api/backups', ['ids' => $ids])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    });

    it('denies a user who may view backups but not manage them', function () {
        // Deleting destroys the copy that exists to survive a mistake, so it
        // sits at the same tier as restore rather than with the schedule.
        $viewer = User::factory()->create();
        grantPermission($viewer, 'backup', view: true, manage: false);
        $token = $viewer->createToken('t')->plainTextToken;
        $backup = ($this->makeBackup)();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/backups', ['ids' => [$backup->id]])
            ->assertForbidden();

        expect(Backup::find($backup->id))->not->toBeNull();
    });
});
