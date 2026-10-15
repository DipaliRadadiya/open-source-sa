<?php

use App\Services\Server\AppShell\AppShellFiles;
use App\Services\Server\AppShell\AppShellInstaller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as RealProcess;

/**
 * Run something for real: Process is faked in this file, and the validators
 * below are only worth anything if they actually run.
 *
 * @param  list<string>  $command
 */
function runForReal(array $command): RealProcess
{
    $process = new RealProcess($command);
    $process->run();

    return $process;
}

/**
 * Putting container login on a server (RC-28): the group, the root helper, the
 * login wrapper, one sudoers rule and the sshd Match block.
 *
 * Two of those can lock people out of a remote server, so they are checked by
 * the real validators below, and the install order is asserted: nothing moves
 * into /etc/sudoers.d before visudo accepts it, and sshd is never reloaded on
 * a config that failed `sshd -t`.
 */
beforeEach(function () {
    $this->onDisk = [];
    $this->fail = [];
    $this->ran = [];
    $this->inputs = [];

    Process::fake(function ($process) {
        $args = $process->command;
        $this->ran[] = $args;
        $this->inputs[] = [$args, $process->input];

        foreach ($this->fail as $prefix) {
            if (array_slice($args, 0, count($prefix)) === $prefix) {
                return Process::result(exitCode: 1, errorOutput: 'refused');
            }
        }

        if ($args[0] === 'cat') {
            return array_key_exists($args[1], $this->onDisk)
                ? Process::result(output: $this->onDisk[$args[1]])
                : Process::result(exitCode: 1, errorOutput: 'No such file or directory');
        }

        return Process::result(exitCode: 0);
    });
});

function shellInstalledFiles(): array
{
    $files = app(AppShellFiles::class);

    return [
        $files->helperPath() => $files->helper(),
        $files->loginPath() => $files->login(),
        $files->sudoersPath() => $files->sudoers(),
        $files->sshdPath() => $files->sshd(),
    ];
}

function shellRanIndex(array $command): int
{
    foreach (test()->ran as $i => $args) {
        if ($args === $command) {
            return $i;
        }
    }

    return -1;
}

describe('rendered files', function () {
    it('grants the group exactly one command, the helper', function () {
        $sudoers = app(AppShellFiles::class)->sudoers();

        expect(collect(explode("\n", $sudoers))->reject(fn ($line) => $line === '' || str_starts_with($line, '#'))->values()->all())
            ->toBe(['%sv-app-shell ALL=(root) NOPASSWD: /usr/local/libexec/panel/app-shell'])
            ->and($sudoers)->not->toContain('Defaults');
    });

    it('writes a sudoers rule visudo accepts', function () {
        $visudo = collect(['/usr/sbin/visudo', '/usr/bin/visudo'])->first(fn ($path) => is_executable($path));

        if ($visudo === null) {
            $this->markTestSkipped('visudo is not installed here');
        }

        $file = tempnam(sys_get_temp_dir(), 'sudoers');
        file_put_contents($file, app(AppShellFiles::class)->sudoers());

        $check = runForReal([$visudo, '-cqf', $file]);
        @unlink($file);

        expect($check->isSuccessful())->toBeTrue($check->getErrorOutput());
    })->skip(fn () => ! is_executable('/usr/sbin/visudo'), 'visudo is not installed here');

    it('forces the login wrapper and turns every kind of forwarding off for the group', function () {
        expect(app(AppShellFiles::class)->sshd())->toContain(implode("\n", [
            'Match Group sv-app-shell',
            '    ForceCommand /usr/local/libexec/panel/app-login',
            '    DisableForwarding yes',
            '    AllowTcpForwarding no',
            '    AllowStreamLocalForwarding no',
            '    X11Forwarding no',
            '    AllowAgentForwarding no',
            '    PermitTunnel no',
            '    GatewayPorts no',
            '    PermitUserRC no',
        ]));
    });

    it('is accepted by sshd and applies to group members only', function () {
        // A real sshd, on a throwaway config laid out the way Ubuntu's is: the
        // drop-in directory included at the top, global settings after it. The
        // block is rendered for the group the test runs as, so `sshd -T` can
        // show what a member gets; root, outside it, must get nothing.
        $dir = sys_get_temp_dir().'/rc28-sshd-'.bin2hex(random_bytes(4));
        mkdir($dir.'/d', 0700, true);
        runForReal(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $dir.'/hostkey']);

        $group = posix_getgrgid(posix_getegid())['name'];
        $user = posix_getpwuid(posix_geteuid())['name'];
        config()->set('server.app_shell.group', $group);

        file_put_contents($dir.'/sshd_config', "Include {$dir}/d/*.conf\nHostKey {$dir}/hostkey\nX11Forwarding yes\nAllowTcpForwarding yes\n");
        file_put_contents($dir.'/d/01-panel-app-shell.conf', app(AppShellFiles::class)->sshd());

        $test = runForReal(['/usr/sbin/sshd', '-t', '-f', $dir.'/sshd_config']);
        $member = runForReal(['/usr/sbin/sshd', '-T', '-f', $dir.'/sshd_config', '-C', "user={$user},host=localhost,addr=127.0.0.1"])->getOutput();
        $root = runForReal(['/usr/sbin/sshd', '-T', '-f', $dir.'/sshd_config', '-C', 'user=root,host=localhost,addr=127.0.0.1'])->getOutput();

        runForReal(['rm', '-rf', $dir]);

        expect($test->isSuccessful())->toBeTrue($test->getErrorOutput())
            ->and($member)->toContain("forcecommand /usr/local/libexec/panel/app-login\n")
            ->and($member)->toContain("allowtcpforwarding no\n")
            ->and($member)->toContain("x11forwarding no\n")
            ->and($member)->toContain("permituserrc no\n")
            ->and($root)->toContain("forcecommand none\n")
            ->and($root)->toContain("allowtcpforwarding yes\n")
            ->and($root)->toContain("x11forwarding yes\n");
    })->skip(fn () => ! is_executable('/usr/sbin/sshd') || ! function_exists('posix_getegid'), 'sshd is not installed here');

    it('hands the original command to the helper as one argument, through sudo -n', function () {
        expect(app(AppShellFiles::class)->login())
            ->toStartWith("#!/bin/sh\n")
            ->toContain('exec /usr/bin/sudo -n /usr/local/libexec/panel/app-shell "${SSH_ORIGINAL_COMMAND-}"');
    });

    it('runs the helper on the panel\'s own PHP with no ini, reading the configured map', function () {
        config()->set('panel_update.php_binary', '/usr/bin/php8.4');
        config()->set('server.app_shell.map', '/etc/elsewhere/map.json');

        $helper = app(AppShellFiles::class)->helper();

        expect($helper)->toStartWith("#!/usr/bin/php8.4 -n\n<?php")
            ->toContain("const MAP = '/etc/elsewhere/map.json';")
            ->not->toContain("const MAP = '/etc/panel-app-shell/map.json';");
    });

    it('refuses paths that are not plain absolute paths', function (string $key, string $value) {
        config()->set("server.app_shell.{$key}", $value);

        expect(app(AppShellFiles::class)->configured())->toBeFalse();
    })->with([
        ['helper', ''],
        ['helper', 'relative/app-shell'],
        ['login', '/usr/local/libexec/panel/app login'],
        ['sudoers', '/etc/sudoers.d/panel.app-shell'],
        ['map', '/etc/../tmp/map.json'],
        ['group', 'Bad Group'],
    ]);
});

describe('install', function () {
    it('lays everything down on a server that has none of it', function () {
        expect(app(AppShellInstaller::class)->ensure())->toBe([
            'group' => 'unchanged', 'helper' => 'updated', 'login' => 'updated', 'sudoers' => 'updated', 'sshd' => 'updated',
        ]);

        $sudoers = '/etc/sudoers.d/panel-app-shell';
        $sshd = '/etc/ssh/sshd_config.d/01-panel-app-shell.conf';

        expect(shellRanIndex(['groupadd', '-f', 'sv-app-shell']))->toBe(0)
            // Helper and wrapper: root-owned and executable before they move in.
            ->and(shellRanIndex(['chown', 'root:root', '/usr/local/libexec/panel/app-shell.new']))->toBeGreaterThan(0)
            ->and(shellRanIndex(['chmod', '0755', '/usr/local/libexec/panel/app-shell.new']))
            ->toBeLessThan(shellRanIndex(['mv', '-f', '/usr/local/libexec/panel/app-shell.new', '/usr/local/libexec/panel/app-shell']))
            // Sudoers: 0440, then visudo, then the rename — in that order.
            ->and(shellRanIndex(['chmod', '0440', "{$sudoers}.new"]))->toBeLessThan(shellRanIndex(['visudo', '-cqf', "{$sudoers}.new"]))
            ->and(shellRanIndex(['visudo', '-cqf', "{$sudoers}.new"]))->toBeLessThan(shellRanIndex(['mv', '-f', "{$sudoers}.new", $sudoers]))
            // sshd: the file, then the check, then the reload.
            ->and(shellRanIndex(['tee', $sshd]))->toBeLessThan(shellRanIndex(['sshd', '-t']))
            ->and(shellRanIndex(['sshd', '-t']))->toBeLessThan(shellRanIndex(['systemctl', 'try-reload-or-restart', 'ssh']));
    });

    it('changes nothing and reloads nothing when everything is current', function () {
        $this->onDisk = shellInstalledFiles();

        expect(app(AppShellInstaller::class)->ensure())->toBe([
            'group' => 'unchanged', 'helper' => 'unchanged', 'login' => 'unchanged', 'sudoers' => 'unchanged', 'sshd' => 'unchanged',
        ]);

        expect(collect($this->ran)->map(fn ($args) => $args[0])->unique()->values()->all())->toBe(['groupadd', 'cat']);
    });

    it('never moves a sudoers rule visudo refused into place', function () {
        $this->fail = [['visudo']];

        expect(app(AppShellInstaller::class)->ensure()['sudoers'])->toBe('failed')
            ->and(shellRanIndex(['mv', '-f', '/etc/sudoers.d/panel-app-shell.new', '/etc/sudoers.d/panel-app-shell']))->toBe(-1)
            ->and(shellRanIndex(['rm', '-f', '/etc/sudoers.d/panel-app-shell.new']))->toBeGreaterThan(0);
    });

    it('takes a block sshd refused back out, and does not reload', function () {
        $this->fail = [['sshd', '-t']];

        expect(app(AppShellInstaller::class)->ensure()['sshd'])->toBe('failed')
            ->and(shellRanIndex(['rm', '-f', '/etc/ssh/sshd_config.d/01-panel-app-shell.conf']))->toBeGreaterThan(0)
            ->and(shellRanIndex(['systemctl', 'try-reload-or-restart', 'ssh']))->toBe(-1);
    });

    it('puts the previous block back when an update of it is refused', function () {
        $sshd = '/etc/ssh/sshd_config.d/01-panel-app-shell.conf';
        $this->onDisk = [$sshd => "Match Group sv-app-shell\n    ForceCommand /old\n"];
        $this->fail = [['sshd', '-t']];

        app(AppShellInstaller::class)->ensure();

        $writes = collect($this->inputs)->filter(fn ($pair) => $pair[0] === ['tee', $sshd])->pluck(1)->values();

        expect($writes)->toHaveCount(2)
            ->and($writes[1])->toBe("Match Group sv-app-shell\n    ForceCommand /old\n")
            ->and(shellRanIndex(['systemctl', 'try-reload-or-restart', 'ssh']))->toBe(-1);
    });

    it('answers sshd -T for one user: is the wrapper forced on them?', function () {
        Process::fake(['*' => Process::result(output: "user blogger\nforcecommand /usr/local/libexec/panel/app-login\n")]);
        expect(app(AppShellInstaller::class)->enforcedFor('blogger'))->toBeTrue();

        Process::fake(['*' => Process::result(output: "user blogger\nforcecommand none\n")]);
        expect(app(AppShellInstaller::class)->enforcedFor('blogger'))->toBeFalse();
    });
});

describe('containers:sync-shell-access', function () {
    it('installs, writes the map and says so', function () {
        expect(Artisan::call('containers:sync-shell-access'))->toBe(0);

        expect(Artisan::output())->toContain('sudoers  updated')->toContain('map      0 app(s)');
    });

    it('fails, without hiding it, when a part could not be installed', function () {
        $this->fail = [['visudo']];

        expect(Artisan::call('containers:sync-shell-access'))->toBe(1)
            ->and(Artisan::output())->toContain('sudoers  failed');
    });

    it('runs in the panel update right after the sudo grant is rewritten, and never fails it', function () {
        $script = file_get_contents(app_path('Services/Panel/UpdateScript.php'));

        expect($script)->toContain("note configure_app_shell\n        {\$asUser}{\$php} {\$backend}/artisan containers:sync-shell-access || echo")
            ->and(strpos($script, 'note sync_privileges'))->toBeLessThan(strpos($script, 'note configure_app_shell'));
    });

    it('runs at install, after the sudo grant exists', function () {
        $install = file_get_contents(base_path('../install.sh'));

        expect($install)->toContain("artisan containers:sync-shell-access' -- \"\$backend\"")
            ->and(strpos($install, "\n    configure_sudoers\n"))->toBeLessThan(strpos($install, "\n    finish\n"));
    });
});
