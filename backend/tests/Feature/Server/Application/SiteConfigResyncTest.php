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

        // An existing server: the web server is already in every site user's
        // group, so the home grant has nothing to add and "already current"
        // means exactly that. (A server where it is not is the case the grant
        // exists for, and has its own tests.)
        if (($args[0] ?? '') === 'id') {
            return Process::result(output: 'www-data '.SystemUser::query()->pluck('username')->implode(' '));
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

it('reconciles the canonical URL of an existing installed site', function () {
    $site = makeSite('one.test');
    $site->update(['site_type' => 'wordpress']);

    fakeResyncServer();

    app(SiteConfigResyncer::class)->run();

    Process::assertRan(fn ($process) => in_array('option', $process->command, true)
        && in_array('home', $process->command, true)
        && in_array('http://one.test', $process->command, true));
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

it('creates the directories the config names, even for a site it skips', function () {
    // The site this exists for has a *correct* vhost already — it names an
    // ACME challenge root that was simply never created, because nothing made
    // one at provision time before 2026-09-03. So it is exactly the site the
    // "already current" skip passes over, and a resync that prepared nothing
    // left it broken while reporting success.
    //
    // It bites hardest on OpenLiteSpeed, which resolves a context's `location`
    // when the config loads rather than per request: the vhost fails its own
    // test and the site is rolled back. "Deploy, then resync" was the standing
    // advice for repairing precisely this, and it did not.
    $site = makeSite('one.test');

    $current = app(WebServerManager::class)
        ->driver()
        ->renderConfig(
            $site->load('systemUser'),
            app(ApplicationProvisioner::class)
                ->documentRoot($site->load('systemUser')),
        );

    fakeResyncServer(onDisk: $current);

    $result = app(SiteConfigResyncer::class)->run();

    // Still skipped — this must not start rewriting files that are correct.
    expect($result['unchanged'])->toBe(1)
        ->and($result['updated'])->toBe(0);

    // Asserted against the commands, not against the helper's return value:
    // that ArrayObject collects `tee` *input*, so a filter over it was reading
    // file contents and could never have seen a mkdir.
    Process::assertRan(fn ($process) => in_array('install', $process->command, true)
        && collect($process->command)->contains(fn ($arg) => str_contains((string) $arg, '.well-known/acme-challenge')));
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

/*
|--------------------------------------------------------------------------
| The grant that needs a restart nobody was asking for
|--------------------------------------------------------------------------
|
| OpenLiteSpeed's workers open each site's own access log, so the account they
| run as has to be in the site's log group. Adding it changes no config text at
| all — and the reload here was gated on text changing. On a server whose sites
| were all already current the grant landed and sat inert, because supplementary
| groups are read when a process starts.
|
| Measured live on 2026-09-22: `1 site(s): 0 updated, 1 already current`, no
| reload, and the site went on logging nothing until OpenLiteSpeed was restarted
| by hand.
*/

/** Point the resyncer at an OpenLiteSpeed server. */
function resyncOnOls(): void
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'ols',
        'web_server' => 'openlitespeed',
        'capabilities' => ['php' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);
}

/**
 * Like `fakeResyncServer()`, but answers the two questions the OLS log grant
 * asks: who the web server runs as, and whether it is already in the group.
 *
 * @return ArrayObject<int, array<int, string>> every command run
 */
function fakeOlsResync(string $onDisk, bool $alreadyMember): ArrayObject
{
    $commands = new ArrayObject;

    Process::fake(function ($process) use ($onDisk, $alreadyMember, $commands) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $commands->append($args);

        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'httpd_config')) {
            return Process::result(output: "serverName Example\nuser nobody\ngroup nogroup\n");
        }

        if (($args[0] ?? '') === 'cat') {
            return Process::result(output: $onDisk);
        }

        if (($args[0] ?? '') === 'id') {
            return Process::result(output: $alreadyMember ? 'nogroup siteowner' : 'nogroup');
        }

        return Process::result(exitCode: 0);
    });

    return $commands;
}

/** The config the resyncer would render for a site, i.e. "already current". */
function currentConfigFor(Application $site): string
{
    return app(WebServerManager::class)->driver()->renderConfig(
        $site->load('systemUser'),
        app(ApplicationProvisioner::class)->documentRoot($site->load('systemUser')),
    );
}

it('restarts the web server for a grant, even when no config changed', function () {
    resyncOnOls();
    $site = makeSite('one.test');

    $commands = fakeOlsResync(currentConfigFor($site), alreadyMember: false);

    $result = app(SiteConfigResyncer::class)->run();

    expect($result['updated'])->toBe(0)
        ->and($result['unchanged'])->toBe(1)
        ->and($result['granted'])->toBe(1)
        // The whole point: nothing was rewritten and the web server still has
        // to come back, or the membership does nothing.
        ->and($result['reloaded'])->toBeTrue();

    $joined = collect($commands)->map(fn ($c) => implode(' ', $c))->join("\n");

    expect($joined)->toContain('gpasswd -a nobody siteowner');
});

it('does not restart the web server when the account is already in the group', function () {
    // 🔴 The negative control, and the guard worth reverting hardest. This
    // command runs on every deploy; restarting the web server each time — for
    // a grant that was made months ago — would be a worse bug than the one the
    // test above fixes.
    resyncOnOls();
    $site = makeSite('one.test');

    $commands = fakeOlsResync(currentConfigFor($site), alreadyMember: true);

    $result = app(SiteConfigResyncer::class)->run();

    expect($result['granted'])->toBe(0)
        ->and($result['reloaded'])->toBeFalse();

    // And it does not issue the grant at all. `gpasswd -a` succeeds just as
    // happily on an existing member, so running it anyway would leave no way
    // to tell a new grant from a redundant one.
    $joined = collect($commands)->map(fn ($c) => implode(' ', $c))->join("\n");

    expect($joined)->toContain('id -nG nobody')
        ->and($joined)->not->toContain('gpasswd');
});
