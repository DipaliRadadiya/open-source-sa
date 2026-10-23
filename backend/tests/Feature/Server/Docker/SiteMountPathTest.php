<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Rules\ContainerMountPath;
use App\Services\Server\Applications\ContainerSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * `site_mount_path` (DS-01). Null is the `/app` container sites were first
 * created with, so a site with no recorded path must still render `/app`,
 * while a site that has one renders its own path. Asserted on the compose
 * file the site is deployed with, not on the column (DS-09).
 */

function mountedAt(Application $application): string
{
    $compose = app(ContainerSupervisor::class)->generated($application, '/home/legacy/legacy/public_html');

    preg_match('#- /home/legacy/legacy/public_html:(\S+)#', $compose, $match);

    return $match[1] ?? '';
}

it('keeps a site with no recorded mount path on /app, and one with a path on its own path', function () {
    $user = SystemUser::create(['username' => 'legacy', 'home_path' => '/home/legacy']);

    $legacy = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Legacy', 'slug' => 'legacy', 'domain' => 'legacy.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'neosmemo/memos:0.31.0', 'container_port' => 5230, 'app_port' => 3001,
        'status' => 'active', 'site_mount_path' => null,
    ]);

    expect($legacy->fresh()->site_mount_path)->toBeNull()
        ->and(mountedAt($legacy->fresh()))->toBe(ContainerMountPath::LEGACY_SITE_MOUNT);

    $new = $legacy->replicate();
    $new->forceFill(['name' => 'New', 'slug' => 'new', 'domain' => 'new.test', 'app_port' => 3002, 'site_mount_path' => ContainerMountPath::SITE_MOUNT])->save();

    expect(mountedAt($new))->toBe(ContainerMountPath::SITE_MOUNT);
});
