<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationLogDirectory;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

/**
 * Every log a site produces, in one directory: `{appRoot}/logs`.
 *
 * They used to be in four places — the web server's under `/var/log/nginx` or
 * `/var/log/apache2`, the process logs already here, and the PHP error and WAF
 * detect logs inside `.panel`. So "where are my logs" depended on which web
 * server the box was built with and which kind of log was being asked about,
 * and two of the four were readable only by root.
 *
 * The ownership is the part worth guarding: root owns the directory, the site
 * user is the group. The owner of a site can read every log; nothing running
 * as that user can unlink one and put a symlink in its place for a root
 * process to append into.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create([
        'username' => 'siteowner', 'home_path' => '/home/siteowner',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->site = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'php_version' => '8.4', 'status' => 'active', 'web_root' => '/',
    ]);
});

it('puts every log for a site in one directory', function () {
    expect($this->site->logsPath())->toBe('/home/siteowner/shop/logs');
});

it('agrees across all three web servers', function () {
    // The whole point: the answer must not depend on which web server the
    // server happens to run. Before this, nginx and Apache answered
    // /var/log/... and only OpenLiteSpeed used the site's own directory.
    $expected = [
        'access' => '/home/siteowner/shop/logs/access.log',
        'error' => '/home/siteowner/shop/logs/error.log',
    ];

    expect(app(NginxDriver::class)->logPaths($this->site))->toBe($expected)
        ->and(app(ApacheDriver::class)->logPaths($this->site))->toBe($expected)
        ->and(app(OlsDriver::class)->logPaths($this->site))->toBe($expected);
});

it('keeps the PHP error log with the other logs, not inside .panel', function () {
    // `.panel` is the panel's own bookkeeping and is deliberately unreadable
    // by the site's owner — right for the Basic Auth credential, wrong for the
    // one file a developer whose site is throwing 500s needs most.
    $path = app(PoolManager::class)->errorLogPath($this->site);

    expect($path)->toBe('/home/siteowner/shop/logs/php-error.log')
        ->and($path)->not->toContain('.panel');
});

it('keeps the WAF detect log with the other logs, defined once', function () {
    // Two places used to build this string by hand and drifted: the file the
    // panel opened was not the file the web server wrote, so detect mode
    // showed an empty log however much it had matched.
    expect($this->site->wafDetectLogPath())->toBe('/home/siteowner/shop/logs/waf-detect.log')
        ->and($this->site->wafDetectLogPath())->not->toContain('.panel');
});

it('has the process supervisor and the model agree on the directory', function () {
    expect(ProcessSupervisor::logDir($this->site))->toBe($this->site->logsPath());
});

it('owns the directory as root with the site user as group', function () {
    // The security property. The site user must be able to *read* every log —
    // that is the reason they moved out of /var/log — and must not be able to
    // *unlink* one, because these files are opened by root: nginx's master,
    // systemd's PID 1, php-fpm's master. Directory write permission is
    // permission to unlink, so a user-owned log directory lets a compromised
    // site swap access.log for a symlink and have root append request text
    // into whatever it points at.
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $commands->push(implode(' ', (array) $process->command));

        return Process::result(exitCode: 0);
    });

    app(ApplicationLogDirectory::class)->ensure($this->site);

    $joined = $commands->join("\n");

    expect($joined)->toContain('mkdir -p /home/siteowner/shop/logs')
        ->and($joined)->toContain('chown root:siteowner /home/siteowner/shop/logs')
        ->and($joined)->toContain('chmod 0750 /home/siteowner/shop/logs')
        // Never the site user as owner — that is the whole distinction.
        ->and($joined)->not->toContain('chown siteowner:siteowner /home/siteowner/shop/logs');
});

it('creates the log directory before writing a vhost that names it', function () {
    // A web server refuses to start when a log file's directory is missing, so
    // this ordering is what stops `nginx -t` failing on every site and reading
    // as a bad template rather than an absent folder.
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $commands->push(implode(' ', (array) $process->command));

        return Process::result(exitCode: 0);
    });

    app(NginxDriver::class)->apply($this->site, $this->site->documentRoot());

    $mkdir = $commands->search(fn (string $c) => str_contains($c, 'mkdir -p /home/siteowner/shop/logs'));
    $write = $commands->search(fn (string $c) => str_contains($c, 'shop.conf'));

    expect($mkdir)->not->toBeFalse()
        ->and($write)->not->toBeFalse()
        ->and($mkdir)->toBeLessThan($write);
});

it('renders a vhost that logs where the driver says it does', function () {
    // The template and `logPaths()` are two separate statements of the same
    // fact. If they disagree, fail2ban and the Logs screen watch a file
    // nothing writes — which is exactly what a jail that never bans looks like.
    $config = app(NginxDriver::class)->renderConfig($this->site, $this->site->documentRoot());
    $paths = app(NginxDriver::class)->logPaths($this->site);

    expect($config)->toContain("access_log {$paths['access']};")
        ->and($config)->toContain("error_log  {$paths['error']};")
        ->and($config)->not->toContain('/var/log/nginx/shop');
});

/*
|--------------------------------------------------------------------------
| Who is allowed to write into the log directory
|--------------------------------------------------------------------------
|
| `root:{site user} 0750` rests on a premise its own docblock states: "every
| writer here is a root master process handing a descriptor down". nginx and
| Apache do exactly that. OpenLiteSpeed does not — its workers run as `nobody`
| and open the vhost's own logs — so on that stack the directory could not be
| traversed and nothing was ever written.
|
| Measured on a live OpenLiteSpeed box: two requests answered 200 while
| access.log stayed at zero bytes, and the per-site fail2ban jail watching
| that file could therefore never ban anyone.
*/

/** Point the panel at a given web server for the duration of a test. */
function serverRuns(string $webServer, string $stack): void
{
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => $stack, 'web_server' => $webServer,
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);
}

/** Record every command, answering `cat httpd_config.conf` with a real-ish file. */
function recordCommands(Collection $commands, string $olsUser = 'nobody'): void
{
    Process::fake(function ($process) use ($commands, $olsUser) {
        $command = (array) $process->command;
        $commands->push(implode(' ', $command));

        if (($command[0] ?? null) === 'cat' && str_contains($command[1] ?? '', 'httpd_config')) {
            // Shaped like the real file: the `user` directive this reads sits
            // at the top level, and a `listener Default{` block has to exist
            // for a vhost to be registered at all.
            return Process::result(output: <<<CONF
                serverName                Example
                user                      {$olsUser}
                group                     nogroup

                listener Default{
                  address                 *:80
                  secure                  0
                }
                CONF);
        }

        return Process::result(exitCode: 0);
    });
}

it('admits the web server account on OpenLiteSpeed, where the workers open the log', function () {
    serverRuns('openlitespeed', 'ols');
    $commands = collect();
    recordCommands($commands);

    app(ApplicationLogDirectory::class)->ensure($this->site);

    expect($commands->join("\n"))->toContain('gpasswd -a nobody siteowner');
});

it('admits nobody on nginx or Apache, whose root master opens the log', function () {
    // The negative control, and the more important half. This change must not
    // loosen a directory on the two stacks that were already correct.
    foreach ([['nginx', 'lemp'], ['apache', 'lamp']] as [$webServer, $stack]) {
        serverRuns($webServer, $stack);
        $commands = collect();
        recordCommands($commands);

        app(ApplicationLogDirectory::class)->ensure($this->site);

        // One needle, no second argument. `toContain` is variadic, so a string
        // meant as a failure message is read as another needle — and
        // `not->toContain('gpasswd', 'a message')` passes while `gpasswd` is
        // right there in the haystack. This assertion checked nothing until a
        // revert check caught it.
        expect($commands->join("\n"))->not->toContain('gpasswd');
    }
});

it('still refuses the site user write access to its own log directory', function () {
    // The property the whole design exists for, re-asserted on the stack that
    // now has an extra member in the group. Group membership is read access;
    // the mode is what stops a compromised site unlinking access.log and
    // leaving a symlink for a privileged process to append into.
    serverRuns('openlitespeed', 'ols');
    $commands = collect();
    recordCommands($commands);

    app(ApplicationLogDirectory::class)->ensure($this->site);

    $joined = $commands->join("\n");

    expect($joined)->toContain('chmod 0750 /home/siteowner/shop/logs')
        ->and($joined)->toContain('chown root:siteowner /home/siteowner/shop/logs')
        // The cheaper fix that was deliberately not taken: `o+x` would let
        // every local account read every site's access log.
        ->and($joined)->not->toContain('chmod 0751')
        ->and($joined)->not->toContain('chown siteowner:siteowner /home/siteowner/shop/logs');
});

it('reads the web server account from the file that decides it', function () {
    // Not from `server.web_server_user`, which defaults to www-data, is never
    // written by install.sh, and is therefore wrong on every OpenLiteSpeed
    // server. A grant to an account that does not run the web server looks
    // exactly like a grant that worked.
    serverRuns('openlitespeed', 'ols');
    config(['server.web_server_user' => 'www-data']);
    $commands = collect();
    recordCommands($commands, olsUser: 'lsadm');

    app(ApplicationLogDirectory::class)->ensure($this->site);

    expect($commands->join("\n"))->toContain('gpasswd -a lsadm siteowner')
        ->and($commands->join("\n"))->not->toContain('gpasswd -a www-data siteowner');
});

it('reads the server account, not a vhost\'s own extUser', function () {
    // A server migrated into the panel can carry inline `virtualHost` blocks
    // in the shared config, and every one of them names an `extUser` — the
    // *site's* account. Matching that instead of the top-level `user` would
    // return `siteowner` here, and `admitLogWriter()` skips a grant when the
    // account is already the group. So the fix would quietly become a no-op
    // on exactly the migrated servers most likely to need it, and the only
    // symptom would be an access log that stays empty.
    //
    // ⚠️ What actually keeps them apart is the capital U, not the column-zero
    // anchor. Dropping `^` from the pattern leaves this test green, because
    // `extUser` does not contain lowercase `user`. Reported rather than
    // papered over: the anchor is the tighter reading and worth keeping, but
    // it is this test's *case sensitivity* that is load-bearing — a pattern
    // loosened to `\w*[Uu]ser` fails here, and one loosened to `^\s*` does
    // not. Measured, both ways.
    serverRuns('openlitespeed', 'ols');
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $command = (array) $process->command;
        $commands->push(implode(' ', $command));

        if (($command[0] ?? null) === 'cat' && str_contains($command[1] ?? '', 'httpd_config')) {
            // `extUser` first and indented, `user` after it and at column
            // zero — the order that catches an unanchored match.
            return Process::result(output: <<<'CONF'
                serverName                Example

                virtualHost legacy {
                  vhRoot                  /home/siteowner/legacy/
                  extUser                 siteowner
                }

                user                      nobody
                group                     nogroup

                listener Default{
                  address                 *:80
                }
                CONF);
        }

        return Process::result(exitCode: 0);
    });

    app(ApplicationLogDirectory::class)->ensure($this->site);

    expect($commands->join("\n"))->toContain('gpasswd -a nobody siteowner');
});

it('grants the membership before the restart that would have to pick it up', function () {
    // 🔴 The correctness of the whole fix. Supplementary groups are read when
    // a process starts, so a membership added after the web server is running
    // does nothing until it restarts. Measured live: the grant alone left
    // access.log at zero bytes; `lswsctrl restart` made the next request
    // appear in it.
    serverRuns('openlitespeed', 'ols');
    $commands = collect();
    recordCommands($commands);

    // The order the provisioner uses: the `write_config` step calls apply(),
    // and the `reload` step follows it. The grant belongs inside the first,
    // and this fails if it is ever moved after the second.
    $driver = app(OlsDriver::class);
    $driver->apply($this->site, $this->site->documentRoot());
    $driver->reload();

    $grant = $commands->search(fn (string $c) => str_contains($c, 'gpasswd -a nobody siteowner'));
    $restart = $commands->search(fn (string $c) => str_contains($c, 'lswsctrl restart'));

    expect($grant)->not->toBeFalse('the grant never ran during apply()')
        ->and($restart)->not->toBeFalse('the web server was never restarted')
        ->and($grant)->toBeLessThan($restart);
});
