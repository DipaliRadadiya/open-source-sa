<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
| Which site-type changes are allowed, and which are refused with a reason.
|
| The asymmetry is the design, not an oversight:
|
|   - narrowing to a generic type gives up features and claims nothing, so it
|     never needs evidence;
|   - widening turns on features — `app_magic_login` *writes a loader into the
|     site* — so the disk has to agree;
|   - a git site cannot be relabelled at all, because its Deployments, Workers
|     and .env screens come from `method() === 'git'` and removing them does
|     not stop the workers running or the webhook accepting pushes.
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-retype-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'retypeuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'App',
        'slug' => 'app',
        'domain' => 'app.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.2',
        'web_root' => '/',
        'status' => 'active',
    ]);

    Process::fake(fn () => Process::result(exitCode: 0));
});

/** Answer `test -f` with success for exactly these absolute paths. */
function fakeTypeFiles(array $present): void
{
    Process::fake(function ($process) use ($present) {
        $command = $process->command;

        if (($command[0] ?? null) === 'test' && ($command[1] ?? null) === '-f') {
            return Process::result(exitCode: in_array($command[2] ?? '', $present, true) ? 0 : 1);
        }

        return Process::result(exitCode: 0);
    });
}

function changeType(string $target): TestResponse
{
    return test()->actingAs(test()->admin)
        ->putJson('/api/applications/'.test()->application->id.'/site-type', ['site_type' => $target]);
}

it('relabels a custom php site that really does hold WordPress', function () {
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    changeType('wordpress')->assertOk()
        ->assertJsonPath('application.site_type', 'wordpress');

    expect($this->application->fresh()->site_type)->toBe('wordpress');
});

it('records that the type was relabelled rather than installed', function () {
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    changeType('wordpress')->assertOk();

    $detection = $this->application->fresh()->settings['type_detection'];

    // No installer ran and no file was written. A site type somebody set by
    // hand and one the panel installed are indistinguishable from the column
    // alone, and that difference is the first thing worth knowing when a site
    // misbehaves.
    expect($detection['relabelled'])->toBeTrue()
        ->and($detection['changed_from'])->toBe('php')
        ->and($detection['matched'])->toBe('wp-config.php');
});

it('refuses to widen when nothing on disk agrees', function () {
    fakeTypeFiles([]);

    changeType('wordpress')
        ->assertStatus(422)
        ->assertJsonValidationErrors('site_type');

    expect($this->application->fresh()->site_type)->toBe('php');
});

it('always allows narrowing back to custom php, with no evidence at all', function () {
    $this->application->update(['site_type' => 'wordpress']);
    fakeTypeFiles([]);

    // The escape hatch. Requiring proof in this direction would trap a site
    // whose WordPress has since been deleted.
    changeType('php')->assertOk();

    expect($this->application->fresh()->site_type)->toBe('php');
});

it('refuses to change a git site, even when it really is WordPress', function () {
    $this->application->update([
        'site_type' => 'git', 'repository' => 'acme/app', 'branch' => 'main',
    ]);
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    changeType('wordpress')
        ->assertStatus(422)
        ->assertJsonValidationErrors('site_type');

    expect($this->application->fresh()->site_type)->toBe('git');
});

it('explains the git refusal in terms of what would be orphaned', function () {
    $this->application->update(['site_type' => 'git', 'repository' => 'acme/app', 'branch' => 'main']);
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    // Nothing is deleted by a relabel — the workers keep running and the
    // webhook keeps accepting pushes. Only the screens go. That is the fact
    // the message has to carry, because "invalid selection" would send the
    // user looking for a different type instead of a different approach.
    expect(changeType('wordpress')->json('errors.site_type.0'))
        ->toBe(__('application.site_type_change.git_cannot_change'));
});

it('refuses to turn any site into a git deployment', function () {
    fakeTypeFiles([$this->application->documentRoot().'/artisan']);

    // `artisan` detects as `git` at confidence 80 — the detector's own output
    // includes a verdict that must never be offered, because a git site needs
    // a repository, a branch and a deploy script that cannot be conjured from
    // files already on disk.
    expect(changeType('git')->assertStatus(422)->json('errors.site_type.0'))
        ->toBe(__('application.site_type_change.git_not_a_target'));
});

it('refuses one marketplace application becoming another', function () {
    $this->application->update(['site_type' => 'moodle']);
    fakeTypeFiles([$this->application->documentRoot().'/configuration.php']);

    // A Moodle site is not "actually" Joomla. Whoever is asking has a
    // different problem.
    expect(changeType('joomla')->assertStatus(422)->json('errors.site_type.0'))
        ->toBe(__('application.site_type_change.only_from_generic'));
});

it('refuses a type the panel cannot recognise on disk', function () {
    fakeTypeFiles([]);

    expect(changeType('nextcloud')->assertStatus(422)->json('errors.site_type.0'))
        ->toBe(__('application.site_type_change.not_suggestable'));
});

it('refuses a no-op rather than reporting a change that did not happen', function () {
    fakeTypeFiles([]);

    expect(changeType('php')->assertStatus(422)->json('errors.site_type.0'))
        ->toBe(__('application.site_type_change.unchanged'));
});

it('rejects a site type that does not exist', function () {
    fakeTypeFiles([]);

    // An unknown string is a malformed request, not a refused transition.
    changeType('not-a-real-type')
        ->assertStatus(422)
        ->assertJsonValidationErrors('site_type');
});

it('republishes the vhost, because OpenLiteSpeed renders the type into it', function () {
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    changeType('wordpress')->assertOk();

    // `OlsDriver::viewData()` sets `readsHtaccess` from the site type, because
    // LiteSpeed Cache talks to the cache module through .htaccess and nothing
    // else. Relabel without republishing and the user gets the WordPress
    // screens while the cache integration silently never happens.
    Process::assertRan(fn ($process) => in_array('-t', $process->command, true)
        || str_contains(implode(' ', $process->command), 'reload')
        || str_contains(implode(' ', $process->command), 'tee'));
});

it('does not republish a disabled site back online', function () {
    // `forceFill`, not `update`: `disabled_at` is deliberately not fillable
    // (the enable/disable actions own it), so `update()` here silently does
    // nothing and the test would assert against a site that was never
    // disabled — passing or failing for a reason unrelated to the guard.
    $this->application->forceFill(['disabled_at' => now()])->save();
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    changeType('wordpress')->assertOk();

    // A disabled site's vhost deliberately points at the disabled page.
    // Rewriting it here would put the site back online as a side effect of a
    // relabel.
    Process::assertNotRan(fn ($process) => str_contains(implode(' ', $process->command), 'reload'));
});

it('logs the change with both types', function () {
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    changeType('wordpress')->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'type' => 'application',
        'action' => 'site_type_changed',
    ]);
});

it('refuses the change to a user without manage permission', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'application');

    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    $this->actingAs($viewer)
        ->putJson("/api/applications/{$this->application->id}/site-type", ['site_type' => 'wordpress'])
        ->assertForbidden();

    expect($this->application->fresh()->site_type)->toBe('php');
});
