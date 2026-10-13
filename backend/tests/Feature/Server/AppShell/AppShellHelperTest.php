<?php

use function PanelAppShell\argv;
use function PanelAppShell\plan;
use function PanelAppShell\unsafe;

/**
 * The root helper's decisions (RC-28): what an app user's SSH command turns
 * into. These are the real functions from resources/app-shell/helper.php,
 * loaded as a library, so the file installed on servers is the file tested.
 *
 * The helper runs as root for users who are not administrators. Everything it
 * decides comes from two inputs — who sudo says the caller is, and the map —
 * and the user's text only ever becomes the `sh -c` string inside the
 * container.
 */
if (! defined('PANEL_APP_SHELL_LIBRARY')) {
    define('PANEL_APP_SHELL_LIBRARY', true);
}

require_once dirname(__DIR__, 4).'/resources/app-shell/helper.php';

function shellMap(): array
{
    return ['version' => 1, 'apps' => [
        ['app' => 'openclaw', 'id' => 3, 'owner' => 'adarsh-oc', 'project' => 'sv-app-3', 'containers' => [
            ['service' => 'openclaw', 'name' => 'sv-app-3-openclaw-1', 'main' => true],
        ]],
        ['app' => 'ghost', 'id' => 4, 'owner' => 'blogger', 'project' => 'sv-app-4', 'containers' => [
            ['service' => 'ghost', 'name' => 'sv-app-4-ghost-1', 'main' => true],
            ['service' => 'db', 'name' => 'sv-app-4-db-1', 'main' => false],
        ]],
        ['app' => 'shop', 'id' => 5, 'owner' => 'many', 'project' => 'sv-app-5', 'containers' => [
            ['service' => 'web', 'name' => 'shop-web', 'main' => true],
            ['service' => 'worker', 'name' => 'shop-worker', 'main' => false],
        ]],
        ['app' => 'wiki', 'id' => 6, 'owner' => 'many', 'project' => 'sv-app-6', 'containers' => [
            ['service' => 'wiki', 'name' => 'sv-app-6-wiki-1', 'main' => true],
        ]],
    ]];
}

describe('one app', function () {
    it('opens a shell in the main container for an empty command', function () {
        expect(plan('blogger', shellMap(), ''))->toBe([
            'action' => 'shell', 'app' => 'ghost', 'container' => 'sv-app-4-ghost-1',
        ]);
    });

    it('runs a plain command in the main container', function () {
        expect(plan('adarsh-oc', shellMap(), 'openclaw devices list'))->toBe([
            'action' => 'exec', 'app' => 'openclaw', 'container' => 'sv-app-3-openclaw-1', 'command' => 'openclaw devices list',
        ]);
    });

    it('opens another container of the app with @service', function () {
        expect(plan('blogger', shellMap(), '@db'))->toBe([
            'action' => 'shell', 'app' => 'ghost', 'container' => 'sv-app-4-db-1',
        ]);
    });

    it('runs a command in another container with @service cmd, keeping the text as typed', function () {
        expect(plan('blogger', shellMap(), "@db mysql -e 'show databases'"))->toBe([
            'action' => 'exec', 'app' => 'ghost', 'container' => 'sv-app-4-db-1', 'command' => "mysql -e 'show databases'",
        ]);
    });

    it('accepts @app and @app/service for a single app too', function () {
        expect(plan('blogger', shellMap(), '@ghost')['container'])->toBe('sv-app-4-ghost-1')
            ->and(plan('blogger', shellMap(), '@ghost/db uptime'))->toMatchArray(['container' => 'sv-app-4-db-1', 'command' => 'uptime']);
    });

    it('lists the caller\'s own apps and nothing else with @list', function () {
        $plan = plan('blogger', shellMap(), '@list');

        expect($plan['action'])->toBe('list')
            ->and($plan['message'])->toBe("ghost\n  ghost  (main)\n  db\n")
            ->and($plan['message'])->not->toContain('openclaw');
    });
});

describe('@logs', function () {
    it('tails the main container by default', function () {
        expect(plan('blogger', shellMap(), '@logs'))->toBe([
            'action' => 'logs', 'app' => 'ghost', 'container' => 'sv-app-4-ghost-1', 'tail' => 200, 'follow' => false,
        ]);
    });

    it('takes a service, --tail and --follow in any order', function () {
        expect(plan('blogger', shellMap(), '@logs --follow db --tail 20'))->toBe([
            'action' => 'logs', 'app' => 'ghost', 'container' => 'sv-app-4-db-1', 'tail' => 20, 'follow' => true,
        ]);
    });

    it('refuses anything that is not a count, a flag it knows or one target', function (string $command) {
        expect(plan('blogger', shellMap(), $command))->toMatchArray(['action' => 'refuse', 'exit' => 2]);
    })->with([
        '@logs --tail lots',
        '@logs --tail 999999999',
        '@logs --since 1h',
        '@logs db ghost',
        '@logs --tail',
    ]);
});

describe('several apps', function () {
    it('prints the list and exits 2 when nothing picks one', function (string $command) {
        $plan = plan('many', shellMap(), $command);

        expect($plan)->toMatchArray(['action' => 'refuse', 'exit' => 2])
            ->and($plan['message'])->toContain("shop\n  web  (main)\n  worker\nwiki\n  wiki  (main)\n");
    })->with(['', 'ls -la', '@logs']);

    it('picks with @app and @app/service', function () {
        expect(plan('many', shellMap(), '@wiki'))->toMatchArray(['action' => 'shell', 'container' => 'sv-app-6-wiki-1'])
            ->and(plan('many', shellMap(), '@shop/worker php artisan queue:restart'))->toMatchArray([
                'action' => 'exec', 'app' => 'shop', 'container' => 'shop-worker', 'command' => 'php artisan queue:restart',
            ])
            ->and(plan('many', shellMap(), '@logs shop/worker --tail 5'))->toMatchArray(['container' => 'shop-worker', 'tail' => 5]);
    });

    it('does not accept a bare @service, which could mean any of the apps', function () {
        expect(plan('many', shellMap(), '@worker'))->toMatchArray(['action' => 'refuse', 'exit' => 1, 'message' => "not your app\n"]);
    });
});

describe('refusals', function () {
    it('refuses another user\'s app the same way as one that does not exist', function (string $command) {
        expect(plan('blogger', shellMap(), $command))->toBe(['action' => 'refuse', 'exit' => 1, 'message' => "not your app\n"]);
    })->with([
        '@openclaw',
        '@openclaw openclaw devices list',
        '@shop/web',
        '@logs openclaw',
        '@nope',
        '@../etc',
        '@ghost/../../db',
        '@ghost/db;id',
        '@$(id)',
        '@-u',
    ]);

    it('refuses a caller with no Docker app', function () {
        expect(plan('stranger', shellMap(), ''))->toMatchArray(['action' => 'refuse', 'exit' => 1]);
    });

    it('refuses sftp and legacy scp, which arrive as commands', function (string $command) {
        expect(plan('blogger', shellMap(), $command))->toMatchArray(['action' => 'refuse', 'exit' => 1]);
    })->with([
        '/usr/lib/openssh/sftp-server',
        'internal-sftp',
        'scp -t /tmp',
        'scp -f /etc/passwd',
    ]);

    it('ignores map entries whose names are not names', function () {
        $map = ['apps' => [
            ['app' => 'ok', 'owner' => 'u', 'containers' => [
                ['service' => 'web', 'name' => '--privileged', 'main' => true],
                ['service' => 'db', 'name' => 'ok-db-1', 'main' => false],
            ]],
            ['app' => '../x', 'owner' => 'u', 'containers' => [['service' => 'a', 'name' => 'a', 'main' => true]]],
        ]];

        expect(plan('u', $map, ''))->toMatchArray(['action' => 'shell', 'app' => 'ok', 'container' => 'ok-db-1']);
    });
});

describe('what reaches docker', function () {
    it('passes injection attempts to the container as the sh -c string and nothing else', function (string $command) {
        $plan = plan('adarsh-oc', shellMap(), $command);

        expect($plan['action'])->toBe('exec')
            ->and(argv('/usr/bin/docker', $plan, false))
            ->toBe(['/usr/bin/docker', 'exec', '-i', 'sv-app-3-openclaw-1', 'sh', '-c', '--', $command]);
    })->with([
        '--privileged',
        '-u root sh',
        '+x',
        'ls; cat /etc/shadow',
        'echo $(id)',
        '`id`',
        "x' && touch /tmp/pwned && echo '",
    ]);

    it('opens bash when the image has it, sh otherwise, with -t only on a terminal', function () {
        $plan = plan('blogger', shellMap(), '');

        expect(argv('/usr/bin/docker', $plan, true))->toBe([
            '/usr/bin/docker', 'exec', '-i', '-t', 'sv-app-4-ghost-1', 'sh', '-c', '--',
            'if command -v bash >/dev/null 2>&1; then exec bash; fi; exec sh',
        ])->and(argv('/usr/bin/docker', $plan, false))->not->toContain('-t');
    });

    it('builds docker logs from fixed flags only', function () {
        expect(argv('/usr/bin/docker', plan('blogger', shellMap(), '@logs db --tail 20 -f'), false))
            ->toBe(['/usr/bin/docker', 'logs', '--tail', '20', '-f', 'sv-app-4-db-1'])
            ->and(argv('/usr/bin/docker', plan('blogger', shellMap(), '@logs'), false))
            ->toBe(['/usr/bin/docker', 'logs', '--tail', '200', 'sv-app-4-ghost-1']);
    });
});

describe('containers it will not open', function () {
    it('refuses a privileged container', function () {
        expect(unsafe(['HostConfig' => ['Privileged' => true], 'State' => ['Running' => true]]))->toContain('privileged');
    });

    it('refuses a container holding the Docker socket, however it is mounted', function (array $inspect) {
        expect(unsafe([...$inspect, 'State' => ['Running' => true]]))->toContain('Docker socket');
    })->with([
        'mount' => [['Mounts' => [['Source' => '/var/run/docker.sock', 'Destination' => '/var/run/docker.sock']]]],
        'renamed inside' => [['Mounts' => [['Source' => '/run/docker.sock', 'Destination' => '/sock/docker.sock']]]],
        'bind' => [['HostConfig' => ['Binds' => ['/var/run/docker.sock:/tmp/d.sock:ro']]]],
    ]);

    it('refuses a stopped container and accepts an ordinary running one', function () {
        expect(unsafe(['State' => ['Running' => false]]))->toContain('not running')
            ->and(unsafe([
                'HostConfig' => ['Privileged' => false, 'Binds' => ['/home/u/site/data:/data']],
                'Mounts' => [['Source' => '/home/u/site/data', 'Destination' => '/data']],
                'State' => ['Running' => true],
            ]))->toBeNull();
    });
});
