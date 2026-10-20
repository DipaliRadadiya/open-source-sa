<?php

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\Deployment;
use App\Models\PanelUpdate;
use App\Models\Restore;
use App\Models\RuntimeInstall;
use App\Models\StagingRun;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
 * RC-20 (Krishna, 17 Oct): long jobs disappeared from view once the user left
 * the page that started them, and there was no single place to ask what is
 * running. GET /operations lists them all, cut down to what the role may see.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->site = Application::forceCreate([
        'system_user_id' => SystemUser::create(['username' => 'ops', 'home_path' => '/home/ops'])->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test', 'site_type' => 'php',
        'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
    ]);
});

function operations(User $user, string $query = ''): array
{
    return test()->actingAs($user)->getJson('/api/operations'.$query)->assertOk()->json();
}

function aged(Model $row, int $hours): Model
{
    $row->forceFill(['created_at' => now()->subHours($hours)])->save();

    return $row;
}

it('lists every kind of job with one state word for all of them', function () {
    $backup = Backup::create(['application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Verified, 'finished_at' => now()]);
    $restore = Restore::create(['backup_id' => $backup->id, 'application_id' => $this->site->id, 'type' => 'full', 'status' => RestoreStatus::Running, 'current_step' => 'swap_files', 'reference' => (string) Str::uuid()]);
    Deployment::create(['application_id' => $this->site->id, 'trigger' => 'manual', 'status' => 'queued', 'branch' => 'main']);
    $db = Database::create(['name' => 'ledger', 'engine' => 'mysql']);
    DatabaseExport::create(['database_id' => $db->id, 'database_name' => 'ledger', 'engine' => 'mysql', 'status' => 'failed']);
    RuntimeInstall::create(['runtime' => 'php', 'version' => '8.3', 'extension' => '', 'status' => 'installing', 'started_at' => now()]);
    PanelUpdate::create(['status' => 'succeeded', 'rolled_back' => false, 'dry_run' => false]);

    $ops = collect(operations($this->admin)['operations'])->keyBy('kind');

    expect($ops->keys()->sort()->values()->all())->toBe(['backup', 'database_export', 'deployment', 'panel_update', 'restore', 'runtime_install'])
        ->and($ops['backup']['state'])->toBe('done')->and($ops['backup']['status'])->toBe('verified')
        ->and($ops['backup']['subject'])->toBe(['type' => 'application', 'id' => $this->site->id, 'name' => 'Shop'])
        ->and($ops['restore']['state'])->toBe('running')->and($ops['restore']['step'])->toBe('swap_files')
        ->and($ops['restore']['reference'])->toBe($restore->reference)
        ->and($ops['deployment']['state'])->toBe('waiting')
        ->and($ops['database_export']['state'])->toBe('failed')
        ->and($ops['database_export']['subject'])->toBe(['type' => 'database', 'id' => $db->id, 'name' => 'ledger'])
        ->and($ops['runtime_install']['state'])->toBe('running')
        ->and($ops['panel_update']['state'])->toBe('done');
});

it('shows a role only the kinds it can already see', function () {
    Backup::create(['application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Running]);
    Deployment::create(['application_id' => $this->site->id, 'trigger' => 'manual', 'status' => 'running', 'branch' => 'main']);
    PanelUpdate::create(['status' => 'running', 'rolled_back' => false, 'dry_run' => false]);
    $viewer = User::factory()->create();
    grantPermission($viewer, 'backup');

    $kinds = array_column(operations($viewer)['operations'], 'kind');

    expect($kinds)->toBe(['backup']);
});

it('shows nothing to a role with none of the permissions', function () {
    Backup::create(['application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Running]);

    expect(operations(User::factory()->create())['operations'])->toBe([]);
});

it('keeps running work however old, and finished work only inside the window', function () {
    aged(Backup::create(['application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Running]), 50);
    aged(Backup::create(['application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Verified]), 30);
    aged(Backup::create(['application_id' => $this->site->id, 'type' => 'full', 'status' => BackupStatus::Failed]), 2);

    expect(array_column(operations($this->admin)['operations'], 'state'))->toBe(['failed', 'running'])
        ->and(array_column(operations($this->admin, '?hours=48')['operations'], 'state'))->toBe(['failed', 'done', 'running'])
        ->and(array_column(operations($this->admin, '?active=1')['operations'], 'state'))->toBe(['running'])
        ->and(operations($this->admin)['meta']['running'])->toBe(1);
});

it('reports a staging run whose worker died as failed', function () {
    $run = StagingRun::create(['application_id' => $this->site->id, 'kind' => 'push', 'mode' => 'files', 'status' => StagingRun::RUNNING]);
    $run->forceFill(['created_at' => now()->subSeconds(StagingRun::staleAfterSeconds() + 60)])->save();

    expect(collect(operations($this->admin, '?hours=168')['operations'])->firstWhere('kind', 'staging')['state'])->toBe('failed');
});

it('refuses a window longer than a week', function () {
    $this->actingAs($this->admin)->getJson('/api/operations?hours=500')->assertUnprocessable()->assertJsonValidationErrors('hours');
});

it('needs a signed-in user', function () {
    $this->getJson('/api/operations')->assertUnauthorized();
});
