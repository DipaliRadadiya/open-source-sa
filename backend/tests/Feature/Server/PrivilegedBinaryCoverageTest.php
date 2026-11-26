<?php

/*
 * Every binary the code shells out to has to be one the panel may elevate.
 *
 * `ServerOps::elevate()` prefixes `sudo` only for binaries in `server.privilege.binaries`;
 * anything else runs as the unprivileged panel account and fails with "permission denied" on a
 * feature that looks fully configured.
 *
 * Nothing else finds this: the suite fakes `Process`, so a faked run succeeds whether or not
 * `sudo` was prefixed. A missing `mysqldump`, `touch` or `openssl` would break MySQL backups,
 * cron creation or every self-signed certificate.
 *
 * Deliberately a *source scan*, not a hand-kept list that would drift from the real one.
 */

/**
 * Binaries the panel runs *without* elevation, on purpose.
 *
 * Listed rather than skipped, so adding one is a decision. Both read world-readable state
 * (`ip route`, pool files under /etc/php), so elevating them would widen sudoers for nothing.
 */
// `apt-config` reads apt.conf.d, which is world-readable: no root needed.
const RUNS_UNPRIVILEGED = ['ip', 'grep', 'apt-config'];

it('elevates every binary the code actually runs', function () {
    $allowed = array_merge(
        (array) config('server.privilege.binaries', []),
        RUNS_UNPRIVILEGED,
    );

    $called = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // `run(['binary', …])` and `run($ctx, ['binary', …])`: the literal first element of a
        // command array. Interpolated binaries (versioned PHP paths, config-driven clients) are not
        // literals to check; `php-fpm*` has its own rule in elevate().
        // The `=>` lookahead keeps the *context* array, `run($cmd, ['feature' => 'application'])`,
        // out of the results: same shape as a command, but not one.
        if (preg_match_all("/run\(\s*(?:\\\$[a-zA-Z]+,\s*)?\[\s*'([a-z0-9_.-]+)'\s*(?!=>)[,\]]/", $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $binary) {
            // php-fpm8.4, php-fpm8.3 …: one binary per installed PHP version. elevate() matches
            // them by prefix (install.sh grants them by wildcard) so no list needs editing per version.
            if (str_starts_with($binary, 'php-fpm')) {
                continue;
            }

            $called[$binary][] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    // `sudo` is elevate()'s own prefix, not something a caller asks for.
    unset($called['sudo']);

    $missing = array_diff_key($called, array_flip($allowed));

    $detail = implode('; ', array_map(
        fn (string $binary, array $files) => $binary.' ('.implode(', ', array_unique($files)).')',
        array_keys($missing),
        $missing,
    ));

    expect($missing)->toBe([], "not in server.privilege.binaries: {$detail}");
});

/**
 * The binaries that reach `command[0]` from config rather than as a literal.
 *
 * The scan above cannot see these; `certbot` (named by `certificates.certbot`) hid here, so
 * Let's Encrypt issuance would run unprivileged against /etc/letsencrypt.
 *
 * Deliberately small: a config key belongs here only when its value is the *first element* of a
 * command. `composer_binary` and `node_binary` are arguments to `runuser`/`env`, which carry the
 * elevation themselves; adding them would grant sudo to something that never asks for it.
 */
const BINARY_CONFIG_KEYS = [
    'server.certificates.certbot',
    'server.databases.engines.mysql.client',
    'server.databases.engines.mysql.dump_client',
    'server.databases.engines.mariadb.client',
    'server.databases.engines.mariadb.dump_client',
    'server.databases.engines.mongodb.client',
    'server.databases.engines.mongodb.dump_client',
    'server.databases.engines.mongodb.restore_client',
    'server.installers.wordpress.wp_cli',
    'server.fail2ban.client',
];

it('elevates the binaries that come from config too', function () {
    $allowed = (array) config('server.privilege.binaries', []);

    $missing = [];

    foreach (BINARY_CONFIG_KEYS as $key) {
        $value = config($key);

        if (blank($value)) {
            continue;
        }

        $binary = basename((string) $value);

        if (! in_array($binary, $allowed, true) && ! str_starts_with($binary, 'php-fpm')) {
            $missing[$key] = $binary;
        }
    }

    $detail = implode(', ', array_map(
        fn (string $key, string $binary) => "{$binary} ({$key})",
        array_keys($missing),
        $missing,
    ));

    expect($missing)->toBe([], "config names a binary the panel will not elevate: {$detail}");
});

/**
 * The same blind spot, for config values that are whole *commands*.
 *
 * The check above reads a config key as a single binary path; these keys hold an argv array, so
 * `basename()` on them is useless and the source scan cannot see them either.
 *
 * Elevation is matched on the basename, so the assertion below is paired with the path one that
 * follows: `lswsctrl` was allowlisted, but the grant named `/usr/bin/lswsctrl` while OpenLiteSpeed
 * installs at `/usr/local/lsws/bin/lswsctrl`, and every restart ran unprivileged.
 *
 * `config_tests` is read from config, so a service added there is covered automatically.
 */
const COMMAND_CONFIG_KEYS = [
    'server.web_server_drivers.openlitespeed.test_command',
    'server.web_server_drivers.openlitespeed.reload_command',
    'server.php_stacks.lsphp.reload_command',
];

it('elevates the binaries that come from config as whole commands', function () {
    $allowed = (array) config('server.privilege.binaries', []);

    $commands = [];

    foreach (COMMAND_CONFIG_KEYS as $key) {
        $commands[$key] = (array) config($key, []);
    }

    foreach ((array) config('server.config_tests', []) as $service => $command) {
        $commands["server.config_tests.{$service}"] = (array) $command;
    }

    $missing = [];

    foreach ($commands as $key => $command) {
        $path = (string) ($command[0] ?? '');

        if ($path === '') {
            continue;
        }

        $binary = basename($path);

        if (! in_array($binary, $allowed, true) && ! str_starts_with($binary, 'php-fpm')) {
            $missing[$key] = $binary;

            continue;
        }

        // An absolute path in the command must be one the grant names, or sudo refuses it.
        // Binaries with no `paths` entry default to /usr/bin.
        if (! str_contains($path, '/')) {
            continue;
        }

        $granted = (array) (config('server.privilege.paths')[$binary] ?? ['/usr/bin/'.$binary]);

        if (! in_array($path, $granted, true)) {
            $missing[$key] = $binary.' at '.$path.' (granted: '.implode(', ', $granted).')';
        }
    }

    $detail = implode(', ', array_map(
        fn (string $key, string $binary) => "{$binary} ({$key})",
        array_keys($missing),
        $missing,
    ));

    expect($missing)->toBe([], "config names a command the panel will not elevate: {$detail}");
});

/**
 * The panel's allowlist and the sudoers rule the installer writes must agree, or the panel asks
 * for a privilege the server never granted and `sudo` answers "a password is required".
 *
 * install.sh renders the file with `artisan panel:sudoers --print`, so the two agree by
 * construction. What is asserted is that it still does: a literal list put back into the
 * installer would restore the whole class of bug silently.
 */
it('leaves the grant to the panel rather than repeating it in install.sh', function () {
    $installer = base_path('../install.sh');

    if (! is_file($installer)) {
        $this->markTestSkipped('install.sh is not in this checkout');
    }

    $source = (string) file_get_contents($installer);

    expect($source)->toContain('artisan panel:sudoers --print');

    // Nothing that looks like a second copy of the list: matched on any run of absolute binary
    // paths, so a reintroduction under a different variable name is caught too.
    preg_match_all('#(?:/usr(?:/local)?/s?bin/[a-z0-9_.*-]+[ ,]+){3,}#', $source, $runs);

    expect($runs[0])->toBe([], 'install.sh appears to carry its own binary list again');
});

it('never reloads a web server it may have just told the operator to stop', function () {
    // On a re-install, the thing holding port 80 is the web server being configured, so the user
    // stops it, and `systemctl reload` on a stopped unit fails ("cannot reload"). reload-or-restart
    // works in both states, so no bare reload of nginx or apache2 may come back.
    $installer = base_path('../install.sh');

    if (! is_file($installer)) {
        $this->markTestSkipped('install.sh is not in this checkout');
    }

    preg_match_all('/systemctl reload (nginx|apache2)\b/', (string) file_get_contents($installer), $bare);

    expect($bare[0])->toBe([], 'bare reload(s) in install.sh: '.implode(', ', $bare[0]));
});

/**
 * apt goes through `ServerOps::apt()`, never `run()`.
 *
 * The difference is a wait budget: `run()` retries a held lock three times, 1.5s apart, which
 * suits a passwd lock but is far too short for apt's (a freshly booted server runs
 * unattended-upgrades for minutes). A call on `run()` fails on a busy server and works on a quiet one.
 *
 * A source scan, because the suite fakes `Process` and a faked apt returns instantly either way.
 */
const APT_WITHOUT_WAITING = [
    // A simulation run when the disk-cleaner screen opens: apt()'s long lock wait would hang the
    // page just to show a number. Its `clean()` does wait.
    'Services/Server/DiskCleaner/Targets/AptOrphansTarget.php',
];

it('routes every apt command through the waiting wrapper', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // `->run([... 'apt-get' ...])`, allowing for array_merge and newlines
        // between the call and the binary.
        $relative = str_replace(app_path().'/', '', $file->getPathname());

        if (in_array($relative, APT_WITHOUT_WAITING, true)) {
            continue;
        }

        if (preg_match("/->run\(\s*(?:array_merge\(\s*)?\[\s*'apt-get'/", $source) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'apt through run() instead of apt(): '.implode(', ', $offenders));
});
