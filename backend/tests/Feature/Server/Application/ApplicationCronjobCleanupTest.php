<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Cronjob;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationArtifacts;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Deleting a site keeps the cron jobs the user added, and removes the one the
 * panel created for it — Nextcloud's and Moodle's background job, which runs
 * the site's own cron.php and fails every tick once the site is gone.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->su = SystemUser::create(['username' => 'ncuser', 'home_path' => '/home/ncuser', 'shell' => '/bin/bash', 'sudo' => false]);

    $this->site = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Files', 'slug' => 'files', 'domain' => 'cloud.example.com',
        'site_type' => 'nextcloud', 'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/',
        'status' => 'active',
    ]);

    $this->logDir = rtrim((string) config('server.cronjob_log_dir'), '/');

    $this->job = fn (array $attributes) => Cronjob::create(array_merge([
        'username' => 'ncuser', 'system_user_id' => $this->su->id, 'application_id' => $this->site->id,
        'expression' => '*/5 * * * *',
    ], $attributes));
});

it('removes the panel\'s own job for the site: file, log and row', function () {
    Process::fake();
    $owned = ($this->job)(['name' => 'Files background jobs #'.$this->site->id, 'slug' => 'files-bg',
        'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php', 'application_owned' => true]);

    app(ApplicationArtifacts::class)->remove($this->site);

    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', '/etc/cron.d/files-bg']);
    Process::assertRan(fn ($p) => $p->command === ['rm', '-f', "{$this->logDir}/files-bg.log"]);
    expect(Cronjob::find($owned->id))->toBeNull()
        ->and(ActivityLog::where('type', 'cronjob')->where('action', 'deleted')->exists())->toBeTrue();
});

it('keeps a job the user added to the same site, even one running cron.php', function () {
    // Not marked as the panel's, so the panel cannot be sure it may go.
    Process::fake();
    $mine = ($this->job)(['name' => 'My sync', 'slug' => 'my-sync',
        'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php']);

    app(ApplicationArtifacts::class)->remove($this->site);

    Process::assertNotRan(fn ($p) => in_array('/etc/cron.d/my-sync', $p->command, true));
    expect(Cronjob::find($mine->id))->not->toBeNull();
});

it('leaves another site\'s own job alone', function () {
    Process::fake();
    $other = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'Other', 'slug' => 'other', 'domain' => 'other.example.com',
        'site_type' => 'nextcloud', 'serving_profile' => 'php', 'php_version' => '8.4', 'web_root' => '/',
        'status' => 'active',
    ]);
    $theirs = ($this->job)(['name' => 'Other background jobs #'.$other->id, 'slug' => 'other-bg',
        'application_id' => $other->id, 'command' => '/usr/bin/php8.4 -f /home/ncuser/other/public_html/cron.php',
        'application_owned' => true]);

    app(ApplicationArtifacts::class)->remove($this->site);

    expect(Cronjob::find($theirs->id))->not->toBeNull();
});

it('does it through the delete endpoint, before the record goes', function () {
    Process::fake();
    $owned = ($this->job)(['name' => 'Files background jobs #'.$this->site->id, 'slug' => 'files-bg',
        'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php', 'application_owned' => true]);

    $this->actingAs($this->admin)->deleteJson("/api/applications/{$this->site->id}")->assertSuccessful();

    expect(Cronjob::find($owned->id))->toBeNull();
});

it('hands the job to the user once they edit its command', function () {
    Process::fake();
    $owned = ($this->job)(['name' => 'Files background jobs #'.$this->site->id, 'slug' => 'files-bg',
        'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php', 'application_owned' => true]);

    $this->actingAs($this->admin)->putJson("/api/cronjobs/{$owned->id}", [
        'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php --verbose',
    ])->assertOk();

    expect($owned->fresh()->application_owned)->toBeFalse();
});

it('cannot be marked as the panel\'s through the API', function () {
    Process::fake();

    $this->actingAs($this->admin)->postJson('/api/cronjobs', [
        'name' => 'Sneaky', 'system_user_id' => $this->su->id, 'application_id' => $this->site->id,
        'command' => 'echo hi', 'expression' => '* * * * *', 'application_owned' => true,
    ])->assertCreated();

    expect(Cronjob::where('name', 'Sneaky')->sole()->application_owned)->toBeFalse();
});

describe('marking existing jobs', function () {
    function runOwnedMigration(): void
    {
        $migration = require database_path('migrations/2026_09_30_080000_add_application_owned_to_cronjobs_table.php');
        $migration->down();
        $migration->up();
    }

    it('marks only a job that is exactly the installer\'s', function () {
        $id = $this->site->id;
        $cases = [
            'installer' => ["Files background jobs #{$id}", '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php', true],
            'renamed' => ['Nightly', '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php', false],
            'edited command' => ["B background jobs #{$id}", '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php --x', false],
            'other script' => ["C background jobs #{$id}", '/usr/bin/php8.4 -f /home/ncuser/files/public_html/occ', false],
            'outside the site' => ["D background jobs #{$id}", '/usr/bin/php8.4 -f /home/ncuser/elsewhere/cron.php', false],
            'another site\'s id' => ['Files background jobs #999', '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php', false],
            'relative binary' => ["E background jobs #{$id}", 'php -f /home/ncuser/files/public_html/cron.php', false],
        ];

        $ids = [];
        foreach ($cases as $label => [$name, $command]) {
            $ids[$label] = ($this->job)(['name' => $name, 'slug' => Cronjob::uniqueSlug($name), 'command' => $command])->id;
        }

        runOwnedMigration();

        foreach ($cases as $label => [, , $expected]) {
            expect((bool) DB::table('cronjobs')->where('id', $ids[$label])->value('application_owned'))
                ->toBe($expected, $label);
        }
    });

    it('marks Moodle\'s own script, and not Nextcloud\'s on a Moodle site', function () {
        $this->site->forceFill(['site_type' => 'moodle'])->save();
        $id = $this->site->id;
        $moodle = ($this->job)(['name' => "Files background jobs #{$id}", 'slug' => 'm1',
            'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/admin/cli/cron.php'])->id;
        $wrong = ($this->job)(['name' => "B background jobs #{$id}", 'slug' => 'm2',
            'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php'])->id;

        runOwnedMigration();

        expect((bool) DB::table('cronjobs')->where('id', $moodle)->value('application_owned'))->toBeTrue()
            ->and((bool) DB::table('cronjobs')->where('id', $wrong)->value('application_owned'))->toBeFalse();
    });

    it('marks nothing on a site type that has no installer job', function () {
        $this->site->forceFill(['site_type' => 'wordpress'])->save();
        $id = $this->site->id;
        $job = ($this->job)(['name' => "Files background jobs #{$id}", 'slug' => 'w1',
            'command' => '/usr/bin/php8.4 -f /home/ncuser/files/public_html/cron.php'])->id;

        runOwnedMigration();

        expect((bool) DB::table('cronjobs')->where('id', $job)->value('application_owned'))->toBeFalse();
    });
});
