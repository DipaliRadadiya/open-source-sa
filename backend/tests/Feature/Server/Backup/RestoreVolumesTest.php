<?php

use App\Enums\BackupStatus;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Restore;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\Restores\Steps\RestoreVolumes;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The one step in this feature that can destroy data.
 *
 * Everything else writes to a working directory or uploads a file. This empties
 * a volume and extracts over it, so what is asserted here is mostly refusals:
 * it must stop the containers first, it must not touch a volume something else
 * holds, and it must not read "nothing is using it" out of a question Docker
 * failed to answer.
 */
beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/sv-restore-volumes-'.Str::random(8);
    File::makeDirectory("{$this->tmp}/staging/volumes", 0750, true);
    File::put("{$this->tmp}/staging/volumes/sv-app-1_data.tar", 'tar bytes');

    $user = SystemUser::create(['username' => 'owner', 'home_path' => "{$this->tmp}/home"]);

    $this->application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Ghost', 'slug' => 'ghost',
        'domain' => 'ghost.test', 'site_type' => 'ghost', 'serving_profile' => 'docker',
        'status' => 'active', 'web_root' => '/',
    ]);

    $destination = StorageDestination::create([
        'name' => 'dest-'.Str::random(6), 'provider' => 's3',
        'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's'],
    ]);

    $this->target = BackupTarget::create([
        'application_id' => $this->application->id,
        'storage_destination_id' => $destination->id,
        'type' => 'volumes_config', 'retention_count' => 2, 'frequency' => 'manual', 'enabled' => true,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * A ServerOps that answers each `op` from a map and records what ran.
 *
 * @param  array<string, ServerOpsResult>  $answers
 */
function restoreVolumeOps(array $answers, array &$ran = []): ServerOps
{
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturnUsing(
        function (array $command, array $context = []) use ($answers, &$ran) {
            $op = $context['op'] ?? '';
            $ran[] = ['op' => $op, 'command' => $command];

            return $answers[$op] ?? new ServerOpsResult(ok: true, reference: 'r', answered: true);
        }
    );

    return $ops;
}

function opsOutput(string $stdout, bool $answered = true, bool $ok = true): ServerOpsResult
{
    $process = Mockery::mock(ProcessResult::class);
    $process->shouldReceive('output')->andReturn($stdout);
    $process->shouldReceive('errorOutput')->andReturn('');

    return new ServerOpsResult(ok: $ok, reference: 'r', result: $process, answered: $answered);
}

/** Records whether the containers were asked to stop, and with what root. */
function spyingSupervisor(array &$stopped): ContainerSupervisor
{
    $supervisor = Mockery::mock(ContainerSupervisor::class);
    $supervisor->shouldReceive('stop')->andReturnUsing(
        function ($application, $documentRoot) use (&$stopped) {
            $stopped[] = ['id' => $application->id, 'root' => $documentRoot];

            return new ServerOpsResult(ok: true, reference: 'r', answered: true);
        }
    );

    return $supervisor;
}

/**
 * The resolver every other supervisor caller uses.
 *
 * Faked with a real-looking path because the *empty* case is the bug this guards
 * against: `document_root` is a computed value for a container site, its column
 * is blank, and stopping with a blank root finds no compose file and stops
 * nothing.
 */
function stubProvisioner(string $root = '/home/vaultowner/vault/public_html'): ApplicationProvisioner
{
    $provisioner = Mockery::mock(ApplicationProvisioner::class);
    $provisioner->shouldReceive('documentRoot')->andReturn($root);

    return $provisioner;
}

/**
 * Takes its state as arguments rather than reaching through `test()`.
 *
 * `test()->target->id` inside a plain helper in this suite fails with
 * "Undefined property ::$id" — the call does not resolve to the running test
 * case from here, and the error names the test class rather than the mistake.
 * Explicit parameters are both clearer and the only reliable form.
 */
function restoreVolumeContext(
    Application $application,
    BackupTarget $target,
    string $tmp,
    string $type = 'volumes_config',
): RestoreContext {
    $backup = Backup::create([
        'backup_target_id' => $target->id,
        'application_id' => $application->id,
        'type' => $type,
        'status' => BackupStatus::Verified->value,
        'manifest' => ['volumes' => [['name' => 'sv-app-1_data', 'file' => 'volumes/sv-app-1_data.tar']]],
    ]);

    $restore = Restore::create([
        'backup_id' => $backup->id,
        'application_id' => $application->id,
        'type' => $type,
        'status' => 'running',
    ]);

    $context = new RestoreContext($restore, $backup, $application, $tmp.'/work');
    $context->stagingDirectory = $tmp.'/staging';

    return $context;
}

it('stops the containers before writing to a volume', function () {
    // A container still writing would corrupt the incoming copy and lose its
    // own writes. A restore is already an outage, so stopping costs nothing
    // that is not already spent — unlike a backup, where downtime is refused.
    $stopped = [];
    $ran = [];

    $step = new RestoreVolumes(
        restoreVolumeOps(['restore_volume_in_use' => opsOutput('')], $ran),
        spyingSupervisor($stopped),
        stubProvisioner(),
    );

    $step->run(restoreVolumeContext($this->application, $this->target, $this->tmp));

    // The root matters as much as the fact of stopping: a blank one finds no
    // compose file, stops nothing, and leaves the guard below to refuse.
    expect($stopped)->toHaveCount(1)
        ->and($stopped[0]['id'])->toBe($this->application->id)
        ->and($stopped[0]['root'])->not->toBe('');

    // And the write really did happen, through a container rather than the host.
    $write = collect($ran)->firstWhere('op', 'restore_volume');
    expect($write)->not->toBeNull()
        ->and($write['command'][0])->toBe('docker')
        ->and($write['command'][1])->toBe('run');
});

it('empties the volume including dotfiles before extracting', function () {
    // tar extracting over existing contents merges, so without the delete a
    // restored volume keeps files the backup never contained — and `rm -rf
    // /volume/*` would leave the dotfiles, which is where an application's
    // "already set up" marker lives.
    $ran = [];
    $stopped = [];

    (new RestoreVolumes(
        restoreVolumeOps(['restore_volume_in_use' => opsOutput('')], $ran),
        spyingSupervisor($stopped),
        stubProvisioner(),
    ))->run(restoreVolumeContext($this->application, $this->target, $this->tmp));

    $script = collect($ran)->firstWhere('op', 'restore_volume')['command'];
    $shell = end($script);

    expect($shell)->toContain('find . -mindepth 1 -delete')
        ->and($shell)->toContain('tar -xf')
        ->and($shell)->not->toContain('rm -rf');
});

it('refuses to overwrite a volume another container is using', function () {
    // The site's own containers are stopped by this point, so anything still
    // holding the volume belongs to something that is not expecting its data
    // to be replaced.
    $ran = [];
    $stopped = [];

    $step = new RestoreVolumes(
        restoreVolumeOps(['restore_volume_in_use' => opsOutput("other-site-app\n")], $ran),
        spyingSupervisor($stopped),
        stubProvisioner(),
    );

    expect(fn () => $step->run(restoreVolumeContext($this->application, $this->target, $this->tmp)))
        ->toThrow(RuntimeException::class, 'still in use by other-site-app');

    // And nothing was written.
    expect(collect($ran)->firstWhere('op', 'restore_volume'))->toBeNull();
});

it('refuses when docker could not say whether the volume is in use', function () {
    // A question that could not be answered is not permission to proceed.
    // Reading "nothing is using it" out of a failed `docker ps` is how a
    // restore overwrites live data.
    $ran = [];
    $stopped = [];

    $step = new RestoreVolumes(
        restoreVolumeOps(['restore_volume_in_use' => opsOutput('', answered: false)], $ran),
        spyingSupervisor($stopped),
        stubProvisioner(),
    );

    expect(fn () => $step->run(restoreVolumeContext($this->application, $this->target, $this->tmp)))
        ->toThrow(RuntimeException::class, 'could not check whether');

    expect(collect($ran)->firstWhere('op', 'restore_volume'))->toBeNull();
});

it('refuses a volumes restore of an archive that holds no volumes', function () {
    $unused = [];
    // Said plainly rather than skipped: continuing would report success for
    // having restored nothing, which is the failure mode this whole feature
    // exists to avoid.
    File::deleteDirectory($this->tmp.'/staging/volumes');

    $step = new RestoreVolumes(restoreVolumeOps([]), spyingSupervisor($unused), stubProvisioner());

    expect(fn () => $step->run(restoreVolumeContext($this->application, $this->target, $this->tmp)))
        ->toThrow(RuntimeException::class, 'contains no volumes');
});

it('leaves the containers down for RestartProcess to bring up', function () {
    // Starting here would start them before `SwapFiles` has put the restored
    // compose file in place, so a volumes+config restore would come up on the
    // old definition and then have it swapped underneath.
    $ran = [];
    $stopped = [];

    (new RestoreVolumes(
        restoreVolumeOps(['restore_volume_in_use' => opsOutput('')], $ran),
        spyingSupervisor($stopped),
        stubProvisioner(),
    ))->run(restoreVolumeContext($this->application, $this->target, $this->tmp));

    expect(collect($ran)->pluck('op')->all())->not->toContain('container_start');
});

it('applies only to a restore that includes volumes', function (string $type, bool $expected) {
    $unused = [];
    $step = new RestoreVolumes(restoreVolumeOps([]), spyingSupervisor($unused), stubProvisioner());

    expect($step->appliesTo(restoreVolumeContext($this->application, $this->target, $this->tmp, $type)))->toBe($expected);
})->with([
    ['volumes', true],
    ['volumes_config', true],
    ['config', false],
    ['full', false],
]);
