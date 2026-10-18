<?php

/*
 * install.sh's configure_redis(), run for real against a redis-cli that
 * answers the way Redis 7 does for whatever redis.conf says.
 *
 * Why (2026-10-18, step 14): a server whose redis.conf already had
 * `user default on nopass ~* &* +@all` from an old CONFIG REWRITE. The
 * installer appended `requirepass`, which that ACL line overrides, and then
 * took `redis-cli -a … ping` answering PONG as proof — but redis-cli prints
 * "AUTH failed" and carries on, and the PING works without a password. The
 * panel's .env got a password Redis rejected, and creating the administrator
 * failed with "AUTH <password> called without any password configured".
 */

/**
 * A redis-cli for redis.conf at $conf, as Redis 7 reads it: `requirepass`
 * first, then the default user's ACL line on top (reset, then its rules left
 * to right). Every command line it is called with goes to $dir/argv.
 */
function fakeRedisCli(string $dir, string $conf): void
{
    mkdir($dir.'/bin', 0777, true);

    $stub = <<<'PHP'
<?php
file_put_contents(__DIR__.'/../argv', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);

$conf = (string) @file_get_contents(getenv('FAKE_REDIS_CONF'));
$nopass = true;
$hashes = [];
$acl = null;
foreach (preg_split('/\R/', $conf) as $line) {
    $t = preg_split('/\s+/', trim($line));
    if ($t[0] === 'requirepass' && isset($t[1])) {
        $nopass = false;
        $hashes = [hash('sha256', $t[1])];
    }
    if ($t[0] === 'user' && ($t[1] ?? '') === 'default') {
        $acl = array_slice($t, 2);
    }
}
if ($acl !== null) {
    $nopass = false;
    $hashes = [];
    foreach ($acl as $rule) {
        if ($rule === 'nopass') { $nopass = true; $hashes = []; }
        elseif ($rule === 'resetpass' || $rule === 'reset') { $nopass = false; $hashes = []; }
        elseif ($rule[0] === '>') { $nopass = false; $hashes[] = hash('sha256', substr($rule, 1)); }
        elseif ($rule[0] === '#') { $nopass = false; $hashes[] = substr($rule, 1); }
        elseif ($rule[0] === '<') { $hashes = array_values(array_diff($hashes, [hash('sha256', substr($rule, 1))])); }
        elseif ($rule[0] === '!') { $hashes = array_values(array_diff($hashes, [substr($rule, 1)])); }
    }
}

$args = array_slice($argv, 1);
$auth = getenv('REDISCLI_AUTH');
$i = array_search('-a', $args, true);
if ($i !== false) { $auth = $args[$i + 1]; }

$in = $nopass;
if ($auth !== false && $auth !== '') {
    if ($nopass) {
        fwrite(STDERR, "AUTH failed: ERR AUTH <password> called without any password configured for the default user. Are you sure your configuration is correct?\n");
    } elseif (in_array(hash('sha256', $auth), $hashes, true)) {
        $in = true;
    } else {
        fwrite(STDERR, "AUTH failed: WRONGPASS invalid username-password pair or user is disabled.\n");
    }
}
echo $in ? "PONG\n" : "NOAUTH Authentication required.\n";
PHP;

    file_put_contents($dir.'/bin/redis-cli.php', $stub);
    file_put_contents($dir.'/bin/redis-cli', "#!/bin/sh\nFAKE_REDIS_CONF=".escapeshellarg($conf).' exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($dir.'/bin/redis-cli.php')." \"\$@\"\n");
    chmod($dir.'/bin/redis-cli', 0755);
}

/**
 * configure_redis() on this redis.conf (and the panel .env, when given).
 *
 * @return array{out: string, conf: string, password: string, cache: string, argv: string, dir: string}
 */
function runConfigureRedis(string $conf, ?string $panelEnv = null): array
{
    $dir = sys_get_temp_dir().'/installer-redis-'.bin2hex(random_bytes(4));
    mkdir($dir.'/app/backend', 0777, true);
    file_put_contents($dir.'/redis.conf', $conf);
    if ($panelEnv !== null) {
        file_put_contents($dir.'/app/backend/.env', $panelEnv);
    }
    fakeRedisCli($dir, $dir.'/redis.conf');

    $script = 'set -Eeuo pipefail
        '.installerFunctions('redis_default_acl', 'redis_set_default_password', 'redis_password_works', 'configure_redis').'
        step() { :; }; ok() { echo "ok: $*"; }; skip() { echo "skip: $*"; }; warn() { echo "warn: $*"; }; run() { :; }
        PATH='.escapeshellarg($dir.'/bin').':$PATH; APP_DIR='.escapeshellarg($dir.'/app').'; REDACT=(); CACHE_STORE=""
        configure_redis '.escapeshellarg($dir.'/redis.conf').'
        echo "PASSWORD=$REDIS_PASSWORD"; echo "CACHE=$CACHE_STORE"; echo "REDACTED=${#REDACT[@]}"';
    exec('bash -c '.escapeshellarg($script).' 2>&1', $out, $code);
    $said = implode("\n", $out);

    expect($code)->toBe(0, $said);
    preg_match('/^PASSWORD=(.*)$/m', $said, $pw);
    preg_match('/^CACHE=(.*)$/m', $said, $cache);

    return [
        'out' => $said,
        'conf' => (string) file_get_contents($dir.'/redis.conf'),
        'password' => $pw[1] ?? '',
        'cache' => $cache[1] ?? '',
        'argv' => (string) @file_get_contents($dir.'/argv'),
        'dir' => $dir,
    ];
}

/** What the fake Redis answers a PING with, with and without this password. */
function fakePing(string $dir, ?string $password): string
{
    $env = $password === null ? 'env -u REDISCLI_AUTH' : 'REDISCLI_AUTH='.escapeshellarg($password);
    exec($env.' '.escapeshellarg($dir.'/bin/redis-cli').' ping 2>&1', $out);

    return implode("\n", $out);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/installer-redis-*') ?: [] as $dir) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

/** The 2026-10-18 server's redis.conf, plus a second user the installer must leave alone. */
function nopassRedisConf(): string
{
    return "bind 127.0.0.1 -::1\nport 6379\nuser default on nopass ~* &* +@all\nuser reports on #aaaa ~reports:* +get\n";
}

describe('the default user\'s ACL line', function () {
    it('is read the way Redis applies it, left to right', function (string $conf, string $expected) {
        $dir = sys_get_temp_dir().'/installer-redis-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/redis.conf', $conf);

        exec('bash -c '.escapeshellarg(installerFunctions('redis_default_acl').' redis_default_acl '.escapeshellarg($dir.'/redis.conf')), $out);

        expect(implode('', $out))->toBe($expected);
    })->with([
        'what CONFIG REWRITE writes for an open Redis' => ["user default on nopass ~* &* +@all\n", 'nopass'],
        'a password, then nopass' => ["user default on >secret nopass ~* +@all\n", 'nopass'],
        'nopass, then a password' => ["user default on nopass >secret ~* +@all\n", 'password'],
        'a hash' => ["user default on #5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8 ~* +@all\n", 'password'],
        'nopass, then resetpass' => ["user default on nopass resetpass ~* +@all\n", 'none'],
        'another user\'s nopass' => ["user reports on nopass ~* +@all\n", ''],
        'commented out' => ["# user default on nopass ~* &* +@all\n", ''],
        'no ACL line' => ["requirepass secret\n", ''],
    ]);
});

describe('configure_redis', function () {
    it('gives an open default user a password Redis really applies (the 2026-10-18 server)', function () {
        $r = runConfigureRedis(nopassRedisConf());

        expect($r['password'])->toHaveLength(32)
            ->and($r['cache'])->toBe('redis')
            ->and($r['out'])->toContain('had no password')
            ->and($r['out'])->not->toContain('warn:');

        // Proven against Redis's own rules, not just written down.
        expect(fakePing($r['dir'], $r['password']))->toBe('PONG')
            ->and(fakePing($r['dir'], null))->toBe('NOAUTH Authentication required.');

        $hash = hash('sha256', $r['password']);
        expect($r['conf'])->toContain("user default on ~* &* +@all resetpass #{$hash}\n")
            ->toContain("# user default on nopass ~* &* +@all\n")
            ->toContain("\nrequirepass {$r['password']}\n")
            // Another user's line is not the installer's business.
            ->toContain("user reports on #aaaa ~reports:* +get\n")
            ->and(preg_match_all('/^user default /m', $r['conf']))->toBe(1);
    });

    it('gives it the requirepass already there, on a re-run after the appended line did nothing', function () {
        $r = runConfigureRedis(nopassRedisConf()."\n# Added by the Control panel installer\nrequirepass Left-By-The-Failed-Run-0123456789\n");

        expect($r['password'])->toBe('Left-By-The-Failed-Run-0123456789')
            ->and($r['cache'])->toBe('redis')
            ->and(substr_count($r['conf'], 'requirepass '))->toBe(1)
            ->and($r['conf'])->toContain('#'.hash('sha256', 'Left-By-The-Failed-Run-0123456789'))
            ->and(fakePing($r['dir'], null))->toBe('NOAUTH Authentication required.');
    });

    it('leaves a Redis that already has a working password exactly as it is', function () {
        $conf = "port 6379\nmaxmemory 100mb\nrequirepass Old-Requirepass-000\nuser default on #".hash('sha256', 'Live-Panel-Password-1')." ~* &* +@all\n";
        $r = runConfigureRedis($conf, "APP_NAME=Panel\nREDIS_PASSWORD=\"Live-Panel-Password-1\"\n");

        expect($r['out'])->toContain('skip: Redis already has a password')
            ->and($r['password'])->toBe('Live-Panel-Password-1')
            ->and($r['cache'])->toBe('redis')
            ->and($r['conf'])->toBe($conf);
    });

    it('generates one for a Redis with no password rules at all', function () {
        $r = runConfigureRedis("port 6379\n");

        expect($r['password'])->toHaveLength(32)
            ->and($r['cache'])->toBe('redis')
            ->and($r['conf'])->toContain("requirepass {$r['password']}\n")
            ->and($r['conf'])->not->toContain('user default')
            ->and(fakePing($r['dir'], null))->toBe('NOAUTH Authentication required.');
    });

    it('writes no password Redis rejects: it falls back to the database cache', function () {
        $conf = "port 6379\nmaxmemory 100mb\nrequirepass Stale-Requirepass-000\nuser default on #".hash('sha256', 'Nobody-Knows-This-1')." ~* &* +@all\n";
        $r = runConfigureRedis($conf, "REDIS_PASSWORD=Stale-Panel-Password-1\n");

        expect($r['password'])->toBe('')
            ->and($r['cache'])->toBe('database')
            ->and($r['out'])->toContain('warn: Redis did not accept a password the panel can use')
            ->and($r['conf'])->toBe($conf);
    });

    it('never puts a password on redis-cli\'s command line', function () {
        $r = runConfigureRedis(nopassRedisConf());

        expect($r['argv'])->not->toBe('')
            ->and($r['argv'])->not->toContain($r['password'])
            ->and($r['argv'])->not->toContain('-a');
    });
});

describe('the proof a password works', function () {
    it('counts only an authenticated PONG with no AUTH failure, and a refused PING without one', function (string $withPassword, string $without, bool $works) {
        $dir = sys_get_temp_dir().'/installer-redis-'.bin2hex(random_bytes(4));
        mkdir($dir.'/bin', 0777, true);
        file_put_contents($dir.'/bin/redis-cli', "#!/bin/sh\nif [ -n \"\${REDISCLI_AUTH:-}\" ]; then printf '%b' ".escapeshellarg($withPassword)."; else printf '%b' ".escapeshellarg($without)."; fi\n");
        chmod($dir.'/bin/redis-cli', 0755);

        $script = installerFunctions('redis_password_works').'
            PATH='.escapeshellarg($dir.'/bin').':$PATH
            if redis_password_works pw-123; then echo works; else echo rejected; fi';
        exec('bash -c '.escapeshellarg($script).' 2>&1', $out);

        expect(implode('', $out))->toBe($works ? 'works' : 'rejected');
    })->with([
        'Redis 7 with an open default user: AUTH fails, PING works anyway' => ['AUTH failed: ERR AUTH <password> called without any password configured for the default user.\nPONG\n', 'PONG\n', false],
        'wrong password' => ['AUTH failed: WRONGPASS invalid username-password pair or user is disabled.\nNOAUTH Authentication required.\n', 'NOAUTH Authentication required.\n', false],
        'a password that works, on a Redis that also lets anyone in' => ['PONG\n', 'PONG\n', false],
        'Redis not running' => ['Could not connect to Redis at 127.0.0.1:6379: Connection refused\n', 'Could not connect to Redis at 127.0.0.1:6379: Connection refused\n', false],
        'a password that works' => ['PONG\n', 'NOAUTH Authentication required.\n', true],
    ]);

    it('is what configure_redis checks with, for every candidate', function () {
        $fn = installerFn('configure_redis');

        expect($fn)->toContain('redis_password_works "$candidate"')
            ->and($fn)->not->toContain('redis-cli');
    });
});
