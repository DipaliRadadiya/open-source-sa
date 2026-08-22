<?php

/*
 * Every binary the code shells out to has to be one the panel may elevate.
 *
 * This is the check that would have caught three separate live bugs, and it is
 * cheap. `ServerOps::elevate()` prefixes `sudo` only for binaries in
 * `server.privilege.binaries`; anything else runs as the unprivileged panel
 * account, hits a directory owned by a site user, and fails with "permission
 * denied" — on a feature that looks entirely configured.
 *
 * Nothing else finds it. The suite fakes `Process`, so a faked run returns
 * success whether or not `sudo` was prefixed: the tests prove the panel issues
 * the right command, never that it is allowed to. `mysqldump` was missing and
 * broke every MySQL backup; `touch` was missing and broke cron creation,
 * chunked uploads and git provisioning; `openssl` was missing, which is every
 * self-signed certificate.
 *
 * Deliberately a *source scan* rather than a list someone maintains by hand —
 * a second hand-maintained list would drift from the first, which is the
 * problem, not the fix.
 */

/**
 * Binaries the panel runs *without* elevation, on purpose.
 *
 * Listed rather than silently skipped, so that adding one is a decision
 * somebody made rather than a gap nobody noticed. Both of these read
 * world-readable state — `ip route` asks the kernel which interface reaches the
 * internet, and pool files under /etc/php are 0644 — so elevating them would
 * widen the sudoers grant to buy nothing.
 */
const RUNS_UNPRIVILEGED = ['ip', 'grep'];

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

        // `run(['binary', …])` and `run($ctx, ['binary', …])` — the literal
        // first element of a command array. Interpolated binaries (the PHP
        // stack's versioned paths, config-driven clients) are deliberately not
        // matched: they are not literals to check, and `php-fpm*` has its own
        // rule in elevate().
        // The negative lookahead for `=>` is what keeps the *context* array
        // out of the results — `run($cmd, ['feature' => 'application', …])`
        // has the same shape as a command and is not one.
        if (preg_match_all("/run\(\s*(?:\\\$[a-zA-Z]+,\s*)?\[\s*'([a-z0-9_.-]+)'\s*(?!=>)[,\]]/", $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $binary) {
            // php-fpm8.4, php-fpm8.3 … one binary per installed PHP version.
            // elevate() matches them by prefix for the same reason install.sh
            // grants them by wildcard: an exact list would need editing every
            // time a version is added through the panel's own feature.
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
 * The scan above cannot see these, and that blind spot is exactly where the
 * worst one hid: `certbot` is named by `certificates.certbot`, was installed by
 * install.sh, was never granted, and so **every Let's Encrypt issuance ran
 * unprivileged against /etc/letsencrypt** — a whole feature that could not work
 * on any real server, with a green test suite over it.
 *
 * This list is the residual manual part, and it is deliberately small: a config
 * key only belongs here when its value is the *first element* of a command.
 * `composer_binary` and `node_binary` are not, and must not be added — they are
 * arguments to `runuser` and `env`, which carry the elevation themselves. Add
 * one here and you would be granting sudo to something that never asks for it.
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
 * The allowlist the panel checks and the sudoers rule the installer writes have
 * to agree, or the panel asks for a privilege the server never granted — and
 * `sudo` answers "a password is required" to a process that cannot supply one.
 *
 * This used to parse the list back out of install.sh, because install.sh had a
 * list to parse. It no longer does: it renders the file with `artisan
 * panel:sudoers --print`, so the two cannot disagree by construction and there
 * is nothing left to compare. What is worth asserting is that it still does
 * that — a well-meaning change putting a literal list back into the installer
 * would restore the whole class of bug silently, and every test here would
 * pass.
 */
it('leaves the grant to the panel rather than repeating it in install.sh', function () {
    $installer = base_path('../install.sh');

    if (! is_file($installer)) {
        $this->markTestSkipped('install.sh is not in this checkout');
    }

    $source = (string) file_get_contents($installer);

    expect($source)->toContain('artisan panel:sudoers --print');

    // Nothing that looks like a second copy of the list. Matched on any run of
    // absolute binary paths rather than on the old `bins=(` spelling, so a
    // reintroduction under a different variable name is caught too.
    preg_match_all('#(?:/usr(?:/local)?/s?bin/[a-z0-9_.*-]+[ ,]+){3,}#', $source, $runs);

    expect($runs[0])->toBe([], 'install.sh appears to carry its own binary list again');
});
