<?php

use App\Models\Cronjob;
use App\Models\Permission;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    $this->su = SystemUser::create(['username' => 'deploy', 'home_path' => '/home/deploy', 'shell' => '/bin/bash', 'sudo' => false]);
});

it('creates a cron job for a panel system user, writing a cron.d file', function () {
    Process::fake();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', [
            'name' => 'Nightly backup',
            'system_user_id' => $this->su->id,
            'command' => '/home/deploy/backup.sh',
            'expression' => '0 0 * * *',
        ]);

    $response->assertCreated()
        ->assertJsonPath('cronjob.name', 'Nightly backup')
        ->assertJsonPath('cronjob.username', 'deploy')
        ->assertJsonPath('cronjob.system_user.id', $this->su->id)
        ->assertJsonPath('cronjob.active', true);

    expect($response->json('cronjob.slug'))->toBe('nightly-backup');

    Process::assertRan(function ($process) {
        // filename is the stable name-slug (migration-safe, no id)
        return $process->command === ['tee', '/etc/cron.d/nightly-backup']
            && str_contains($process->input, '0 0 * * * deploy /home/deploy/backup.sh');
    });
});

it('creates a cron job for a default/unmanaged OS user by username', function () {
    Process::fake();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', [
            'name' => 'Cache clear',
            'username' => 'www-data',
            'command' => 'php /var/www/app/artisan cache:clear',
            'expression' => '*/5 * * * *',
        ]);

    $response->assertCreated()
        ->assertJsonPath('cronjob.username', 'www-data')
        ->assertJsonPath('cronjob.system_user', null);

    Process::assertRan(fn ($p) => $p->command[0] === 'tee'
        && str_contains($p->input, '*/5 * * * * www-data php /var/www/app/artisan cache:clear'));
});

it('rejects a duplicate cron job name', function () {
    Process::fake();
    Cronjob::create(['name' => 'Backup', 'slug' => 'backup', 'username' => 'deploy', 'command' => 'a', 'expression' => '* * * * *']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', ['name' => 'Backup', 'username' => 'deploy', 'command' => 'b', 'expression' => '* * * * *'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('generates a unique slug when two distinct names slug to the same value', function () {
    Process::fake();

    // distinct names (allowed) that both slugify to "backup"
    $first = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', ['name' => 'Backup', 'username' => 'deploy', 'command' => 'a', 'expression' => '* * * * *']);
    $second = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', ['name' => 'Backup!', 'username' => 'deploy', 'command' => 'b', 'expression' => '* * * * *']);

    expect($first->json('cronjob.slug'))->toBe('backup');
    expect($second->json('cronjob.slug'))->toBe('backup-2');
});

it('rejects a non-existent OS user', function () {
    Process::fake(fn ($process) => $process->command[0] === 'getent'
        ? Process::result(output: '', errorOutput: '', exitCode: 2)
        : Process::result(exitCode: 0));

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', [
            'name' => 'Bad user job',
            'username' => 'ghost',
            'command' => 'echo hi',
            'expression' => '* * * * *',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');

    expect(Cronjob::count())->toBe(0);
});

it('rejects an invalid cron expression', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', [
            'name' => 'Bad schedule',
            'username' => 'deploy',
            'command' => 'echo hi',
            'expression' => 'not a cron',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expression');
});

it('does not write a file when the job is created inactive', function () {
    Process::fake();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', [
            'name' => 'Disabled job',
            'username' => 'deploy',
            'command' => 'echo hi',
            'expression' => '* * * * *',
            'active' => false,
        ])->assertCreated();

    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('removes the cron.d file when a job is disabled via update', function () {
    Process::fake();
    $job = Cronjob::create([
        'name' => 'Job', 'slug' => 'job', 'username' => 'deploy', 'command' => 'echo hi',
        'expression' => '* * * * *', 'active' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/cronjobs/{$job->id}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('cronjob.active', false);

    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/cron.d/job']);
});

it('rewrites the cron.d file when the schedule changes', function () {
    Process::fake();
    $job = Cronjob::create([
        'name' => 'Job', 'slug' => 'job', 'username' => 'deploy', 'command' => 'echo hi',
        'expression' => '* * * * *', 'active' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/cronjobs/{$job->id}", ['expression' => '30 2 * * *'])
        ->assertOk()
        ->assertJsonPath('cronjob.expression', '30 2 * * *');

    Process::assertRan(fn ($p) => $p->command[0] === 'tee'
        && str_contains($p->input, '30 2 * * * deploy echo hi'));
});

it('deletes a cron job and removes its file', function () {
    Process::fake();
    $job = Cronjob::create([
        'name' => 'Job', 'slug' => 'job', 'username' => 'deploy', 'command' => 'echo hi',
        'expression' => '* * * * *', 'active' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/cronjobs/{$job->id}")
        ->assertNoContent();

    expect(Cronjob::find($job->id))->toBeNull();
    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/cron.d/job']);
});

it('relocates the cron.d file when the job is renamed', function () {
    Process::fake();
    $job = Cronjob::create([
        'name' => 'Old name', 'slug' => 'old-name', 'username' => 'deploy', 'command' => 'echo hi',
        'expression' => '* * * * *', 'active' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/cronjobs/{$job->id}", ['name' => 'New name'])
        ->assertOk()
        ->assertJsonPath('cronjob.name', 'New name')
        ->assertJsonPath('cronjob.slug', 'new-name');

    // old-slug file removed, new-slug file written
    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/cron.d/old-name']);
    Process::assertRan(fn ($p) => $p->command === ['tee', '/etc/cron.d/new-name']);
});

it('returns a translated error with reference and rolls back when the write fails', function () {
    Process::fake(fn ($process) => $process->command[0] === 'tee'
        ? Process::result(output: '', errorOutput: 'denied', exitCode: 1)
        : Process::result(exitCode: 0));

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/cronjobs', [
            'name' => 'Failing job',
            'username' => 'deploy',
            'command' => 'echo hi',
            'expression' => '* * * * *',
        ])
        ->assertStatus(500)
        ->assertJsonStructure(['message', 'reference']);

    expect(Cronjob::count())->toBe(0);
});

it('filters the list by system_user_id and active', function () {
    Process::fake();
    Cronjob::create(['name' => 'A', 'slug' => 'a', 'username' => 'deploy', 'system_user_id' => $this->su->id, 'command' => 'a', 'expression' => '* * * * *', 'active' => true]);
    Cronjob::create(['name' => 'B', 'slug' => 'b', 'username' => 'root', 'command' => 'b', 'expression' => '* * * * *', 'active' => false]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/cronjobs?filter[system_user_id]='.$this->su->id)
        ->assertOk()
        ->assertJsonCount(1, 'cronjobs')
        ->assertJsonPath('cronjobs.0.name', 'A');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/cronjobs?filter[active]=false')
        ->assertOk()
        ->assertJsonCount(1, 'cronjobs')
        ->assertJsonPath('cronjobs.0.name', 'B');
});

it('returns schedule presets for the dropdown', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/cronjobs/schedule-presets');

    $response->assertOk()
        ->assertJsonPath('presets.0.key', 'every_minute')
        ->assertJsonPath('presets.0.expression', '* * * * *');

    // custom is last and carries a null expression (frontend shows raw field)
    $presets = $response->json('presets');
    expect(end($presets))->toMatchArray(['key' => 'custom', 'expression' => null]);
    expect(collect($presets)->pluck('key'))->toContain('hourly', 'daily', 'weekly', 'monthly');
});

it('localizes preset labels', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/cronjobs/schedule-presets')
        ->assertJsonPath('presets.0.label', 'Every minute');

    $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept-Language' => 'es'])
        ->getJson('/api/cronjobs/schedule-presets')
        ->assertJsonPath('presets.0.label', 'Cada minuto');
});

it('denies a viewer without manage from creating a cron job', function () {
    $viewer = User::factory()->create();
    $perm = Permission::firstWhere('name', 'cronjob');
    $viewer->permissions()->attach($perm->id, ['view' => true, 'manage' => false]);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/cronjobs', [
            'name' => 'Nope', 'username' => 'deploy', 'command' => 'echo', 'expression' => '* * * * *',
        ])
        ->assertForbidden();
});

it('cascade-deletes cron jobs when their system user is deleted', function () {
    $job = Cronjob::create(['name' => 'A', 'slug' => 'a', 'username' => 'deploy', 'system_user_id' => $this->su->id, 'command' => 'a', 'expression' => '* * * * *']);

    $this->su->delete();

    expect(Cronjob::find($job->id))->toBeNull();
});
