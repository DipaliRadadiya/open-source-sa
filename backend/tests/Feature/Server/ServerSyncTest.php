<?php

use App\Contracts\DatabaseEngine;
use App\Enums\DomainType;
use App\Enums\SyncAction;
use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Http\Resources\DatabaseUserResource;
use App\Jobs\RunServerSync;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\Certificate;
use App\Models\Cronjob;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\FirewallRule;
use App\Models\ServerCapability;
use App\Models\SshKey;
use App\Models\SyncIgnore;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Worker;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\Sync\Discoverers\ApplicationDiscoverer;
use App\Services\Server\Sync\ServerSync;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Reading a migrated server into the panel.
 *
 * The two properties everything else rests on: a preview must change nothing,
 * and an apply must be safe to run twice. Everything below is one of those two
 * or a case where the sync has to say what it could not do rather than quietly
 * leaving it out.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

/**
 * A server with two real accounts, a system account, a reserved name, and one
 * account living outside the panel's home base.
 *
 * `$authorizedKeys` is keyed by username, not shared: returning one file for
 * every account made two users legitimately own the same key and hid which
 * user a key had actually been read from.
 *
 * @param  array<string, string>  $authorizedKeys
 */
function fakeServer(string $passwd = '', array $authorizedKeys = []): void
{
    $passwd = $passwd !== '' ? $passwd : implode("\n", [
        'root:x:0:0:root:/root:/bin/bash',
        'www-data:x:33:33:www-data:/var/www:/usr/sbin/nologin',
        'siteowner:x:1001:1001::/home/siteowner:/bin/bash',
        'shopuser:x:1002:1002::/home/shopuser:/usr/sbin/nologin',
        // Outside home_base — a developer's own login, not a site owner.
        'deploybot:x:1003:1003::/opt/deploybot:/bin/bash',
        // A name the panel refuses to create, so it must refuse to adopt it.
        'mysql:x:1004:1004::/home/mysql:/bin/false',
    ]);

    Process::fake(function ($process) use ($passwd, $authorizedKeys) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'getent') {
            return Process::result(output: $passwd);
        }

        if (($args[0] ?? '') === 'cat' && str_contains((string) ($args[1] ?? ''), 'authorized_keys')) {
            foreach ($authorizedKeys as $username => $contents) {
                if (str_contains((string) $args[1], "/{$username}/")) {
                    return Process::result(output: $contents);
                }
            }

            // No file is the normal case for a user who never had a key.
            return Process::result(exitCode: 1, errorOutput: 'No such file');
        }

        return Process::result(exitCode: 0);
    });
}

function runSync(SyncMode $mode = SyncMode::Preview, array $options = []): SyncRun
{
    $run = SyncRun::create([
        'mode' => $mode,
        'status' => SyncStatus::Pending,
        'options' => $options + ['only' => [], 'include_firewall' => false],
    ]);

    return app(ServerSync::class)->run($run);
}

/** @return array<int, string> resource keys recorded with this action */
function itemsWith(SyncRun $run, string $type, SyncAction $action): array
{
    return $run->items()
        ->where('resource_type', $type)
        ->where('action', $action->value)
        ->pluck('resource_key')
        ->all();
}

describe('discovering system users', function () {
    it('finds real login accounts and ignores the rest', function () {
        fakeServer();

        $found = itemsWith(runSync(), 'system_user', SyncAction::Found);

        expect($found)->toContain('siteowner', 'shopuser')
            // UID below 1000 is a system account on every Debian-derived
            // distribution; offering to manage root would be absurd.
            ->not->toContain('root')
            ->not->toContain('www-data')
            // Outside the panel's home base — someone's own login, not a site
            // owner, and claiming it would be presumptuous.
            ->not->toContain('deploybot')
            // A name the panel refuses to create, so it must refuse to adopt.
            ->not->toContain('mysql');
    });

    it('ignores an account the panel already tracks', function () {
        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
        fakeServer();

        expect(itemsWith(runSync(), 'system_user', SyncAction::Found))
            ->not->toContain('siteowner')
            ->toContain('shopuser');
    });
});

describe('preview', function () {
    it('creates nothing at all', function () {
        fakeServer();

        $before = SystemUser::count();
        $run = runSync(SyncMode::Preview);

        // The whole reason preview is the default: it has to be safe to press
        // on a production server without knowing what it will do.
        expect(SystemUser::count())->toBe($before)
            ->and($run->items()->where('action', SyncAction::Adopted->value)->count())->toBe(0)
            ->and($run->status)->toBe(SyncStatus::Completed);
    });

    it('never runs a command that writes to the server', function () {
        fakeServer();
        runSync(SyncMode::Preview);

        // Binaries that only ever write.
        foreach (['useradd', 'usermod', 'tee', 'rm', 'chmod', 'chown'] as $binary) {
            Process::assertNotRan(fn ($p) => in_array($binary, (array) $p->command, true));
        }

        // systemctl by verb, not by name. Banning the binary outright was a
        // proxy for "changes nothing", and it is the wrong proxy: `is-active`
        // and `show` are reads this codebase already relies on to answer what
        // is running — including web-server detection, which preview needs in
        // order to know whose vhosts it is about to read. Asserting on the verb
        // says what the test means, and still fails on the thing that matters.
        foreach (['start', 'stop', 'restart', 'reload', 'enable', 'disable', 'mask', 'daemon-reload'] as $verb) {
            Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'systemctl'
                && in_array($verb, (array) $p->command, true));
        }
    });
});

describe('apply', function () {
    it('creates the panel rows', function () {
        fakeServer();

        runSync(SyncMode::Apply);

        expect(SystemUser::pluck('username')->all())->toContain('siteowner', 'shopuser');
    });

    it('does not claim the account can do more than the panel knows', function () {
        fakeServer();
        runSync(SyncMode::Apply);

        $adopted = SystemUser::where('username', 'siteowner')->firstOrFail();

        // Neither is inferred from the account merely existing. `ssh_access`
        // especially: the panel does not enforce it yet, so setting it true
        // here would be a claim rather than a fact.
        expect($adopted->sudo)->toBeFalse()
            ->and($adopted->ssh_access)->toBeFalse()
            ->and($adopted->home_path)->toBe('/home/siteowner')
            ->and($adopted->shell)->toBe('/bin/bash');
    });

    it('is safe to run twice', function () {
        fakeServer();

        runSync(SyncMode::Apply);
        $afterFirst = SystemUser::count();

        $second = runSync(SyncMode::Apply);

        // A migration people are nervous about is a migration they will run
        // twice. The second pass must find nothing left to do.
        expect(SystemUser::count())->toBe($afterFirst)
            ->and(itemsWith($second, 'system_user', SyncAction::Adopted))->toBeEmpty();
    });
});

describe('ssh keys', function () {
    $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyMaterialForTests laptop@home';

    it('finds keys the panel has no record of', function () use ($key) {
        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
        fakeServer(authorizedKeys: ['siteowner' => $key]);

        $found = itemsWith(runSync(), 'ssh_key', SyncAction::Found);

        // A key on the box the panel cannot list is unaudited access — the
        // SSH Keys screen would say nobody can log in while a laptop still can.
        expect($found)->toHaveCount(1)
            ->and($found[0])->toStartWith('siteowner:SHA256:');
    });

    it('adopts a key against its owner and does not duplicate it', function () use ($key) {
        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
        fakeServer(authorizedKeys: ['siteowner' => $key]);

        runSync(SyncMode::Apply);
        runSync(SyncMode::Apply);

        expect(SshKey::count())->toBe(1)
            ->and(SshKey::first()->name)->toBe('laptop@home');
    });

    it('reports a line it cannot parse instead of dropping it', function () {
        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
        fakeServer(authorizedKeys: ['siteowner' => 'command="/usr/bin/rsync" ssh-rsa NOTVALIDBASE64!!!']);

        $skipped = itemsWith(runSync(), 'ssh_key', SyncAction::Skipped);

        // It may still grant access. Silently omitting it would make the
        // panel's list wrong in the one direction that matters.
        expect($skipped)->toHaveCount(1);
    });

    it('is skipped, with a reason, when system users were left out of the run', function () {
        fakeServer();

        $run = runSync(SyncMode::Preview, ['only' => ['ssh_key']]);
        $item = $run->items()->where('resource_type', 'ssh_key')->first();

        // Keys without their owners would all look ownerless. Saying so beats
        // returning an empty list that reads as "this server has no keys".
        expect($item->action)->toBe(SyncAction::Skipped)
            ->and($item->reason)->toBe('requires_system_user');
    });
});

describe('the API', function () {
    it('queues a run and answers 202', function () {
        Queue::fake();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync', ['mode' => 'preview'])
            ->assertStatus(202)
            ->assertJsonPath('sync.mode', 'preview')
            ->assertJsonPath('sync.status', 'pending');

        Queue::assertPushed(RunServerSync::class);
    });

    it('defaults to preview when no mode is sent', function () {
        Queue::fake();

        // An omitted field must never resolve to the mode that writes.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync', [])
            ->assertStatus(202)
            ->assertJsonPath('sync.mode', 'preview');
    });

    it('refuses a second run while one is live', function () {
        Queue::fake();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync', [])->assertStatus(202);

        // The job is unique, so a second dispatch would be dropped silently.
        // Better to say so than to hand back a run id that never starts.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync', [])
            ->assertJsonValidationErrors('sync');
    });

    it('returns only the items after the cursor', function () {
        fakeServer();
        $run = runSync();

        $all = $run->items()->orderBy('id')->pluck('id')->all();

        $page = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/server/sync/{$run->id}?since={$all[0]}")
            ->assertOk()
            ->json('sync.items');

        // Polling has to be cheap: re-sending a thousand rows every second to
        // add three is what makes a live feed cost more than it is worth.
        expect(collect($page)->pluck('id')->all())->not->toContain($all[0])
            ->and(count($page))->toBe(count($all) - 1);
    });

    it('lets a viewer watch but not start', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'sync', view: true, manage: false);
        $token = $viewer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/server/sync/latest')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/server/sync', [])->assertForbidden();
    });

    it('refuses someone with no sync permission at all', function () {
        $outsider = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$outsider->createToken('t')->plainTextToken)
            ->getJson('/api/server/sync/latest')->assertForbidden();
    });
});

it('records a failure per resource type instead of ending the run', function () {
    // getent fails; the run must still finish and still cover the rest.
    Process::fake(fn ($p) => ($p->command[0] ?? '') === 'sudo' && ($p->command[2] ?? '') === 'getent'
        ? Process::result(exitCode: 1, errorOutput: 'boom')
        : Process::result(exitCode: 0));

    $run = runSync();

    expect($run->status)->toBe(SyncStatus::Completed);
});

/*
 * Sites the web server is already serving.
 *
 * The hard resource: a database is a name, a site is a config file plus a
 * directory plus an owner plus a type read off the disk. Only the first three
 * are facts, and the tests below are mostly about the fourth being treated as
 * the guess it is.
 */
describe('discovering applications', function () {
    beforeEach(function () {
        ServerCapability::create([
            'stack' => 'lemp', 'web_server' => 'nginx',
            'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now(),
        ]);

        $this->owner = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    });

    /**
     * @param  array<string, string>  $vhosts  filename (no .conf) => contents
     * @param  array<int, string>  $files  paths that `test -f` should find
     */
    function fakeVhosts(array $vhosts, string $owner = 'siteowner', array $files = []): void
    {
        Process::fake(function ($process) use ($vhosts, $owner, $files) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $binary = $args[0] ?? '';

            if ($binary === 'getent') {
                return Process::result(output: "siteowner:x:1001:1001::/home/siteowner:/bin/bash\n");
            }

            if ($binary === 'find') {
                return Process::result(output: implode("\n", array_map(
                    fn (string $name): string => "/etc/nginx/sites-available/{$name}.conf",
                    array_keys($vhosts),
                )));
            }

            if ($binary === 'cat') {
                $path = (string) ($args[1] ?? '');

                if (str_contains($path, 'authorized_keys')) {
                    return Process::result(exitCode: 1, errorOutput: 'No such file');
                }

                foreach ($vhosts as $name => $contents) {
                    if (str_ends_with($path, "/{$name}.conf")) {
                        return Process::result(output: $contents);
                    }
                }

                return Process::result(exitCode: 1, errorOutput: 'No such file');
            }

            if ($binary === 'stat') {
                return Process::result(output: $owner);
            }

            if ($binary === 'test') {
                return Process::result(exitCode: in_array($args[2] ?? '', $files, true) ? 0 : 1);
            }

            return Process::result(exitCode: 0);
        });
    }

    function nginxVhost(string $serverNames, string $root = '/home/siteowner/shop/public_html'): string
    {
        return "server {\n    listen 80;\n    server_name {$serverNames};\n    root {$root};\n}\n";
    }

    it('finds a served site with all of its names', function () {
        fakeVhosts(['shop' => nginxVhost('shop.example.com www.shop.example.com')]);

        $item = runSync()->items()->where('resource_type', 'application')->first();

        expect($item->action)->toBe(SyncAction::Found)
            ->and($item->resource_key)->toBe('shop.example.com')
            // Aliases are part of the site, not separate sites.
            ->and($item->evidence['domains'])->toBe(['shop.example.com', 'www.shop.example.com']);
    });

    it('never adopts the panel\'s own vhost', function () {
        // Detected by the directory it serves, not by its name — see the
        // exclusion group below for why a name is not enough. Recorded with a
        // reason rather than dropped, so the list accounts for every vhost on
        // the box.
        fakeVhosts([
            'panel' => nginxVhost('panel.example.com', dirname(base_path()).'/backend/public'),
            'shop' => nginxVhost('shop.example.com'),
        ]);

        $items = runSync()->items()->where('resource_type', 'application')->get()->keyBy('resource_key');

        expect($items['panel.example.com']->action)->toBe(SyncAction::Skipped)
            ->and($items['panel.example.com']->reason)->toBe('panel_infrastructure')
            ->and($items['shop.example.com']->action)->toBe(SyncAction::Found);
    });

    it('ignores a site the panel already has', function () {
        Application::forceCreate([
            'system_user_id' => $this->owner->id, 'name' => 'Shop', 'slug' => 'shop',
            'domain' => 'shop.example.com', 'site_type' => 'php', 'serving_profile' => 'php',
            'status' => 'active', 'web_root' => '/',
        ]);

        fakeVhosts(['shop' => nginxVhost('shop.example.com')]);

        expect(runSync()->items()->where('resource_type', 'application')->count())->toBe(0);
    });

    it('refuses a site whose owner the panel does not manage', function () {
        fakeVhosts(['shop' => nginxVhost('shop.example.com')], owner: 'somebodyelse');

        $item = runSync()->items()->where('resource_type', 'application')->first();

        // Adopted anyway, the site would have no account to run as and every
        // later operation would fail somewhere much deeper than here.
        expect($item->action)->toBe(SyncAction::Skipped)
            ->and($item->reason)->toBe('owner_not_tracked');
    });

    it('reports a config it cannot read instead of dropping it', function () {
        fakeVhosts(['weird' => "# nothing this parser understands\n"]);

        $item = runSync()->items()->where('resource_type', 'application')->first();

        // The site is live. A list that silently omits it is wrong in the one
        // direction that matters.
        expect($item->action)->toBe(SyncAction::Skipped)
            ->and($item->reason)->toBe('vhost_unparsed');
    });

    it('reads an Apache vhost too', function () {
        fakeVhosts(['shop' => "<VirtualHost *:80>\n  ServerName shop.example.com\n  ServerAlias www.shop.example.com\n  DocumentRoot /home/siteowner/shop/public_html\n</VirtualHost>\n"]);

        $item = runSync()->items()->where('resource_type', 'application')->first();

        expect($item->action)->toBe(SyncAction::Found)
            ->and($item->evidence['domains'])->toContain('shop.example.com', 'www.shop.example.com');
    });

    it('drops nginx catch-alls and wildcards, which name no site', function () {
        fakeVhosts(['shop' => nginxVhost('_ *.example.com shop.example.com')]);

        expect(runSync()->items()->where('resource_type', 'application')->first()->evidence['domains'])
            ->toBe(['shop.example.com']);
    });
});

describe('inferring what a site is', function () {
    beforeEach(function () {
        ServerCapability::create([
            'stack' => 'lemp', 'web_server' => 'nginx',
            'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now(),
        ]);

        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    });

    it('is confident about WordPress and says why', function () {
        fakeVhosts(
            ['shop' => nginxVhost('shop.example.com')],
            files: ['/home/siteowner/shop/public_html/wp-config.php'],
        );

        $item = runSync()->items()->where('resource_type', 'application')->first();

        // The evidence is the point: a user confirming this needs to know it
        // was a file on disk and not a hunch.
        expect($item->evidence['site_type'])->toBe('wordpress')
            ->and($item->evidence['matched'])->toBe('wp-config.php')
            ->and($item->confidence)->toBeGreaterThan(90);
    });

    it('is honest when it recognises nothing', function () {
        fakeVhosts(['shop' => nginxVhost('shop.example.com')], files: []);

        $item = runSync()->items()->where('resource_type', 'application')->first();

        // Low confidence and no evidence, rather than a confident wrong
        // answer. The screen can then ask instead of telling.
        expect($item->confidence)->toBeLessThan(20)
            ->and($item->evidence['matched'])->toBeNull()
            // php over static: serving a PHP app as plain files publishes its
            // source, and the opposite mistake only costs a spare handler.
            ->and($item->evidence['site_type'])->toBe('php');
    });

    it('prefers the more specific signature', function () {
        // A Laravel app has index.php too; whichever is checked first decides,
        // so the order of the signature list is load-bearing.
        fakeVhosts(
            ['shop' => nginxVhost('shop.example.com')],
            files: [
                '/home/siteowner/shop/public_html/artisan',
                '/home/siteowner/shop/public_html/index.php',
            ],
        );

        expect(runSync()->items()->where('resource_type', 'application')->first()->evidence['site_type'])
            ->toBe('git');
    });
});

describe('adopting a site', function () {
    beforeEach(function () {
        ServerCapability::create([
            'stack' => 'lemp', 'web_server' => 'nginx',
            'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now(),
        ]);

        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    });

    it('creates the site and every one of its domains', function () {
        fakeVhosts(
            ['shop' => nginxVhost('shop.example.com www.shop.example.com')],
            files: ['/home/siteowner/shop/public_html/wp-config.php'],
        );

        runSync(SyncMode::Apply);

        $application = Application::where('domain', 'shop.example.com')->firstOrFail();

        expect($application->site_type)->toBe('wordpress')
            ->and($application->slug)->not->toBeNull()
            ->and($application->domains()->count())->toBe(2)
            ->and($application->domains()->where('type', 'primary')->count())->toBe(1);
    });

    it('keeps the guess where anyone acting on the site will see it', function () {
        fakeVhosts(
            ['shop' => nginxVhost('shop.example.com')],
            files: ['/home/siteowner/shop/public_html/wp-config.php'],
        );

        runSync(SyncMode::Apply);

        $adoption = Application::where('domain', 'shop.example.com')->firstOrFail()->settings['adoption'];

        // Nobody looks up a sync run before running a command against a site,
        // so the confidence has to travel with the site itself.
        expect($adoption['inferred_site_type'])->toBe('wordpress')
            ->and($adoption['matched'])->toBe('wp-config.php')
            ->and($adoption['confidence'])->toBeGreaterThan(90);
    });

    it('does not claim the panel wrote a pool for it', function () {
        fakeVhosts(['shop' => nginxVhost('shop.example.com')]);

        runSync(SyncMode::Apply);

        // `isolated_at` set here would make php:isolate-all skip the one site
        // that most needs converting.
        expect(Application::where('domain', 'shop.example.com')->firstOrFail()->isolated_at)->toBeNull();
    });

    it('is safe to run twice', function () {
        fakeVhosts(['shop' => nginxVhost('shop.example.com')]);

        runSync(SyncMode::Apply);
        $after = Application::count();

        runSync(SyncMode::Apply);

        expect(Application::count())->toBe($after);
    });
});

/*
 * Things on the box that are not customer sites.
 *
 * A server usually runs more than the sites it hosts — the panel itself, and
 * whatever else the operator put there. Offering to adopt the control plane as
 * a site is the failure mode, and matching on names does not prevent it:
 * install.sh writes both `{slug}.conf` and `{slug}-tls.conf`, the slug is
 * overridable, and the panel answers on a domain rather than that slug. On the
 * box this was developed against the panel's own frontend vhost is called
 * `sv-oss-app.conf`, which no amount of matching "panel" would ever catch.
 */
describe('excluding what is not a customer site', function () {
    beforeEach(function () {
        ServerCapability::create([
            'stack' => 'lemp', 'web_server' => 'nginx',
            'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now(),
        ]);

        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

        // Where this code is actually running — the panel's own directory is
        // one level above the Laravel root, whatever it happens to be called.
        $this->panelRoot = dirname(base_path());
    });

    it('refuses any vhost serving the panel\'s own directory', function () {
        fakeVhosts([
            // Named nothing like "panel", exactly as on a real install.
            'sv-oss-app' => nginxVhost('panel.example.com', $this->panelRoot.'/frontend'),
        ]);

        $item = runSync()->items()->where('resource_type', 'application')->first();

        expect($item->action)->toBe(SyncAction::Skipped)
            ->and($item->reason)->toBe('panel_infrastructure');
    });

    it('catches the TLS vhost of the panel too', function () {
        // install.sh writes a second file per panel vhost. Excluding one name
        // left this one adoptable.
        fakeVhosts(['panel-tls' => nginxVhost('panel.example.com', $this->panelRoot.'/backend/public')]);

        expect(runSync()->items()->where('resource_type', 'application')->first()->reason)
            ->toBe('panel_infrastructure');
    });

    it('refuses a site laid out somewhere the panel could not manage it', function () {
        // Plesk-style, or anything else hand-built. Reported rather than
        // dropped: the site is live and the user should know it was seen.
        fakeVhosts(['legacy' => nginxVhost('legacy.example.com', '/var/www/vhosts/legacy/httpdocs')]);

        $item = runSync()->items()->where('resource_type', 'application')->first();

        expect($item->action)->toBe(SyncAction::Skipped)
            ->and($item->reason)->toBe('outside_panel_layout')
            ->and($item->evidence['document_root'])->toBe('/var/www/vhosts/legacy/httpdocs');
    });

    it('honours an operator\'s own exclusions, globs included', function () {
        config(['server.sync.exclude.vhosts' => ['internal-*']]);

        fakeVhosts([
            'internal-metrics' => nginxVhost('metrics.example.com'),
            'shop' => nginxVhost('shop.example.com'),
        ]);

        // Excluded by the operator, so not even worth a line — unlike the
        // rules above, this is a deliberate "I know, stop showing me".
        expect(runSync()->items()->where('resource_type', 'application')->pluck('resource_key')->all())
            ->toBe(['shop.example.com']);
    });

    it('honours an exclusion by domain', function () {
        config(['server.sync.exclude.domains' => ['*.internal.example.com']]);

        fakeVhosts([
            'metrics' => nginxVhost('box.internal.example.com'),
            'shop' => nginxVhost('shop.example.com'),
        ]);

        expect(runSync()->items()->where('resource_type', 'application')->pluck('resource_key')->all())
            ->toBe(['shop.example.com']);
    });

    it('still adopts a real site laid out the panel\'s way', function () {
        // The guard against over-blocking: all of the above must not cost us
        // the sites the feature exists to find.
        fakeVhosts(['shop' => nginxVhost('shop.example.com', '/home/siteowner/shop/public_html')]);

        expect(runSync()->items()->where('resource_type', 'application')->first()->action)
            ->toBe(SyncAction::Found);
    });
});

/*
 * The limits a migrated site is actually running under.
 *
 * Without this an adopted site shows the panel's defaults while FPM enforces
 * whatever the old panel wrote — the screen describing a server it is not
 * looking at, and the first save quietly replacing real limits with invented
 * ones.
 */
describe('importing php settings from the pool that serves a site', function () {
    beforeEach(function () {
        ServerCapability::create([
            'stack' => 'lemp', 'web_server' => 'nginx',
            'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now(),
        ]);

        $this->su = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

        $this->application = Application::forceCreate([
            'system_user_id' => $this->su->id, 'name' => 'Shop', 'slug' => 'shop',
            'domain' => 'shop.example.com', 'site_type' => 'php', 'serving_profile' => 'php',
            'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        ]);
    });

    /** @param  array<string, string>  $pools  absolute path => contents */
    function fakePools(array $pools): void
    {
        Process::fake(function ($process) use ($pools) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $binary = $args[0] ?? '';

            if ($binary === 'getent') {
                return Process::result(output: "siteowner:x:1001:1001::/home/siteowner:/bin/bash\n");
            }

            if ($binary === 'find') {
                $directory = (string) ($args[1] ?? '');

                return Process::result(output: implode("\n", array_filter(
                    array_keys($pools),
                    fn (string $path): bool => dirname($path) === rtrim($directory, '/'),
                )));
            }

            if ($binary === 'cat') {
                $path = (string) ($args[1] ?? '');

                return array_key_exists($path, $pools)
                    ? Process::result(output: $pools[$path])
                    : Process::result(exitCode: 1, errorOutput: 'No such file');
            }

            return Process::result(exitCode: 0);
        });
    }

    it('finds the pool by the user it runs as, not by the name we would have used', function () {
        // The bug this exists for: poolPath() builds a name from our own slug,
        // so on a box migrated from another panel it pointed at nothing and
        // the import silently did nothing at all.
        fakePools([
            '/etc/php/8.4/fpm/pool.d/oldpanel_shop_example_com.conf' => "[oldpanel]\nuser = siteowner\nphp_admin_value[memory_limit] = 1024M\n",
        ]);

        expect(app(PoolManager::class)->livePoolPath($this->application->load('systemUser')))
            ->toBe('/etc/php/8.4/fpm/pool.d/oldpanel_shop_example_com.conf');
    });

    it('does not claim a pool belonging to a different site', function () {
        fakePools([
            '/etc/php/8.4/fpm/pool.d/other.conf' => "[other]\nuser = someoneelse\n",
        ]);

        // `user =` is the one property a pool cannot fake — it is what FPM
        // drops privileges to. Matching anything looser would attach one
        // site's limits to another.
        expect(app(PoolManager::class)->livePoolPath($this->application->load('systemUser')))->toBeNull();
    });

    it('imports the limits the pool states', function () {
        fakePools([
            '/etc/php/8.4/fpm/pool.d/oldpanel_shop.conf' => implode("\n", [
                '[oldpanel]',
                'user = siteowner',
                'php_admin_value[memory_limit] = 1024M',
                'php_value[upload_max_filesize] = 512M',
                'php_admin_value[max_execution_time] = 300',
            ]),
        ]);

        runSync(SyncMode::Apply);

        $settings = ApplicationPhpSettings::where('application_id', $this->application->id)->firstOrFail();

        // php_value as well as php_admin_value: plenty of hand-written pools
        // use the first, and a site running under it is running under it.
        expect($settings->memory_limit)->toBe('1024M')
            ->and($settings->upload_max_filesize)->toBe('512M')
            ->and((int) $settings->max_execution_time)->toBe(300);
    });

    it('leaves unstated limits unset rather than inventing them', function () {
        fakePools([
            '/etc/php/8.4/fpm/pool.d/oldpanel_shop.conf' => "[oldpanel]\nuser = siteowner\nphp_admin_value[memory_limit] = 1024M\n",
        ]);

        runSync(SyncMode::Apply);

        $settings = ApplicationPhpSettings::where('application_id', $this->application->id)->firstOrFail();

        // A limit the old pool did not set is one PHP was taking from its own
        // ini. Storing a number here would turn a server default into a
        // decision that then stops following the server.
        expect($settings->memory_limit)->toBe('1024M')
            ->and($settings->post_max_size)->toBeNull()
            ->and($settings->max_input_vars)->toBeNull();
    });

    it('never overwrites settings a user already chose', function () {
        ApplicationPhpSettings::create([
            'application_id' => $this->application->id,
            'memory_limit' => '128M',
        ]);

        fakePools([
            '/etc/php/8.4/fpm/pool.d/oldpanel_shop.conf' => "[oldpanel]\nuser = siteowner\nphp_admin_value[memory_limit] = 1024M\n",
        ]);

        runSync(SyncMode::Apply);

        // A migrated pool must not overrule a decision made inside the panel.
        expect(ApplicationPhpSettings::where('application_id', $this->application->id)->first()->memory_limit)
            ->toBe('128M');
    });

    it('takes the last of a repeated directive, the way FPM does', function () {
        fakePools([
            '/etc/php/8.4/fpm/pool.d/oldpanel_shop.conf' => implode("\n", [
                '[oldpanel]',
                'user = siteowner',
                'php_admin_value[memory_limit] = 256M',
                '; someone appended an override further down',
                'php_admin_value[memory_limit] = 2048M',
            ]),
        ]);

        runSync(SyncMode::Apply);

        // Taking the first would import a value the server is not using.
        expect(ApplicationPhpSettings::where('application_id', $this->application->id)->first()->memory_limit)
            ->toBe('2048M');
    });
});

/*
 * Background processes a migrated site is already running.
 *
 * The fuzziest resource: nothing on a server says "this is a queue worker".
 * Attribution is by path — a working directory inside a site the panel knows
 * — and the kind is a guess off a command line.
 */
describe('discovering workers', function () {
    beforeEach(function () {
        $this->su = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

        $this->application = Application::forceCreate([
            'system_user_id' => $this->su->id, 'name' => 'Shop', 'slug' => 'shop',
            'domain' => 'shop.example.com', 'site_type' => 'git', 'serving_profile' => 'php',
            'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        ]);
    });

    /** @param  array<string, string>  $files  absolute path => contents */
    function fakeProcessDefinitions(array $files): void
    {
        Process::fake(function ($process) use ($files) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $binary = $args[0] ?? '';

            if ($binary === 'getent') {
                return Process::result(output: "siteowner:x:1001:1001::/home/siteowner:/bin/bash\n");
            }

            if ($binary === 'find') {
                $directory = rtrim((string) ($args[1] ?? ''), '/');

                return Process::result(output: implode("\n", array_filter(
                    array_keys($files),
                    fn (string $path): bool => dirname($path) === $directory,
                )));
            }

            if ($binary === 'cat') {
                $path = (string) ($args[1] ?? '');

                return array_key_exists($path, $files)
                    ? Process::result(output: $files[$path])
                    : Process::result(exitCode: 1, errorOutput: 'No such file');
            }

            return Process::result(exitCode: 0);
        });
    }

    it('finds a supervisor queue worker and ties it to its site', function () {
        fakeProcessDefinitions([
            '/etc/supervisor/conf.d/shop-worker.conf' => implode("\n", [
                '[program:shop-worker]',
                'command=php /home/siteowner/shop/artisan queue:work --tries=3',
                'directory=/home/siteowner/shop',
                'numprocs=4',
                'user=siteowner',
            ]),
        ]);

        $item = runSync()->items()->where('resource_type', 'worker')->first();

        expect($item->action)->toBe(SyncAction::Found)
            ->and($item->evidence['source'])->toBe('supervisor')
            ->and($item->evidence['kind'])->toBe('queue')
            ->and($item->evidence['processes'])->toBe(4)
            ->and($item->evidence['application'])->toBe('shop.example.com');
    });

    it('finds a systemd unit too', function () {
        fakeProcessDefinitions([
            '/etc/systemd/system/shop-horizon.service' => implode("\n", [
                '[Service]',
                'WorkingDirectory=/home/siteowner/shop',
                'ExecStart=/usr/bin/php artisan horizon',
            ]),
        ]);

        $item = runSync()->items()->where('resource_type', 'worker')->first();

        expect($item->evidence['source'])->toBe('systemd')
            // Horizon restarts with horizon:terminate, a queue worker with
            // queue:restart. Guessing wrong means a restart that does not.
            ->and($item->evidence['kind'])->toBe('horizon');
    });

    it('ignores a service that has nothing to do with any site', function () {
        fakeProcessDefinitions([
            '/etc/systemd/system/nginx.service' => "[Service]\nExecStart=/usr/sbin/nginx -g 'daemon off;'\n",
        ]);

        // A box is full of services. Listing every one would bury the handful
        // that matter, and the panel cannot manage what it cannot place.
        expect(runSync()->items()->where('resource_type', 'worker')->count())->toBe(0);
    });

    it('skips the panel\'s own units', function () {
        fakeProcessDefinitions([
            // The name encodes a worker id from this database. Adopting it
            // would invent a worker whose unit the panel writes elsewhere.
            '/etc/systemd/system/sv-worker-7@.service' => "[Service]\nWorkingDirectory=/home/siteowner/shop\nExecStart=/usr/bin/php artisan queue:work\n",
        ]);

        expect(runSync()->items()->where('resource_type', 'worker')->count())->toBe(0);
    });

    it('adopts the worker disabled, so it does not race the one already running', function () {
        fakeProcessDefinitions([
            '/etc/supervisor/conf.d/shop-worker.conf' => implode("\n", [
                '[program:shop-worker]',
                'command=php /home/siteowner/shop/artisan queue:work',
                'directory=/home/siteowner/shop',
                'numprocs=2',
            ]),
        ]);

        runSync(SyncMode::Apply);

        $worker = Worker::where('application_id', $this->application->id)->firstOrFail();

        // The hazard unique to this resource: starting the panel's copy while
        // supervisor still runs the original puts two processes on one queue.
        // For anything touching money that is the failure that matters.
        expect($worker->enabled)->toBeFalse()
            ->and($worker->kind)->toBe('queue')
            ->and($worker->processes)->toBe(2);
    });

    it('says the original is still running, so the user knows to stop it', function () {
        fakeProcessDefinitions([
            '/etc/supervisor/conf.d/shop-worker.conf' => "[program:shop-worker]\ncommand=php /home/siteowner/shop/artisan queue:work\ndirectory=/home/siteowner/shop\n",
        ]);

        $item = runSync()->items()->where('resource_type', 'worker')->first();

        expect($item->evidence['already_running_elsewhere'])->toBeTrue()
            // Named, so the user can go and stop the right thing.
            ->and($item->evidence['path'])->toBe('/etc/supervisor/conf.d/shop-worker.conf');
    });

    it('is safe to run twice', function () {
        fakeProcessDefinitions([
            '/etc/supervisor/conf.d/shop-worker.conf' => "[program:shop-worker]\ncommand=php /home/siteowner/shop/artisan queue:work\ndirectory=/home/siteowner/shop\n",
        ]);

        runSync(SyncMode::Apply);
        runSync(SyncMode::Apply);

        expect(Worker::count())->toBe(1);
    });

    it('does not let one site claim a directory that merely starts with its name', function () {
        // `/home/siteowner/shop` is a string prefix of
        // `/home/siteowner/shopping` but not a path prefix. Only one site
        // exists here, so ordering cannot rescue this — the boundary has to
        // be the separator.
        fakeProcessDefinitions([
            '/etc/supervisor/conf.d/other.conf' => "[program:other]\ncommand=php artisan queue:work\ndirectory=/home/siteowner/shopping/current\n",
        ]);

        expect(runSync()->items()->where('resource_type', 'worker')->count())->toBe(0);
    });

    it('gives a nested site its own worker rather than the parent taking it', function () {
        $inner = Application::forceCreate([
            'system_user_id' => $this->su->id, 'name' => 'Inner', 'slug' => 'shop-inner',
            'domain' => 'inner.example.com', 'site_type' => 'git', 'serving_profile' => 'php',
            'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        ]);

        fakeProcessDefinitions([
            '/etc/supervisor/conf.d/inner.conf' => "[program:inner-worker]\ncommand=php artisan queue:work\ndirectory=/home/siteowner/shop-inner/current\n",
        ]);

        // `/home/siteowner/shop` is a prefix of `/home/siteowner/shop-inner`
        // as a string but not as a path. Longest-root-first settles it.
        expect(runSync()->items()->where('resource_type', 'worker')->first()->evidence['application'])
            ->toBe($inner->domain);
    });
});

/*
 * Accounts that can reach an adopted database.
 *
 * Databases were already adoptable and their users were not, so an adopted
 * database showed an empty user list — which reads as "nobody can connect to
 * this" while four applications were connecting to it perfectly well.
 *
 * The password is the part that cannot be recovered, and the tests below are
 * mostly about the panel saying so rather than covering for it.
 */
describe('discovering database users', function () {
    beforeEach(function () {
        $this->database = Database::create([
            'name' => 'shop_db', 'engine' => 'mysql',
        ]);
    });

    /** @param  array<int, array{username: string, host: string, databases: array<int, string>}>  $users */
    function fakeEngineUsers(array $users): void
    {
        $engine = Mockery::mock(DatabaseEngine::class);
        $engine->shouldReceive('available')->andReturn(true);
        $engine->shouldReceive('listUsers')->andReturn($users);

        $manager = Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('engineNames')->andReturn(['mysql']);
        $manager->shouldReceive('engine')->with('mysql')->andReturn($engine);

        app()->instance(DatabaseManager::class, $manager);

        Process::fake(fn () => Process::result(exitCode: 0));
    }

    it('finds an account granted on a database the panel tracks', function () {
        fakeEngineUsers([
            ['username' => 'shop_user', 'host' => 'localhost', 'databases' => ['shop_db']],
        ]);

        $item = runSync()->items()->where('resource_type', 'database_user')->first();

        expect($item->action)->toBe(SyncAction::Found)
            ->and($item->evidence['username'])->toBe('shop_user')
            ->and($item->evidence['database'])->toBe('shop_db');
    });

    it('says out loud that the password cannot be recovered', function () {
        fakeEngineUsers([
            ['username' => 'shop_user', 'host' => 'localhost', 'databases' => ['shop_db']],
        ]);

        // The engine holds a hash. Anything the panel displayed as a password
        // here would be invented.
        expect(runSync()->items()->where('resource_type', 'database_user')->first()->evidence['password_recoverable'])
            ->toBeFalse();
    });

    it('ignores an account on a database the panel has not adopted', function () {
        fakeEngineUsers([
            ['username' => 'other_user', 'host' => 'localhost', 'databases' => ['some_other_db']],
        ]);

        // Adopting the database is the step that comes first; a user with
        // nothing to hang off is not a finding.
        expect(runSync()->items()->where('resource_type', 'database_user')->count())->toBe(0);
    });

    it('adopts with no password rather than an invented one', function () {
        fakeEngineUsers([
            ['username' => 'shop_user', 'host' => 'localhost', 'databases' => ['shop_db']],
        ]);

        runSync(SyncMode::Apply);

        $user = DatabaseUser::where('username', 'shop_user')->firstOrFail();

        expect($user->password)->toBeNull()
            ->and($user->database_id)->toBe($this->database->id);
    });

    it('records what the grant actually allows, not the narrowest reading', function () {
        fakeEngineUsers([
            ['username' => 'remote_user', 'host' => '%', 'databases' => ['shop_db']],
        ]);

        runSync(SyncMode::Apply);

        // A user granted from `%` can connect from anywhere. Recording that
        // as localhost would describe the account as narrower than it is —
        // the wrong direction for anything anyone later audits.
        expect(DatabaseUser::where('username', 'remote_user')->firstOrFail()->connection_preference)
            ->toBe('anywhere');
    });

    it('is safe to run twice', function () {
        fakeEngineUsers([
            ['username' => 'shop_user', 'host' => 'localhost', 'databases' => ['shop_db']],
        ]);

        runSync(SyncMode::Apply);
        runSync(SyncMode::Apply);

        expect(DatabaseUser::count())->toBe(1);
    });
});

it('never hands out a connection string it knows will not work', function () {
    $database = Database::create(['name' => 'shop_db', 'engine' => 'mysql']);

    $adopted = DatabaseUser::create([
        'database_id' => $database->id, 'username' => 'shop_user',
        'password' => null, 'connection_preference' => 'localhost', 'host' => 'localhost',
    ]);

    $payload = DatabaseUserResource::make($adopted->load('database'))->resolve();

    // `mysql://shop_user:@127.0.0.1:3306/shop_db` looks right and fails at
    // connect time, which moves the confusion somewhere much harder to debug
    // than this screen.
    expect($payload['password_known'])->toBeFalse()
        ->and($payload['connection_string'])->toBeNull();
});

/*
 * Certificates already issued on the box.
 *
 * Without these an adopted site reads as having no TLS while it is serving
 * HTTPS — and the panel would offer to issue a certificate for a name that
 * already has one, spending a weekly rate limit to replace something working.
 */
describe('discovering certificates', function () {
    beforeEach(function () {
        $su = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

        $this->application = Application::forceCreate([
            'system_user_id' => $su->id, 'name' => 'Shop', 'slug' => 'shop',
            'domain' => 'shop.example.com', 'site_type' => 'php', 'serving_profile' => 'php',
            'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        ]);

        $this->application->domains()->create([
            'domain' => 'shop.example.com', 'type' => DomainType::Primary,
        ]);
    });

    /** @param  array<int, string>  $liveDirs */
    function fakeCertbot(array $liveDirs, string $enddate = 'notAfter=Dec  1 12:00:00 2026 GMT'): void
    {
        Process::fake(function ($process) use ($liveDirs, $enddate) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $binary = $args[0] ?? '';

            if ($binary === 'getent') {
                return Process::result(output: "siteowner:x:1001:1001::/home/siteowner:/bin/bash\n");
            }

            if ($binary === 'find' && str_contains((string) ($args[1] ?? ''), 'letsencrypt')) {
                return Process::result(output: implode("\n", $liveDirs));
            }

            if ($binary === 'openssl') {
                return Process::result(output: $enddate);
            }

            if ($binary === 'find' || $binary === 'cat' || $binary === 'crontab') {
                return Process::result(exitCode: 1, errorOutput: 'nothing');
            }

            return Process::result(exitCode: 0);
        });
    }

    it('finds a certificate and ties it to the site it covers', function () {
        fakeCertbot(['/etc/letsencrypt/live/shop.example.com']);

        $item = runSync()->items()->where('resource_type', 'certificate')->first();

        expect($item->action)->toBe(SyncAction::Found)
            ->and($item->evidence['application'])->toBe('shop.example.com');
    });

    it('handles certbot\'s re-issue suffix', function () {
        // certbot appends -0001 when a certificate is re-issued under a
        // changed set of names. The directory is not the domain.
        fakeCertbot(['/etc/letsencrypt/live/shop.example.com-0001']);

        expect(runSync()->items()->where('resource_type', 'certificate')->first()->evidence['application'])
            ->toBe('shop.example.com');
    });

    it('leaves a certificate for a name the panel does not serve', function () {
        fakeCertbot(['/etc/letsencrypt/live/someone-else.example.com']);

        // A certificate on the wrong application is worse than one the panel
        // simply does not know about.
        expect(runSync()->items()->where('resource_type', 'certificate')->count())->toBe(0);
    });

    it('adopts it as renewing, because certbot already renews it', function () {
        fakeCertbot(['/etc/letsencrypt/live/shop.example.com']);

        runSync(SyncMode::Apply);

        $certificate = Certificate::where('application_id', $this->application->id)->firstOrFail();

        expect($certificate->auto_renew)->toBeTrue()
            ->and($certificate->expires_at)->not->toBeNull()
            // Not inferred: whether the site redirects to HTTPS is a vhost
            // decision the panel has not read.
            ->and($certificate->force_https)->toBeFalse();
    });

    it('is safe to run twice', function () {
        fakeCertbot(['/etc/letsencrypt/live/shop.example.com']);

        runSync(SyncMode::Apply);
        runSync(SyncMode::Apply);

        expect(Certificate::count())->toBe(1);
    });
});

/*
 * Scheduled jobs. The resource people notice last and miss most: a cron job
 * that stops after a migration is discovered a week later, by whatever did
 * not happen.
 */
describe('discovering cronjobs', function () {
    beforeEach(function () {
        SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    });

    /**
     * @param  array<string, string>  $cronD  path => contents
     * @param  array<string, string>  $crontabs  username => contents
     */
    function fakeCron(array $cronD = [], array $crontabs = []): void
    {
        Process::fake(function ($process) use ($cronD, $crontabs) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $binary = $args[0] ?? '';

            if ($binary === 'getent') {
                return Process::result(output: "siteowner:x:1001:1001::/home/siteowner:/bin/bash\n");
            }

            if ($binary === 'find' && str_contains((string) ($args[1] ?? ''), 'cron.d')) {
                return Process::result(output: implode("\n", array_keys($cronD)));
            }

            if ($binary === 'crontab') {
                $user = (string) ($args[3] ?? '');

                return array_key_exists($user, $crontabs)
                    ? Process::result(output: $crontabs[$user])
                    : Process::result(exitCode: 1, errorOutput: 'no crontab');
            }

            if ($binary === 'cat' && array_key_exists((string) ($args[1] ?? ''), $cronD)) {
                return Process::result(output: $cronD[$args[1]]);
            }

            return Process::result(exitCode: 1, errorOutput: 'nothing');
        });
    }

    it('finds a cron.d job belonging to a managed account', function () {
        fakeCron(cronD: [
            '/etc/cron.d/shop-backup' => "SHELL=/bin/sh\n0 3 * * * siteowner /usr/bin/php /home/siteowner/shop/artisan backup:run\n",
        ]);

        $item = runSync()->items()->where('resource_type', 'cronjob')->first();

        expect($item->action)->toBe(SyncAction::Found)
            ->and($item->evidence['expression'])->toBe('0 3 * * *')
            ->and($item->evidence['username'])->toBe('siteowner');
    });

    it('leaves root\'s cron alone', function () {
        fakeCron(cronD: [
            '/etc/cron.d/certbot' => "0 */12 * * * root test -x /usr/bin/certbot && certbot -q renew\n",
        ]);

        // Root's cron is the operating system's. A panel that offered to
        // manage certbot renewals would eventually be asked to delete one.
        expect(runSync()->items()->where('resource_type', 'cronjob')->count())->toBe(0);
    });

    it('reads a user\'s own crontab too', function () {
        fakeCron(crontabs: [
            'siteowner' => "MAILTO=\"\"\n*/5 * * * * /usr/bin/php /home/siteowner/shop/artisan schedule:run\n",
        ]);

        $item = runSync()->items()->where('resource_type', 'cronjob')->first();

        // Where a developer puts things by hand, and where a cron.d-only
        // sync would have reported a site with no schedule at all.
        expect($item->evidence['source'])->toBe('crontab')
            ->and($item->evidence['expression'])->toBe('*/5 * * * *');
    });

    it('ignores comments and environment lines', function () {
        fakeCron(cronD: [
            '/etc/cron.d/shop' => implode("\n", [
                '# Managed by the old panel',
                'SHELL=/bin/sh',
                'PATH=/usr/local/bin:/usr/bin:/bin',
                'MAILTO=""',
                '0 3 * * * siteowner /usr/bin/php artisan backup:run',
            ]),
        ]);

        // A cron file opens with assignments. Parsed as jobs they would be
        // adopted as nonsense schedules.
        expect(runSync()->items()->where('resource_type', 'cronjob')->count())->toBe(1);
    });

    it('is safe to run twice', function () {
        fakeCron(cronD: [
            '/etc/cron.d/shop' => "0 3 * * * siteowner /usr/bin/php artisan backup:run\n",
        ]);

        runSync(SyncMode::Apply);
        runSync(SyncMode::Apply);

        expect(Cronjob::count())->toBe(1);
    });

    it('keeps two jobs that run the same command on different schedules', function () {
        fakeCron(cronD: [
            '/etc/cron.d/shop' => implode("\n", [
                '0 3 * * * siteowner /usr/bin/php artisan backup:run',
                '0 15 * * * siteowner /usr/bin/php artisan backup:run',
            ]),
        ]);

        runSync(SyncMode::Apply);

        // The slug is derived from the command, so without the schedule in
        // the hash the second job would collide with the first and vanish.
        expect(Cronjob::count())->toBe(2);
    });
});

/*
 * Things the user has already said no to.
 *
 * Without this every sync re-lists the same items forever. On the box this
 * was developed against that is twenty-one vhosts the panel can never adopt,
 * and a list where the same twenty-one lines appear every time is one nobody
 * reads — which costs the feature the only thing it is for.
 */
describe('ignoring an item', function () {
    it('stops it appearing in later runs', function () {
        fakeServer();

        expect(itemsWith(runSync(), 'system_user', SyncAction::Found))->toContain('shopuser');

        SyncIgnore::create(['resource_type' => 'system_user', 'resource_key' => 'shopuser']);

        // Not recorded as skipped either. The point of ignoring something is
        // that it stops appearing, not that it appears differently.
        expect(itemsWith(runSync(), 'system_user', SyncAction::Found))->not->toContain('shopuser')
            ->and(runSync()->items()->where('resource_key', 'shopuser')->count())->toBe(0);
    });

    it('leaves everything else alone', function () {
        fakeServer();
        SyncIgnore::create(['resource_type' => 'system_user', 'resource_key' => 'shopuser']);

        expect(itemsWith(runSync(), 'system_user', SyncAction::Found))->toContain('siteowner');
    });

    it('is scoped to its own resource type', function () {
        fakeServer();

        // The same key can mean different things for different resources; an
        // ignore on one must not silently cover the other.
        SyncIgnore::create(['resource_type' => 'application', 'resource_key' => 'shopuser']);

        expect(itemsWith(runSync(), 'system_user', SyncAction::Found))->toContain('shopuser');
    });

    it('can be shown again on request', function () {
        fakeServer();
        SyncIgnore::create(['resource_type' => 'system_user', 'resource_key' => 'shopuser']);

        // An ignore made by mistake has to be reachable from the screen that
        // made it, or it is permanent.
        expect(itemsWith(runSync(SyncMode::Preview, ['include_ignored' => true]), 'system_user', SyncAction::Found))
            ->toContain('shopuser');
    });

    it('is never adopted, even in apply mode', function () {
        fakeServer();
        SyncIgnore::create(['resource_type' => 'system_user', 'resource_key' => 'shopuser']);

        runSync(SyncMode::Apply);

        expect(SystemUser::where('username', 'shopuser')->exists())->toBeFalse()
            ->and(SystemUser::where('username', 'siteowner')->exists())->toBeTrue();
    });
});

describe('the ignore endpoints', function () {
    it('records a dismissal with the reason behind it', function () {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync/ignores', [
                'resource_type' => 'application',
                'resource_key' => 'metrics.internal',
                'note' => 'Our own monitoring box',
            ])
            ->assertStatus(201);

        // A decision without a reason is one nobody dares reverse six months
        // later.
        expect(SyncIgnore::first()->note)->toBe('Our own monitoring box');
    });

    it('treats a second dismissal as the same decision', function () {
        $payload = ['resource_type' => 'application', 'resource_key' => 'metrics.internal'];

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync/ignores', $payload)->assertStatus(201);

        // The unique index would otherwise turn a double-click into a 500.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync/ignores', $payload)->assertStatus(201);

        expect(SyncIgnore::count())->toBe(1);
    });

    it('refuses a resource type that does not exist', function () {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync/ignores', [
                'resource_type' => 'not_a_thing',
                'resource_key' => 'x',
            ])
            // A typo here would create an ignore that silently matches
            // nothing, forever.
            ->assertJsonValidationErrors('resource_type');
    });

    it('can be undone', function () {
        $ignore = SyncIgnore::create(['resource_type' => 'application', 'resource_key' => 'metrics.internal']);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/server/sync/ignores/{$ignore->id}")
            ->assertNoContent();

        expect(SyncIgnore::count())->toBe(0);
    });

    it('lets a viewer read the list but not change it', function () {
        $viewer = User::factory()->create();
        grantPermission($viewer, 'sync', view: true, manage: false);
        $token = $viewer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/server/sync/ignores')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/server/sync/ignores', [
                'resource_type' => 'application', 'resource_key' => 'x',
            ])->assertForbidden();
    });
});

/*
 * Firewall rules — the only opt-in resource.
 *
 * Every other adoption leaves the server exactly as it was. This one
 * populates the screen someone will later use to *change* the firewall, and a
 * half-imported rule list is a worse starting point for that than an empty
 * one, because it looks complete.
 */
describe('discovering firewall rules', function () {
    function fakeUfwForSync(string $status): void
    {
        Process::fake(function ($process) use ($status) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'ufw') {
                return Process::result(output: $status);
            }

            if (($args[0] ?? '') === 'getent') {
                return Process::result(output: '');
            }

            return Process::result(exitCode: 1, errorOutput: 'nothing');
        });
    }

    function ufwRun(string $status): SyncRun
    {
        fakeUfwForSync($status);

        return runSync(SyncMode::Preview, ['include_firewall' => true]);
    }

    it('does nothing at all unless asked for', function () {
        fakeUfwForSync("[ 1] 22/tcp                     ALLOW IN    Anywhere\n");

        // Opt-in means opt-in. Not a line saying it was skipped either — the
        // caller chose, and repeating their choice back is noise on the
        // screen that matters.
        expect(runSync()->items()->where('resource_type', 'firewall_rule')->count())->toBe(0);
    });

    it('reads a port rule', function () {
        $run = ufwRun("Status: active\n\n[ 1] 3306                       ALLOW IN    203.0.113.5\n");

        $item = $run->items()->where('resource_type', 'firewall_rule')->first();

        expect($item->action)->toBe(SyncAction::Found)
            ->and($item->evidence['from'])->toBe('203.0.113.5');
    });

    it('does not import the same rule twice for IPv6', function () {
        // ufw lists the v6 half as its own numbered entry. The panel stores
        // one row per rule, so adopting both would double every rule.
        $run = ufwRun(implode("\n", [
            '[ 1] 22/tcp                     ALLOW IN    Anywhere',
            '[ 2] 22/tcp (v6)                ALLOW IN    Anywhere (v6)',
        ]));

        expect($run->items()->where('resource_type', 'firewall_rule')->count())->toBe(1);
    });

    it('refuses a LIMIT rule rather than calling it an allow', function () {
        $run = ufwRun("[ 1] 22/tcp                     LIMIT IN    Anywhere\n");

        $item = $run->items()->where('resource_type', 'firewall_rule')->first();

        // Rate limiting is not allowing. Recording it as one would misstate
        // what the server does, in the direction nobody notices.
        expect($item->action)->toBe(SyncAction::Skipped)
            ->and($item->reason)->toBe('firewall_action_unsupported');
    });

    it('refuses an outbound rule', function () {
        $run = ufwRun("[ 1] 25/tcp                     DENY OUT    Anywhere\n");

        // The panel's rules are inbound only; recorded here it would be
        // applied in the wrong direction.
        expect($run->items()->where('resource_type', 'firewall_rule')->first()->reason)
            ->toBe('firewall_direction_unsupported');
    });

    it('refuses an application profile', function () {
        $run = ufwRun("[ 1] Nginx Full                 ALLOW IN    Anywhere\n");

        // The ports behind a profile live in /etc/ufw/applications.d and can
        // change when the package updates. Today's numbers would be a
        // snapshot pretending to be the rule.
        expect($run->items()->where('resource_type', 'firewall_rule')->first()->reason)
            ->toBe('firewall_app_profile');
    });

    it('adopts a rule as in force, because it is', function () {
        fakeUfwForSync("[ 1] 8000:9000/tcp              ALLOW IN    Anywhere\n");

        runSync(SyncMode::Apply, ['include_firewall' => true]);

        $rule = FirewallRule::firstOrFail();

        expect($rule->port_from)->toBe(8000)
            ->and($rule->port_to)->toBe(9000)
            ->and($rule->protocol)->toBe('tcp')
            // Anywhere is no restriction, not a literal source.
            ->and($rule->source_ip)->toBeNull()
            // Recording it disabled would describe a firewall with holes it
            // does not have.
            ->and($rule->enabled)->toBeTrue()
            // Made by hand on the server, not the panel's seeded set — the
            // badge and the delete protection both key off this.
            ->and($rule->origin)->toBe('user');
    });

    it('is safe to run twice', function () {
        fakeUfwForSync("[ 1] 22/tcp                     ALLOW IN    Anywhere\n");

        runSync(SyncMode::Apply, ['include_firewall' => true]);
        runSync(SyncMode::Apply, ['include_firewall' => true]);

        expect(FirewallRule::count())->toBe(1);
    });
});

describe('a run nothing is going to finish', function () {
    beforeEach(function () {
        Queue::fake();
    });

    it('refuses a new scan while a run really is going', function () {
        SyncRun::create(['mode' => SyncMode::Preview, 'status' => SyncStatus::Running, 'started_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync', ['mode' => 'preview'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sync');
    });

    it('starts anyway when the previous run was abandoned', function () {
        // `pending` and old: the job was never picked up, which is a queue
        // worker that is not running. `failed()` never fires for this, so the
        // row used to sit here and refuse every later scan forever.
        $abandoned = SyncRun::create(['mode' => SyncMode::Preview, 'status' => SyncStatus::Pending]);
        $abandoned->forceFill(['created_at' => now()->subSeconds(SyncRun::STALE_AFTER + 1)])->save();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync', ['mode' => 'preview'])
            ->assertStatus(202);

        expect($abandoned->fresh()->status)->toBe(SyncStatus::Failed)
            ->and($abandoned->fresh()->finished_at)->not->toBeNull();
        Queue::assertPushed(RunServerSync::class);
    });

    it('does not retire a run that is merely slow', function () {
        $slow = SyncRun::create([
            'mode' => SyncMode::Preview,
            'status' => SyncStatus::Running,
            'started_at' => now()->subSeconds(SyncRun::STALE_AFTER - 60),
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/server/sync', ['mode' => 'preview'])
            ->assertUnprocessable();

        expect($slow->fresh()->status)->toBe(SyncStatus::Running);
    });

    it('stops the watching screen spinning on an abandoned run', function () {
        $abandoned = SyncRun::create(['mode' => SyncMode::Preview, 'status' => SyncStatus::Running]);
        $abandoned->forceFill(['started_at' => now()->subSeconds(SyncRun::STALE_AFTER + 1)])->save();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/server/sync/{$abandoned->id}")
            ->assertOk()
            ->assertJsonPath('sync.status', 'failed');
    });

    it('reports an abandoned run on the reopened screen', function () {
        $abandoned = SyncRun::create(['mode' => SyncMode::Preview, 'status' => SyncStatus::Pending]);
        $abandoned->forceFill(['created_at' => now()->subSeconds(SyncRun::STALE_AFTER + 1)])->save();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/server/sync/latest')
            ->assertOk()
            ->assertJsonPath('sync.status', 'failed');
    });
});

/*
 * Discovering sites on OpenLiteSpeed.
 *
 * A separate describe because the layout is genuinely different, and the
 * difference is what broke it: nginx and Apache keep one config file per site
 * in one directory, OpenLiteSpeed keeps a *directory* per site each holding a
 * `vhconf.conf`. The old `find -maxdepth 1 -type f` over its vhost root matched
 * nothing, so a server full of sites synced clean and reported none — the worst
 * possible shape for this feature, because "no sites found" is also what a
 * correct run on an empty box looks like.
 */
describe('discovering sites on OpenLiteSpeed', function () {
    beforeEach(function () {
        ServerCapability::query()->delete();
        ServerCapability::query()->create([
            'stack' => 'ols',
            'web_server' => 'openlitespeed',
            'capabilities' => ['php' => true, 'node' => true],
            'source' => 'installer',
            'verified_at' => now(),
        ]);

        $this->run = SyncRun::create(['status' => 'running', 'started_at' => now()]);
        $this->owner = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    });

    function fakeOlsVhosts(array $vhosts, string $owner = 'siteowner'): void
    {
        Process::fake(function ($process) use ($vhosts, $owner) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $binary = $args[0] ?? '';

            if ($binary === 'getent') {
                return Process::result(output: "siteowner:x:1001:1001::/home/siteowner:/bin/bash\n");
            }

            // The real shape: two levels down, and always called vhconf.conf.
            if ($binary === 'find') {
                return Process::result(output: implode("\n", array_map(
                    fn (string $name): string => "/usr/local/lsws/conf/vhosts/{$name}/vhconf.conf",
                    array_keys($vhosts),
                )));
            }

            if ($binary === 'cat') {
                $path = (string) ($args[1] ?? '');

                foreach ($vhosts as $name => $contents) {
                    if (str_contains($path, "/vhosts/{$name}/")) {
                        return Process::result(output: $contents);
                    }
                }

                return Process::result(exitCode: 1, errorOutput: 'No such file');
            }

            if ($binary === 'stat') {
                return Process::result(output: $owner);
            }

            return Process::result(exitCode: 0);
        });
    }

    it('reads vhDomain, vhAliases and docRoot', function () {
        fakeOlsVhosts(['shop' => implode("\n", [
            'docRoot                   /home/siteowner/shop/public_html',
            'vhDomain                  shop.example.com',
            'vhAliases                 www.shop.example.com, shop.example.net',
            'enableGzip                1',
        ])]);

        $found = app(ApplicationDiscoverer::class)->discover($this->run);

        expect($found)->toHaveCount(1)
            ->and($found[0]['key'])->toBe('shop.example.com')
            // Comma-separated, unlike nginx's spaces. Splitting on whitespace
            // alone would have produced one domain called "www.shop.example.com,".
            ->and($found[0]['attributes']['domains'])
            ->toBe(['shop.example.com', 'www.shop.example.com', 'shop.example.net'])
            ->and($found[0]['attributes']['document_root'])->toBe('/home/siteowner/shop/public_html');
    });

    it('names the site after its directory, not after vhconf.conf', function () {
        // Every OpenLiteSpeed site's file has the same basename, so deriving
        // the name from the file would give every site on the box the name
        // "vhconf" — and both the already-tracked check and the exclusion list
        // are keyed on it, so one adopted site would suppress all the others.
        Application::factory()->create(['slug' => 'shop', 'domain' => 'shop.example.com']);

        fakeOlsVhosts([
            'shop' => "docRoot /home/siteowner/shop/public_html\nvhDomain shop.example.com\n",
            'blog' => "docRoot /home/siteowner/blog/public_html\nvhDomain blog.example.com\n",
        ]);

        $found = app(ApplicationDiscoverer::class)->discover($this->run);

        // `shop` is already tracked and drops out by slug; `blog` survives.
        expect($found)->toHaveCount(1)
            ->and($found[0]['key'])->toBe('blog.example.com');
    });
});
