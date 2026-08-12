<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Panel\UpdateScript;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * A vhost is a rendered file, so the AI bot list, the 8G ruleset and the
 * templates all ship inside the panel and reach an existing site only when
 * its config is written again.
 *
 * Before this, nothing wrote it again: the panel would report the new bot
 * list while every site still enforced the old one, and neither side could
 * detect the difference. Protection the user believes is on, silently a
 * version behind, is a security control that has stopped being one.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    ServerCapability::create([
        'stack' => 'lemp',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

/**
 * `ai_bot_policy` and `disabled_at` are deliberately not mass-assignable —
 * they are set by the features that own them, not by whoever posts a form —
 * so they are forced on rather than passed to `create()`.
 */
function makeSite(string $domain, array $forced = []): Application
{
    $site = Application::create([
        'system_user_id' => test()->systemUser->id,
        'name' => $domain,
        'domain' => $domain,
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    $site->forceFill(array_merge(['ai_bot_policy' => 'block_training'], $forced))->save();

    return $site;
}

/**
 * @param  string  $onDisk  what `cat` returns for an existing config
 */
function fakeResyncServer(string $onDisk = 'stale config', bool $testPasses = true): ArrayObject
{
    $written = new ArrayObject;

    Process::fake(function ($process) use ($onDisk, $testPasses, $written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'cat') {
            return Process::result(output: $onDisk);
        }

        if (($args[0] ?? '') === 'tee') {
            $written->append((string) ($process->input ?? ''));
        }

        if (($args[0] ?? '') === 'nginx' && ($args[1] ?? '') === '-t') {
            return Process::result(exitCode: $testPasses ? 0 : 1, errorOutput: $testPasses ? '' : 'invalid');
        }

        return Process::result(exitCode: 0);
    });

    return $written;
}

it('re-renders a stale site config and reloads once', function () {
    makeSite('one.test');
    makeSite('two.test');

    $written = fakeResyncServer();

    $result = app(SiteConfigResyncer::class)->run();

    expect($result['total'])->toBe(2)
        ->and($result['updated'])->toBe(2)
        ->and($result['failed'])->toBe([])
        ->and($result['reloaded'])->toBeTrue()
        // The point of the whole exercise: the current bot list is now in the
        // file the web server actually reads.
        ->and((string) $written[0])->toContain('GPTBot');

    // One reload for the batch, not one per site — each was already proved
    // safe on its own.
    $reloads = 0;
    Process::assertRan(function ($p) use (&$reloads) {
        $args = $p->command[0] === 'sudo' ? array_slice($p->command, 2) : $p->command;

        if (($args[0] ?? '') === 'systemctl' && ($args[1] ?? '') === 'reload') {
            $reloads++;
        }

        return true;
    });
    expect($reloads)->toBeLessThanOrEqual(1);
});

it('skips a site whose config is already current', function () {
    $site = makeSite('one.test');

    // Feed back exactly what the renderer produces, so nothing has drifted.
    // Rendered against the provisioner's own document root, not a
    // hand-written one: the resyncer uses that, so a literal here was
    // comparing the shipped config against a config for a different path and
    // could only ever report drift.
    $current = app(WebServerManager::class)
        ->driver()
        ->renderConfig(
            $site->load('systemUser'),
            app(ApplicationProvisioner::class)
                ->documentRoot($site->load('systemUser')),
        );

    fakeResyncServer(onDisk: $current);

    $result = app(SiteConfigResyncer::class)->run();

    expect($result['unchanged'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        // Nothing changed, so nothing is reloaded — a routine update must not
        // bounce every site's web server for no reason.
        ->and($result['reloaded'])->toBeFalse();
});

it('rolls back a site that fails its config test and keeps going', function () {
    makeSite('one.test');
    makeSite('two.test');

    $written = fakeResyncServer(testPasses: false);

    $result = app(SiteConfigResyncer::class)->run();

    // Both attempted, both restored, run completed — one bad site must not
    // leave the rest un-updated.
    expect($result['failed'])->toHaveCount(2)
        ->and($result['updated'])->toBe(0)
        ->and($result['reloaded'])->toBeFalse();

    // The previous bytes went back, not a re-render of the same broken thing.
    expect(trim((string) $written[count($written) - 1]))->toBe('stale config');
});

it('leaves a disabled site alone', function () {
    makeSite('one.test', ['disabled_at' => now()]);

    fakeResyncServer();

    $result = app(SiteConfigResyncer::class)->run();

    // A disabled site's vhost points at the disabled page on purpose.
    // Re-rendering the real one would put it back online as a side effect of
    // a panel update.
    expect($result['total'])->toBe(0);
});

it('leaves a site that was never provisioned alone', function () {
    makeSite('one.test', ['status' => 'pending']);

    fakeResyncServer();

    expect(app(SiteConfigResyncer::class)->run()['total'])->toBe(0);
});

it('runs as an artisan command without failing on a bad site', function () {
    makeSite('one.test');

    fakeResyncServer(testPasses: false);

    // Exits 0 on purpose: the site was rolled back and is still serving, and
    // failing here would abort an otherwise-good panel update over it.
    $this->artisan('sites:resync')->assertSuccessful();
});

it('is wired into the panel update script', function () {
    expect(UpdateScript::STEPS)->toContain('resync_site_configs');

    // Ordered after migrations: a config rendered against a schema the
    // database has not reached yet is a worse problem than a stale one.
    $steps = array_flip(UpdateScript::STEPS);
    expect($steps['resync_site_configs'])->toBeGreaterThan($steps['migrate']);
});
