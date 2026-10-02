<?php

use App\Models\Cronjob;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
 * Bug #17: the slug is the cron.d filename. A job named "panel-scheduler"
 * overwrote the panel's own scheduler, and deleting the job deleted it.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

    $this->cronD = sys_get_temp_dir().'/sv-oss-cron-foreign-'.getmypid();
    File::deleteDirectory($this->cronD);
    File::makeDirectory($this->cronD, 0755, true);
    config(['server.cron_d' => $this->cronD]);

    // The panel's scheduler, written by install.sh, owned by no job.
    File::put("{$this->cronD}/panel-scheduler", "* * * * * panel php artisan schedule:run\n");
});

afterEach(fn () => File::deleteDirectory($this->cronD));

function createJob(string $name): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)->postJson('/api/cronjobs', [
        'name' => $name, 'username' => 'www-data', 'command' => 'true', 'expression' => '* * * * *',
    ]);
}

it('never writes over a cron.d file the panel does not own', function () {
    Process::fake();

    $response = createJob('panel-scheduler')->assertCreated();

    expect($response->json('cronjob.slug'))->toBe('panel-scheduler-2');
    Process::assertRan(fn ($p) => $p->command === ['tee', "{$this->cronD}/panel-scheduler-2"]);
    Process::assertNotRan(fn ($p) => $p->command === ['tee', "{$this->cronD}/panel-scheduler"]);
});

it('deleting such a job leaves the foreign file alone', function () {
    Process::fake();
    $id = createJob('panel-scheduler')->json('cronjob.id');

    $this->withHeader('Authorization', "Bearer {$this->token}")->deleteJson("/api/cronjobs/{$id}")->assertSuccessful();

    Process::assertNotRan(fn ($p) => in_array("{$this->cronD}/panel-scheduler", (array) $p->command, true));
});

it('keeps a job on its own file when it is edited', function () {
    Process::fake();
    $job = Cronjob::create(['name' => 'Nightly', 'slug' => 'nightly', 'username' => 'www-data', 'command' => 'true', 'expression' => '* * * * *', 'active' => true]);
    File::put("{$this->cronD}/nightly", "x\n");

    // Its own file exists on disk; that must not push it to "nightly-2".
    expect(Cronjob::uniqueSlug('Nightly', $job->id))->toBe('nightly');
});

it('treats the file a job was imported from as its own', function () {
    File::put("{$this->cronD}/backup", "x\n");
    $job = Cronjob::create(['name' => 'Imported', 'slug' => 'true-abc123', 'source_path' => "{$this->cronD}/backup", 'username' => 'root', 'command' => 'true', 'expression' => '* * * * *', 'active' => true]);

    expect(Cronjob::uniqueSlug('backup', $job->id))->toBe('backup');
});

it('still names a job plainly when nothing is in the way', function () {
    expect(Cronjob::uniqueSlug('Nightly backup'))->toBe('nightly-backup');
});
