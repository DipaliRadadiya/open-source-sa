<?php

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\DiskCleaner\Targets\DockerImagesTarget;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * 2026-10-29 (Dipali): a deleted Docker app left its images behind for good —
 * 58 GB on one server. Deleting a Docker site now removes the images only it
 * used, and the Disk Cleaner can remove every image no container uses.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);
    $user = SystemUser::create(['username' => 'imgsite', 'home_path' => '/home/imgsite']);
    $this->site = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Img', 'slug' => 'imgsite', 'domain' => 'img.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'ghost:5', 'container_port' => 2368, 'app_port' => 20011, 'status' => 'active',
    ]);
});

/**
 * Docker as seen by the panel: this site's two containers (one image shared
 * with another site's container), and every command that ran.
 */
function fakeDockerWithImages(Application $site, array &$ran): void
{
    $own = 'com.docker.compose.project=sv-app-'.$site->id;
    $lines = implode("\n", [
        json_encode(['Names' => 'sv-app-'.$site->id.'-app-1', 'Image' => 'ghost:5', 'Labels' => $own.',com.docker.compose.service=app']),
        json_encode(['Names' => 'sv-app-'.$site->id.'-db-1', 'Image' => 'mysql:8.0', 'Labels' => $own.',com.docker.compose.service=db']),
        json_encode(['Names' => 'sv-app-999-db-1', 'Image' => 'mysql:8.0', 'Labels' => 'com.docker.compose.project=sv-app-999']),
    ]);

    Process::fake(function ($process) use (&$ran, $lines) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }
        $ran[] = implode(' ', $args);

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'ps' && in_array('{{json .}}', $args, true)) {
            return Process::result(output: $lines."\n");
        }

        return Process::result(exitCode: 0);
    });
}

describe('deleting a Docker site', function () {
    it('removes the images only it used, after its containers are gone', function () {
        $ran = [];
        fakeDockerWithImages($this->site, $ran);

        $this->actingAs($this->admin)->deleteJson('/api/applications/'.$this->site->id)->assertSuccessful();

        $removed = collect($ran)->filter(fn (string $c) => str_starts_with($c, 'docker image rm'))->values();
        $down = collect($ran)->search(fn (string $c) => str_contains($c, ' down'));

        expect($removed->all())->toBe(['docker image rm -- ghost:5'])
            // The other site's database image stays.
            ->and(collect($ran)->contains('docker image rm -- mysql:8.0'))->toBeFalse()
            ->and($down)->not->toBeFalse()
            ->and(collect($ran)->search('docker image rm -- ghost:5'))->toBeGreaterThan($down);

        // The activity line says what went, not "network and volumes".
        $row = ActivityLog::where('action', 'docker_resources_removed')->latest('id')->first();
        expect(ActivityLogResource::make($row)->resolve()['description'])
            ->toContain('image:ghost:5')->not->toContain('volumes');
    });

    it('says in the activity line which images it kept, and why', function () {
        $ran = [];
        fakeDockerWithImages($this->site, $ran);

        $this->actingAs($this->admin)->deleteJson('/api/applications/'.$this->site->id)->assertSuccessful();

        $row = ActivityLog::where('action', 'docker_resources_removed')->latest('id')->first();
        expect(ActivityLogResource::make($row)->resolve()['description'])
            ->toContain('image:mysql:8.0:used-by-sv-app-999-db-1');
    });

    it('keeps them when the user turns it off', function () {
        $ran = [];
        fakeDockerWithImages($this->site, $ran);

        $this->actingAs($this->admin)->deleteJson('/api/applications/'.$this->site->id, ['remove_docker_images' => false])->assertSuccessful();

        expect(collect($ran)->filter(fn (string $c) => str_starts_with($c, 'docker image rm'))->all())->toBe([]);
    });

    it('deletes the site as before, keeping images, for someone who may not manage Docker', function () {
        $user = User::factory()->create();
        grantPermission($user, 'application', view: true, manage: true);
        $ran = [];
        fakeDockerWithImages($this->site, $ran);

        $this->actingAs($user)->deleteJson('/api/applications/'.$this->site->id)->assertSuccessful();

        expect(collect($ran)->filter(fn (string $c) => str_starts_with($c, 'docker image rm'))->all())->toBe([])
            ->and(Application::find($this->site->id))->toBeNull();
    });

    it('refuses an explicit request from someone who may not manage Docker, and deletes nothing', function () {
        $user = User::factory()->create();
        grantPermission($user, 'application', view: true, manage: true);
        $ran = [];
        fakeDockerWithImages($this->site, $ran);

        $this->actingAs($user)->deleteJson('/api/applications/'.$this->site->id, ['remove_docker_images' => true])->assertForbidden();

        expect(Application::find($this->site->id))->not->toBeNull();
    });
});

describe('the Disk Cleaner', function () {
    it('prices what docker says it can reclaim from images and the build cache', function () {
        Process::fake(fn ($process) => in_array('df', (array) $process->command, true)
            ? Process::result(output: implode("\n", [
                json_encode(['Type' => 'Images', 'Reclaimable' => '1.5GB (60%)']),
                json_encode(['Type' => 'Containers', 'Reclaimable' => '9GB (90%)']),
                json_encode(['Type' => 'Local Volumes', 'Reclaimable' => '20GB (100%)']),
                json_encode(['Type' => 'Build Cache', 'Reclaimable' => '512.5MB']),
            ])."\n")
            : Process::result());

        // Images and build cache only: containers and volumes are not this
        // category's, and volumes are the apps' data.
        expect(app(DockerImagesTarget::class)->estimate())->toBe(2_012_500_000);
    });

    it('prunes unused images and the build cache, and never volumes or containers', function () {
        $ran = [];
        Process::fake(function ($process) use (&$ran) {
            $ran[] = implode(' ', array_values(array_filter((array) $process->command, fn ($a) => ! in_array($a, ['sudo', '-n'], true))));

            return Process::result();
        });

        expect(app(DockerImagesTarget::class)->clean()->ok)->toBeTrue()
            ->and($ran)->toBe(['docker image prune --all --force', 'docker builder prune --all --force']);
    });

    it('is not offered on a server without Docker, and has no keep choice', function () {
        $target = app(DockerImagesTarget::class);

        expect($target->available())->toBe(is_file('/usr/bin/docker'))
            ->and($target->keepsDays())->toBeFalse();
    });
});
