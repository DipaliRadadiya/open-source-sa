<?php

use App\Enums\SyncAction;
use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Jobs\RunServerSync;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SshKey;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Models\User;
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

        foreach (['useradd', 'usermod', 'tee', 'rm', 'chmod', 'chown', 'systemctl'] as $binary) {
            Process::assertNotRan(fn ($p) => in_array($binary, (array) $p->command, true));
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
        fakeVhosts([
            'panel' => nginxVhost('panel.example.com', '/var/www/panel/public'),
            'shop' => nginxVhost('shop.example.com'),
        ]);

        $keys = $ru = runSync()->items()->where('resource_type', 'application')->pluck('resource_key')->all();

        // Adopting the control plane would put it in the list of sites the
        // user can rename, move or delete.
        expect($keys)->toBe(['shop.example.com']);
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
