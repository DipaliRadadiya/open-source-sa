<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Rules\ContainerMountPath;
use App\Services\Server\Applications\ContainerSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * `site_mount_path` (DS-01). Null is the `/app` every existing container site
 * was created with, so the column is added empty and nothing is backfilled —
 * which only holds if a site that predates the column still renders `/app`
 * after it, while a site made since renders its own path. Asserted on the
 * compose file the site is deployed with, not on the column (DS-09).
 */

function mountMigration(): object
{
    return require database_path('migrations/2026_10_07_170000_add_site_mount_path_to_applications.php');
}

function mountedAt(Application $application): string
{
    $compose = app(ContainerSupervisor::class)->generated($application, '/home/legacy/legacy/public_html');

    preg_match('#- /home/legacy/legacy/public_html:(\S+)#', $compose, $match);

    return $match[1] ?? '';
}

it('keeps a site from before the column on /app, and a new one on its own path', function () {
    $migration = mountMigration();
    $user = SystemUser::create(['username' => 'legacy', 'home_path' => '/home/legacy']);

    // A container site as it existed before the column.
    $migration->down();
    expect(Schema::hasColumn('applications', 'site_mount_path'))->toBeFalse();

    $legacyId = DB::table('applications')->insertGetId([
        'system_user_id' => $user->id, 'name' => 'Legacy', 'slug' => 'legacy', 'domain' => 'legacy.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'neosmemo/memos:0.31.0', 'container_port' => 5230, 'app_port' => 3001,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    $legacy = Application::findOrFail($legacyId);

    // Not backfilled, and still deployed exactly where its files have been.
    expect($legacy->site_mount_path)->toBeNull()
        ->and(mountedAt($legacy))->toBe(ContainerMountPath::LEGACY_SITE_MOUNT);

    $new = $legacy->replicate();
    $new->forceFill(['name' => 'New', 'slug' => 'new', 'domain' => 'new.test', 'app_port' => 3002, 'site_mount_path' => ContainerMountPath::SITE_MOUNT])->save();

    expect(mountedAt($new))->toBe(ContainerMountPath::SITE_MOUNT);
});
