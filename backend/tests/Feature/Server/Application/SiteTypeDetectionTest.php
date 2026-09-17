<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeDetector;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
| Reading what is actually installed in a site's directory.
|
| The feature this backs: a user creates a Custom PHP site, installs WordPress
| into it by hand, and the panel goes on calling it Custom PHP — withholding
| Staging, Clone and Magic Login from a site that could use all three. Detect
| reads the disk and offers the correction.
|
| The detector is the one extracted from `ApplicationDiscoverer`, so these also
| pin behaviour brownfield sync has always depended on.
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-sitetype-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'typeuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
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
});

/**
 * Answer `test -f` with success for exactly the given absolute paths.
 *
 * Everything else exits 1, which is the real shape of this probe: on any given
 * site five of the six signatures are absent.
 */
function fakeFiles(array $present): void
{
    Process::fake(function ($process) use ($present) {
        $command = $process->command;

        if (($command[0] ?? null) === 'test' && ($command[1] ?? null) === '-f') {
            return Process::result(exitCode: in_array($command[2] ?? '', $present, true) ? 0 : 1);
        }

        return Process::result(exitCode: 0);
    });
}

it('recognises WordPress by its config file', function () {
    $root = $this->application->documentRoot();
    fakeFiles([$root.'/wp-config.php']);

    $verdict = app(SiteTypeDetector::class)->detect($this->application);

    expect($verdict->siteType)->toBe('wordpress')
        ->and($verdict->confidence)->toBe(95)
        ->and($verdict->matched)->toBe('wp-config.php');
});

it('reports nothing recognisable as php at low confidence', function () {
    // Not "static": serving a PHP application as a directory of files
    // publishes its source, and the reverse mistake only costs a redundant
    // handler. Inherited from the sync discoverer, deliberately unchanged.
    fakeFiles([]);

    $verdict = app(SiteTypeDetector::class)->detect($this->application);

    expect($verdict->siteType)->toBe('php')
        ->and($verdict->confidence)->toBe(10)
        ->and($verdict->matched)->toBeNull();
});

it('prefers the more specific signature over the generic one', function () {
    $root = $this->application->documentRoot();
    fakeFiles([$root.'/wp-config.php', $root.'/index.php']);

    // A WordPress install has PHP files everywhere; `index.php` matches on
    // every PHP site in existence. The distinguishing file has to win.
    expect(app(SiteTypeDetector::class)->detect($this->application)->siteType)->toBe('wordpress');
});

it('finds wp-config.php one level above the web root', function () {
    // Moving wp-config.php out of the web root is long-standing WordPress
    // hardening advice. A detector that only looked at the document root
    // would fail on exactly the sites whose owners know what they are doing.
    $this->application->update(['web_root' => '/public']);

    $documentRoot = $this->application->fresh()->documentRoot();
    fakeFiles([dirname($documentRoot).'/wp-config.php']);

    $verdict = app(SiteTypeDetector::class)->detect($this->application->fresh());

    expect($verdict->siteType)->toBe('wordpress')
        ->and($verdict->root)->toBe(dirname($documentRoot));
});

it('probes rather than runs, so a missing file is not a failed operation', function () {
    fakeFiles([]);

    app(SiteTypeDetector::class)->detect($this->application);

    // `probe()` passes expectedExitCodes: [1]. Without it every absent
    // signature — five of six on any real site — is logged as a failed
    // operation, which is what made the server-ops log unreadable for the
    // one case that was real.
    Process::assertRan(fn ($process) => ($process->command[0] ?? null) === 'test');
});

it('offers a suggestion for a custom php site that now holds WordPress', function () {
    fakeFiles([$this->application->documentRoot().'/wp-config.php']);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/detect-type")
        ->assertOk();

    expect($response->json('site_type_detection.suggested'))->toBe('wordpress')
        ->and($response->json('site_type_detection.matched'))->toBe('wp-config.php')
        ->and($response->json('site_type_detection.confidence'))->toBe(95);
});

it('suggests nothing for the signature every php site matches', function () {
    // `index.php` matches at 40 on every PHP site in existence, and resolves
    // to `php` — which is what this site already says it is. Suppressed
    // because it is not a change, not because of the confidence floor; the
    // floor is exercised separately below, since a test that passes for a
    // reason other than the one in its name guards nothing.
    fakeFiles([$this->application->documentRoot().'/index.php']);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/detect-type")
        ->assertOk();

    expect($response->json('site_type_detection.suggested'))->toBeNull()
        ->and($response->json('site_type_detection.detected'))->toBe('php');
});

it('suggests nothing when confidence is below the floor', function () {
    // No signature in the table currently lands a *suggestable* type under 60
    // — WordPress is 95 and Joomla is exactly 60 — so the floor is raised here
    // rather than found a case for. That is the point of testing it: the floor
    // exists for the signature table's next edit, and this fails the moment
    // somebody removes it.
    config(['server.site_type_detection.min_confidence' => 96]);

    fakeFiles([$this->application->documentRoot().'/wp-config.php']);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/detect-type")
        ->assertOk();

    expect($response->json('site_type_detection.suggested'))->toBeNull()
        // Still detected and still recorded. The floor decides whether the
        // panel says anything, not whether it looked.
        ->and($response->json('site_type_detection.detected'))->toBe('wordpress');
});

it('never offers git, however confidently it is detected', function () {
    // 🔴 The detector resolves `artisan` to `git` at 80 — over the floor, on a
    // generic site, a different type from the current one. Every condition for
    // a suggestion is met, and offering it would tell the user to change their
    // site to `git`: the one transition that cannot work, because `git` means a
    // repository, branch, deploy script and webhook the panel owns, none of
    // which exist on disk. The allowlist is what stops it.
    fakeFiles([$this->application->documentRoot().'/artisan']);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/detect-type")
        ->assertOk();

    expect($response->json('site_type_detection.detected'))->toBe('git')
        ->and($response->json('site_type_detection.confidence'))->toBe(80)
        ->and($response->json('site_type_detection.suggested'))->toBeNull();
});

it('suggests nothing for a git site, and does not even look', function () {
    $this->application->update(['site_type' => 'git', 'repository' => 'acme/app', 'branch' => 'main']);
    fakeFiles([$this->application->documentRoot().'/wp-config.php']);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->fresh()->id}/detect-type")
        ->assertOk();

    // A git-deployed WordPress repository matches at 95. Suggesting the change
    // would invite the user to break their own pipeline.
    expect($response->json('site_type_detection.suggested'))->toBeNull();
    expect($response->json('site_type_detection.detected'))->toBeNull();

    // And the probe never ran. Not a detail: a stored verdict of "wordpress,
    // confidence 95" against a git site is a finding the next person reads as
    // an offer, whether or not this screen renders it as one.
    Process::assertNothingRan();
    expect($this->application->fresh()->settings['type_detection'] ?? null)->toBeNull();
});

it('stores the verdict so the application payload can carry it', function () {
    fakeFiles([$this->application->documentRoot().'/wp-config.php']);

    $this->actingAs($this->admin)
        ->postJson("/api/applications/{$this->application->id}/detect-type")
        ->assertOk();

    $this->actingAs($this->admin)
        ->getJson("/api/applications/{$this->application->id}")
        ->assertOk()
        ->assertJsonPath('application.site_type_detection.suggested', 'wordpress')
        ->assertJsonPath('application.site_type_detection.detected', 'wordpress');
});

it('refuses detection to a user without manage permission', function () {
    $viewer = User::factory()->create();
    grantPermission($viewer, 'application');

    fakeFiles([]);

    $this->actingAs($viewer)
        ->postJson("/api/applications/{$this->application->id}/detect-type")
        ->assertForbidden();
});
