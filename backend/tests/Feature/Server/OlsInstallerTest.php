<?php

/*
 * What install.sh's OpenLiteSpeed vhost points at has to exist when it is
 * written.
 *
 * OpenLiteSpeed resolves a `context`'s `location` while loading the config and
 * refuses one that is not there:
 *
 *   [ERROR] [config:server:vhosts:vhost:panel:context:/_next/static]
 *       path is not accessible: /var/www/panel/frontend/.next/static
 *
 * nginx and Apache resolve per request and never noticed, which is why this
 * shipped: configure_web_server() runs before build_frontend() deliberately —
 * Next inlines NEXT_PUBLIC_* at build time, so the URLs have to be final first
 * — and at that moment .next does not exist. `openlitespeed -t` then failed on
 * a config that was otherwise correct, and the install aborted.
 *
 * Asserted here rather than in bash because the ordering is invisible at the
 * point it breaks: a future `context` added to write_ols_vhost() is one line,
 * and nothing about writing it suggests a directory has to be made first.
 */

/**
 * Paths the installer has already created by the time OpenLiteSpeed is
 * configured, listed so that a match against them is a decision rather than a
 * hole in the pattern below.
 *
 * The document root is the repository's own `backend/public`, present since
 * fetch_source(). The socket is not a path OpenLiteSpeed stats — it is an
 * address for an external app, and configure_fpm() has already made it.
 */
const OLS_PREEXISTING_PATHS = [
    '${APP_DIR}/backend/public',
];

function installerSource(): string
{
    $path = base_path('../install.sh');

    if (! is_file($path)) {
        test()->markTestSkipped('install.sh is not in this checkout');
    }

    return (string) file_get_contents($path);
}

/**
 * @return array<int, string>
 */
function installerFunction(string $name): array
{
    $source = installerSource();

    // Up to the next top-level definition rather than to the next `}` on its
    // own line: these functions write config with heredocs, and a heredoc is
    // full of closing braces — and comments — at column 0. Reading to the first
    // of those silently truncated the body to nothing, and every assertion over
    // it passed vacuously. Overshooting into the next function's doc comment is
    // the safe direction to be wrong in.
    preg_match(
        '/^'.preg_quote($name, '/').'\(\) \{$(.*?)(?:^[a-z_]+\(\) \{|\z)/ms',
        $source,
        $matches,
    );

    expect($matches)->not->toBeEmpty("install.sh has no {$name}() to read");

    return explode("\n", $matches[1]);
}

it('creates every directory the OpenLiteSpeed vhost serves from', function () {
    $created = [];

    foreach (installerFunction('ensure_ols_context_paths') as $line) {
        if (preg_match('/^\s*"(\$\{APP_DIR\}\S*)"\s*$/', $line, $m) === 1) {
            $created[] = rtrim($m[1], '/');
        }
    }

    expect($created)->not->toBeEmpty();

    $served = [];

    foreach (installerFunction('write_ols_vhost') as $line) {
        if (preg_match('/^\s*(?:location|docRoot)\s+(\S+)\s*$/', $line, $m) === 1) {
            $served[] = $m[1];
        }
    }

    // If this is empty the assertion below passes vacuously, which is the one
    // way a test like this fails silently.
    expect($served)->not->toBeEmpty();

    // `mkdir -p a/b/c` makes a and a/b as well, so an ancestor of a created
    // directory counts as created. Matched on path segments rather than as a
    // substring: `.../public/` is a prefix of `.../public/.well-known` as text
    // whether or not it is one as a path, and that difference is how a genuinely
    // uncreated directory would slip through.
    $missing = array_values(array_filter(
        array_unique(array_map(fn (string $path) => rtrim($path, '/'), $served)),
        fn (string $path) => ! in_array($path, array_map(
            fn (string $known) => rtrim($known, '/'),
            OLS_PREEXISTING_PATHS,
        ), true) && ! array_filter(
            $created,
            fn (string $made) => $made === $path || str_starts_with($made, $path.'/'),
        ),
    ));

    expect($missing)->toBe([], 'OpenLiteSpeed will refuse these, and the install aborts: '.implode(', ', $missing));
});

it('creates them before the config that names them is tested', function () {
    $body = implode("\n", installerFunction('configure_ols'));

    $create = strpos($body, 'ensure_ols_context_paths');
    $write = strpos($body, 'write_ols_vhost');
    $test = strpos($body, 'openlitespeed -t');

    expect($create)->toBeInt()
        ->and($write)->toBeInt()
        ->and($test)->toBeInt()
        ->and($create)->toBeLessThan($write)
        ->and($write)->toBeLessThan($test);
});

/**
 * The `listener Default` block and `virtualHost Example` exactly as
 * OpenLiteSpeed 1.9.2 ships them — no space before either brace, `map Example
 * *` last inside the listener, and a hand-indented closing brace.
 *
 * Copied from the .deb rather than typed from the documentation. Every mistake
 * this installer has made against this file came from writing what the config
 * ought to look like: `listener Default {` with a space matched nothing, and
 * the listener being on :8088 rather than :80 would have registered the panel
 * perfectly on a port nobody was asking for.
 */
const OLS_SHIPPED_CONFIG = <<<'CONF'
virtualHost Example{
    vhRoot                   Example/
    allowSymbolLink          1
    enableScript             1
    restrained               1
    setUIDMode               0
    configFile               conf/vhosts/Example/vhconf.conf
}

listener Default{
    address                  *:8088
    secure                   0
    map                      Example *
}

vhTemplate centralConfigLog{
    templateFile             conf/templates/ccl.conf
    listeners                Default
}
CONF;

/**
 * Run the installer's own awk program, pulled out of install.sh rather than
 * copied into this file. A copy would drift, and the transformation is the
 * thing being tested.
 */
function applyOlsRewrite(string $config): string
{
    preg_match(
        "/local tmp80=.*?\n\s*awk '\n(.*?)\n\s*' \"\\\$conf\"/s",
        installerSource(),
        $matches,
    );

    expect($matches)->not->toBeEmpty('install.sh no longer has the awk pass this test exercises');

    $input = tempnam(sys_get_temp_dir(), 'ols');
    file_put_contents($input, $config);

    $program = tempnam(sys_get_temp_dir(), 'awk');
    file_put_contents($program, $matches[1]);

    $output = (string) shell_exec('awk -f '.escapeshellarg($program).' '.escapeshellarg($input).' 2>&1');

    unlink($input);
    unlink($program);

    return $output;
}

it('takes the shipped Example site out of the config, not just off the listener', function () {
    $result = applyOlsRewrite(OLS_SHIPPED_CONFIG);

    // Removing only the map left the virtualHost defined and still validated on
    // every config load, where its root-owned document root trips
    //   [WARN] ... Uid of /usr/local/lsws/Example/html/ is 0 ...
    // on a demo site the installer has already decided is not reachable.
    expect($result)->not->toContain('virtualHost Example')
        ->and($result)->not->toContain('map                      Example');

    // The listener survives it, on port 80, with the panel's own map still to
    // be added by the pass that follows.
    expect($result)->toContain('listener Default{')
        ->and($result)->toContain('address                  *:80')
        ->and($result)->not->toContain('*:8088');

    // Nothing else went with it. An over-eager brace counter that swallowed the
    // rest of the file would still satisfy every assertion above.
    expect($result)->toContain('vhTemplate centralConfigLog{')
        ->and($result)->toContain('conf/templates/ccl.conf');
});

it('answers for the API hostname as well as the panel one', function () {
    // nginx and Apache give the API its own server block on ${API_HOST}.
    // OpenLiteSpeed routes by listener `map`, and every map the installer wrote
    // named only ${PANEL_HOST} — so with the shipped `map Example *` catch-all
    // also removed, nothing on the box answered for the API hostname and the
    // panel would have installed, loaded, and failed every request it made.
    $source = installerSource();

    // Both listeners: the plain one register_ols_panel_vhost() maps into, and
    // the TLS one install_ols_certificate() creates. Getting one and not the
    // other means the panel works until the certificate lands.
    expect(substr_count($source, 'awk -v slug="${PANEL_SLUG}" -v host="$(ols_vhost_domains)"'))->toBe(2)
        ->and($source)->toContain('map                     ${PANEL_SLUG} $(ols_vhost_domains)')
        ->and($source)->not->toContain('-v host="${PANEL_HOST}"');

    // Comma-separated with no space. The manual's Listeners "Domains" field
    // specifies a comma-separated list; a space is not documented as tolerated,
    // and a listener map that parses as one long hostname fails silently — it
    // matches nothing rather than erroring.
    $helper = implode("\n", installerFunction('ols_vhost_domains'));

    expect($helper)->toContain('printf \'%s,%s\' "$PANEL_HOST" "$API_HOST"')
        // Single-host installs put both roles on one name; mapping it twice
        // would be a duplicate domain, which OpenLiteSpeed rejects outright.
        ->and($helper)->toContain('if (( SINGLE_HOST ))');
});

it('converges when the installer is re-run', function () {
    $once = applyOlsRewrite(OLS_SHIPPED_CONFIG);

    expect(applyOlsRewrite($once))->toBe($once);
});

it('hands the frontend build a tree it can still write to', function () {
    // .next is created here as root and written by the panel account minutes
    // later. Without the chown this would trade an OpenLiteSpeed error for a
    // build one, which is a worse trade — the build failure comes later and
    // says nothing about permissions.
    $body = implode("\n", installerFunction('ensure_ols_context_paths'));

    expect($body)->toContain('chown -R "${APP_USER}:${APP_USER}"')
        ->and($body)->toContain('${APP_DIR}/frontend/.next');
});
