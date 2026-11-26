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
 * Every log a site produces lives in one directory: `{appRoot}/logs`.
 *
 * Ownership is the part to guard: root owns the directory, the site user is the group. The site
 * owner can read every log, but nothing running as that user can unlink one and plant a symlink
 * for a root process to append into.
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
    // The answer must not depend on which web server the box runs.
    $expected = [
        'access' => '/home/siteowner/shop/logs/access.log',
        'error' => '/home/siteowner/shop/logs/error.log',
    ];

    // nginx and Apache also write the HTTPS block to `-ssl` files; OpenLiteSpeed has one pair of logs.
    $ssl = [
        'access_ssl' => '/home/siteowner/shop/logs/access-ssl.log',
        'error_ssl' => '/home/siteowner/shop/logs/error-ssl.log',
    ];

    expect(app(NginxDriver::class)->logPaths($this->site))->toBe($expected + $ssl)
        ->and(app(ApacheDriver::class)->logPaths($this->site))->toBe($expected + $ssl)
        ->and(app(OlsDriver::class)->logPaths($this->site))->toBe($expected);
});

it('keeps the PHP error log with the other logs, not inside .panel', function () {
    // `.panel` is deliberately unreadable by the site owner, who needs this log most.
    $path = app(PoolManager::class)->errorLogPath($this->site);

    expect($path)->toBe('/home/siteowner/shop/logs/php-error.log')
        ->and($path)->not->toContain('.panel');
});

it('keeps the WAF detect log with the other logs, defined once', function () {
    // Built in one place: a hand-built copy drifts, and detect mode then shows an empty log.
    expect($this->site->wafDetectLogPath())->toBe('/home/siteowner/shop/logs/waf-detect.log')
        ->and($this->site->wafDetectLogPath())->not->toContain('.panel');
});

it('has the process supervisor and the model agree on the directory', function () {
    expect(ProcessSupervisor::logDir($this->site))->toBe($this->site->logsPath());
});

it('owns the directory as root with the site user as group', function () {
    // The site user must read every log but not unlink one: root processes (nginx master, systemd,
    // php-fpm master) open them, and a user-owned directory lets a compromised site swap
    // access.log for a symlink and have root append request text into its target.
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
        // Never the site user as owner.
        ->and($joined)->not->toContain('chown siteowner:siteowner /home/siteowner/shop/logs');
});

it('creates the log directory before writing a vhost that names it', function () {
    // A web server refuses to start when a log directory is missing, so `nginx -t` would fail
    // on every site and look like a bad template.
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
    // The template and `logPaths()` state the same fact; if they disagree, fail2ban and the Logs
    // screen watch a file nothing writes.
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
| `root:{site user} 0750` assumes every writer is a root master process handing a descriptor
| down. nginx and Apache do; OpenLiteSpeed's workers run as `nobody` and open the logs
| themselves, so on that stack the directory could not be traversed, nothing was written, and
| the per-site fail2ban jail watching access.log could never ban anyone.
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
            // Shaped like the real file: a top-level `user` directive and a `listener Default{`
            // block, which a vhost needs in order to be registered.
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
    // Negative control: the directory must not loosen on the two stacks that were already correct.
    foreach ([['nginx', 'lemp'], ['apache', 'lamp']] as [$webServer, $stack]) {
        serverRuns($webServer, $stack);
        $commands = collect();
        recordCommands($commands);

        app(ApplicationLogDirectory::class)->ensure($this->site);

        // One needle only: `toContain` is variadic, so a second string is read as another needle
        // and `not->toContain('gpasswd', 'a message')` passes with `gpasswd` in the haystack.
        expect($commands->join("\n"))->not->toContain('gpasswd');
    }
});

it('still refuses the site user write access to its own log directory', function () {
    // Group membership is read access; the mode is what stops a compromised site unlinking
    // access.log and leaving a symlink for a privileged process to append into.
    serverRuns('openlitespeed', 'ols');
    $commands = collect();
    recordCommands($commands);

    app(ApplicationLogDirectory::class)->ensure($this->site);

    $joined = $commands->join("\n");

    expect($joined)->toContain('chmod 0750 /home/siteowner/shop/logs')
        ->and($joined)->toContain('chown root:siteowner /home/siteowner/shop/logs')
        // `o+x` is deliberately not used: it would let every local account read every access log.
        ->and($joined)->not->toContain('chmod 0751')
        ->and($joined)->not->toContain('chown siteowner:siteowner /home/siteowner/shop/logs');
});

it('reads the web server account from the file that decides it', function () {
    // Not `server.web_server_user`: it defaults to www-data, install.sh never writes it, and it is
    // wrong on OpenLiteSpeed.
    serverRuns('openlitespeed', 'ols');
    config(['server.web_server_user' => 'www-data']);
    $commands = collect();
    recordCommands($commands, olsUser: 'lsadm');

    app(ApplicationLogDirectory::class)->ensure($this->site);

    expect($commands->join("\n"))->toContain('gpasswd -a lsadm siteowner')
        ->and($commands->join("\n"))->not->toContain('gpasswd -a www-data siteowner');
});

it('reads the server account, not a vhost\'s own extUser', function () {
    // A migrated config can hold inline `virtualHost` blocks, each naming an `extUser` (the site's
    // account). Matching that instead of the top-level `user` would return `siteowner`, and
    // `admitLogWriter()` skips a grant when the account is already the group, so the log stays empty.
    //
    // Case sensitivity is what keeps them apart, not the `^` anchor: dropping `^` leaves this test
    // green, while loosening the pattern to `\w*[Uu]ser` fails it.
    serverRuns('openlitespeed', 'ols');
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $command = (array) $process->command;
        $commands->push(implode(' ', $command));

        if (($command[0] ?? null) === 'cat' && str_contains($command[1] ?? '', 'httpd_config')) {
            // `extUser` first and indented, `user` after it at column zero: catches an unanchored match.
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
    // Supplementary groups are read at process start, so a membership added to a running web
    // server does nothing until it restarts.
    serverRuns('openlitespeed', 'ols');
    $commands = collect();
    recordCommands($commands);

    // Provisioner order: `write_config` calls apply(), then `reload` follows. The grant belongs in
    // the first; this fails if it moves after the second.
    $driver = app(OlsDriver::class);
    $driver->apply($this->site, $this->site->documentRoot());
    $driver->reload();

    $grant = $commands->search(fn (string $c) => str_contains($c, 'gpasswd -a nobody siteowner'));
    $restart = $commands->search(fn (string $c) => str_contains($c, 'lswsctrl restart'));

    expect($grant)->not->toBeFalse('the grant never ran during apply()')
        ->and($restart)->not->toBeFalse('the web server was never restarted')
        ->and($grant)->toBeLessThan($restart);
});
