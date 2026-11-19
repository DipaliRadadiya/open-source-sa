<?php

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\StorageDestination;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/*
 * The backup overview's states, decided by the server (BackupCoverage) so the
 * frontend can page for real. It used to load 100 rows and filter them in the
 * browser, so a server with more than 100 sites lost the rest — and a site
 * whose scheduled backups failed was counted as protected.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->admin()->create(), 'sanctum');

    $this->destination = StorageDestination::create([
        'name' => 'Offsite', 'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's'],
    ]);
});

/** Backup settings for $owner (an application or a database). */
function stateTarget(Application|Database $owner, array $attributes = []): BackupTarget
{
    return BackupTarget::create($attributes + [
        $owner instanceof Database ? 'database_id' : 'application_id' => $owner->id,
        'storage_destination_id' => test()->destination->id,
        'type' => $owner instanceof Database ? 'database' : 'full',
        'retention_count' => 7, 'frequency' => 'daily', 'enabled' => true,
    ]);
}

/** One run of $target. */
function stateRun(BackupTarget $target, BackupStatus $status, bool $safety = false): Backup
{
    return Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $target->application_id,
        'database_id' => $target->database_id,
        'type' => $target->type->value,
        'status' => $status->value,
        'is_safety' => $safety,
        'manifest' => ['key' => uniqid().'.tar.gz'],
    ]);
}

/**
 * One site in each state, named after it:
 * unprotected (no settings), paused (switched off), manual (manual only = paused),
 * failing (scheduled, last run failed), protected (scheduled, last run verified),
 * fresh (scheduled, never ran = protected).
 *
 * @return array<string, Application>
 */
function stateSites(): array
{
    $sites = [];
    foreach (['unprotected', 'paused', 'manual', 'failing', 'protected', 'fresh'] as $name) {
        $sites[$name] = Application::factory()->create(['name' => $name, 'domain' => $name.'.example.test']);
    }

    stateTarget($sites['paused'], ['enabled' => false]);
    stateTarget($sites['manual'], ['frequency' => 'manual']);
    $failing = stateTarget($sites['failing']);
    stateRun($failing, BackupStatus::Verified);
    stateRun($failing, BackupStatus::Failed);
    stateRun(stateTarget($sites['protected']), BackupStatus::Verified);
    stateTarget($sites['fresh']);

    return $sites;
}

/** The names on one response, in order. */
function stateNames(string $query, string $column = 'application_name'): array
{
    return collect(test()->getJson('/api/backup-targets'.$query)->assertOk()->json('backup_targets'))
        ->pluck($column)->all();
}

describe('the state of each row', function () {
    it('is unprotected, paused, failing or protected, by the four rules', function () {
        stateSites();

        $states = collect($this->getJson('/api/backup-targets?per_page=100')->assertOk()->json('backup_targets'))
            ->pluck('state', 'application_name')->all();

        expect($states)->toEqual([
            'failing' => 'failing',
            'fresh' => 'protected',
            'protected' => 'protected',
            'manual' => 'paused',
            'paused' => 'paused',
            'unprotected' => 'unprotected',
        ]);
    });

    it('calls a scheduled site whose latest backup failed failing, not protected', function () {
        $site = Application::factory()->create();
        stateRun(stateTarget($site), BackupStatus::Failed);

        $this->getJson('/api/backup-targets?filter[protected]=1')->assertOk()->assertJsonCount(0, 'backup_targets');
        $this->getJson('/api/backup-targets')
            ->assertJsonPath('backup_targets.0.state', 'failing')
            ->assertJsonPath('meta.protected', 0)
            ->assertJsonPath('meta.failing', 1);
    });

    it('is protected again once a later backup succeeds', function () {
        $target = stateTarget(Application::factory()->create());
        stateRun($target, BackupStatus::Failed);
        stateRun($target, BackupStatus::Verified);

        $this->getJson('/api/backup-targets')->assertJsonPath('backup_targets.0.state', 'protected');
    });

    it('ignores a failed safety copy taken before a restore', function () {
        $target = stateTarget(Application::factory()->create());
        stateRun($target, BackupStatus::Verified);
        stateRun($target, BackupStatus::Failed, safety: true);

        $this->getJson('/api/backup-targets')->assertJsonPath('backup_targets.0.state', 'protected');
    });

    it('does not call a paused site failing, whatever its last run did', function () {
        stateRun(stateTarget(Application::factory()->create(), ['enabled' => false]), BackupStatus::Failed);

        $this->getJson('/api/backup-targets')->assertJsonPath('backup_targets.0.state', 'paused');
    });
});

describe('last_backup', function () {
    it('is the run the state was decided by, never a failed safety copy', function () {
        $target = stateTarget(Application::factory()->create());
        $run = stateRun($target, BackupStatus::Verified);
        stateRun($target, BackupStatus::Failed, safety: true);

        $this->getJson('/api/backup-targets')
            ->assertJsonPath('backup_targets.0.state', 'protected')
            ->assertJsonPath('backup_targets.0.last_backup.id', $run->id)
            ->assertJsonPath('backup_targets.0.last_backup.status', 'verified');
    });

    it('agrees with state on every row', function () {
        stateSites();

        foreach ($this->getJson('/api/backup-targets?per_page=100')->assertOk()->json('backup_targets') as $row) {
            $status = $row['last_backup']['status'] ?? null;

            match ($row['state']) {
                'failing' => expect($status)->toBe('failed', $row['application_name']),
                'protected' => expect($status)->not->toBe('failed', $row['application_name']),
                'unprotected' => expect($row['last_backup'])->toBeNull($row['application_name']),
                default => null,
            };
        }
    });

    it('is the same rule for a standalone database', function () {
        $target = stateTarget(Database::create(['name' => 'ledger', 'engine' => 'mysql']));
        $run = stateRun($target, BackupStatus::Verified);
        stateRun($target, BackupStatus::Failed, safety: true);

        $this->getJson('/api/backup-targets?kind=database')
            ->assertJsonPath('backup_targets.0.state', 'protected')
            ->assertJsonPath('backup_targets.0.last_backup.id', $run->id)
            ->assertJsonPath('backup_targets.0.last_backup.status', 'verified');
    });
});

describe('filter[state]', function () {
    it('returns only the rows in :dataset', function (string $state, array $names) {
        stateSites();

        expect(stateNames('?per_page=100&sort=name&filter[state]='.$state))->toBe($names);
    })->with([
        'unprotected' => ['unprotected', ['unprotected']],
        'paused' => ['paused', ['manual', 'paused']],
        'failing' => ['failing', ['failing']],
        'protected' => ['protected', ['fresh', 'protected']],
    ]);

    it('takes several, comma separated', function () {
        stateSites();

        expect(stateNames('?sort=name&filter[state]=failing,unprotected'))->toBe(['failing', 'unprotected'])
            ->and(stateNames('?sort=name&filter[state]=failing, paused'))->toBe(['failing', 'manual', 'paused']);
    });

    it('refuses a state that does not exist, or none at all', function (string $value) {
        $this->getJson('/api/backup-targets?filter[state]='.$value)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($value === '' ? 'filter.state' : 'filter.state.1');
    })->with(['failing,broken', '']);
});

describe('filter[protected]', function () {
    it('means unprotected, paused and failing when 0 — a failing site is not protected', function () {
        stateSites();

        expect(stateNames('?sort=name&filter[protected]=0'))->toBe(['failing', 'manual', 'paused', 'unprotected'])
            ->and(stateNames('?sort=name&filter[protected]=1'))->toBe(['fresh', 'protected']);
    });
});

describe('filter[type]', function () {
    it('returns only the sites whose settings back up that type, Docker types included', function () {
        $files = Application::factory()->create(['name' => 'files']);
        $data = Application::factory()->create(['name' => 'data']);
        $docker = Application::factory()->create(['name' => 'docker']);
        Application::factory()->create(['name' => 'none']);
        stateTarget($files, ['type' => 'filesystem']);
        stateTarget($data, ['type' => 'database']);
        stateTarget($docker, ['type' => 'volumes']);

        expect(stateNames('?sort=name&filter[type]=filesystem'))->toBe(['files'])
            ->and(stateNames('?sort=name&filter[type]=database,volumes'))->toBe(['data', 'docker'])
            ->and(stateNames('?sort=name&filter[type]=full'))->toBe([]);
    });

    it('refuses a type that does not exist', function () {
        $this->getJson('/api/backup-targets?filter[type]=everything')
            ->assertUnprocessable()->assertJsonValidationErrors('filter.type.0');
    });
});

describe('filters, search and pages together', function () {
    it('pages the filtered, searched rows, and the counts still describe the whole server', function () {
        // 12 failing "shop-" sites, 3 protected "shop-" sites, 2 failing others.
        foreach (range(1, 12) as $i) {
            stateRun(stateTarget(Application::factory()->create(['name' => sprintf('shop-%02d', $i)])), BackupStatus::Failed);
        }
        foreach (range(13, 15) as $i) {
            stateRun(stateTarget(Application::factory()->create(['name' => 'shop-'.$i])), BackupStatus::Verified);
        }
        foreach (['blog', 'wiki'] as $name) {
            stateRun(stateTarget(Application::factory()->create(['name' => $name])), BackupStatus::Failed);
        }

        $first = $this->getJson('/api/backup-targets?search=shop&filter[state]=failing&per_page=10')->assertOk();
        $second = $this->getJson('/api/backup-targets?search=shop&filter[state]=failing&per_page=10&page=2')->assertOk();

        expect($first->json('meta.matched'))->toBe(12)
            ->and($first->json('meta.last_page'))->toBe(2)
            ->and($first->json('backup_targets'))->toHaveCount(10)
            ->and($second->json('backup_targets'))->toHaveCount(2)
            ->and(array_merge(array_column($first->json('backup_targets'), 'application_name'), array_column($second->json('backup_targets'), 'application_name')))
            ->toBe(array_map(fn ($i) => sprintf('shop-%02d', $i), range(1, 12)))
            ->and($second->json('meta.total'))->toBe(17)
            ->and($second->json('meta.failing'))->toBe(14)
            ->and($second->json('meta.protected'))->toBe(3);
    });
});

describe('meta', function () {
    it('counts all four states over every site, and they add up to total', function () {
        stateSites();

        $meta = $this->getJson('/api/backup-targets?filter[state]=failing&search=fail')->assertOk()->json('meta');

        expect($meta)->toMatchArray([
            'total' => 6, 'protected' => 2, 'unprotected' => 1, 'paused' => 2, 'failing' => 1, 'configured' => 5, 'matched' => 1,
        ])
            ->and($meta['protected'] + $meta['unprotected'] + $meta['paused'] + $meta['failing'])->toBe($meta['total']);
    });
});

describe('sort=state', function () {
    it('is the default: failing, protected, paused, unprotected, then name', function () {
        stateSites();
        $order = ['failing', 'fresh', 'protected', 'manual', 'paused', 'unprotected'];

        expect(stateNames('?per_page=100'))->toBe($order)
            ->and(stateNames('?per_page=100&sort=state'))->toBe($order)
            ->and(stateNames('?per_page=100&sort=-state'))->toBe(['unprotected', 'manual', 'paused', 'fresh', 'protected', 'failing']);
    });

    it('still sorts by name when asked', function () {
        stateSites();

        expect(stateNames('?per_page=100&sort=name'))->toBe(['failing', 'fresh', 'manual', 'paused', 'protected', 'unprotected']);
    });

    it('refuses a sort that is not on the list', function () {
        $this->getJson('/api/backup-targets?sort=status')->assertUnprocessable()->assertJsonValidationErrors('sort');
    });
});

describe('kind=database', function () {
    it('gets the same states, filters, counts and sort', function () {
        $none = Database::create(['name' => 'none', 'engine' => 'mysql']);
        $failing = stateTarget(Database::create(['name' => 'failing', 'engine' => 'mysql']));
        stateRun($failing, BackupStatus::Failed);
        stateRun(stateTarget(Database::create(['name' => 'kept', 'engine' => 'pgsql'])), BackupStatus::Verified);
        stateTarget(Database::create(['name' => 'off', 'engine' => 'mysql']), ['enabled' => false]);
        // A site's own database is not on this list.
        Database::create(['name' => 'site_db', 'engine' => 'mysql', 'application_id' => Application::factory()->create()->id]);

        $response = $this->getJson('/api/backup-targets?kind=database')->assertOk();

        expect(array_column($response->json('backup_targets'), 'database_name'))->toBe(['failing', 'kept', 'off', 'none'])
            ->and(array_column($response->json('backup_targets'), 'state'))->toBe(['failing', 'protected', 'paused', 'unprotected'])
            ->and($response->json('meta'))->toMatchArray(['total' => 4, 'protected' => 1, 'unprotected' => 1, 'paused' => 1, 'failing' => 1])
            ->and(stateNames('?kind=database&sort=name&filter[protected]=0', 'database_name'))->toBe(['failing', 'none', 'off'])
            ->and(stateNames('?kind=database&filter[state]=failing', 'database_name'))->toBe(['failing'])
            ->and(stateNames('?kind=database&sort=name&filter[type]=database', 'database_name'))->toBe(['failing', 'kept', 'off']);

        expect($none->id)->toBeInt();
    });
});

describe('permissions', function () {
    it('refuses a user without the backup permission', function () {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson('/api/backup-targets?filter[state]=failing')->assertForbidden();
    });
});
