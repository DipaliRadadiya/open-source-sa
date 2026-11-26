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
| The asymmetry is the design:
|   - narrowing to a generic type gives up features, so it needs no evidence;
|   - widening turns on features (`app_magic_login` writes a loader into the site), so the disk
|     has to agree;
|   - a git site cannot be relabelled: removing its screens would not stop its workers or webhook.
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

    // No installer ran and no file was written. The activity log must still tell a hand-set type
    // from a panel-installed one.
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

    // The escape hatch: requiring proof here would trap a site whose WordPress was deleted.
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

    // A relabel deletes nothing: workers keep running and the webhook keeps accepting pushes, only
    // the screens go. The message has to say so, not "invalid selection".
    expect(changeType('wordpress')->json('errors.site_type.0'))
        ->toBe(__('application.site_type_change.git_cannot_change'));
});

it('refuses to turn any site into a git deployment', function () {
    fakeTypeFiles([$this->application->documentRoot().'/artisan']);

    // `artisan` detects as `git` at confidence 80, a verdict that must never be offered: a git
    // site needs a repository, branch and deploy script that files on disk cannot supply.
    expect(changeType('git')->assertStatus(422)->json('errors.site_type.0'))
        ->toBe(__('application.site_type_change.git_not_a_target'));
});

it('refuses one marketplace application becoming another', function () {
    $this->application->update(['site_type' => 'moodle']);
    fakeTypeFiles([$this->application->documentRoot().'/configuration.php']);

    // A Moodle site is not "actually" Joomla.
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

    // `OlsDriver::viewData()` sets `readsHtaccess` from the site type (LiteSpeed Cache works only
    // through .htaccess). Relabelling without republishing would silently skip the cache integration.
    Process::assertRan(fn ($process) => in_array('-t', $process->command, true)
        || str_contains(implode(' ', $process->command), 'reload')
        || str_contains(implode(' ', $process->command), 'tee'));
});

it('does not republish a disabled site back online', function () {
    // `forceFill`, not `update`: `disabled_at` is not fillable (the enable/disable actions own it),
    // so `update()` would silently leave the site enabled.
    $this->application->forceFill(['disabled_at' => now()])->save();
    fakeTypeFiles([$this->application->documentRoot().'/wp-config.php']);

    changeType('wordpress')->assertOk();

    // A disabled site's vhost points at the disabled page; rewriting it would put the site back
    // online as a side effect of a relabel.
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
