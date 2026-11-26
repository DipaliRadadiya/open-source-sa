<?php

/*
 * What install.sh's OpenLiteSpeed vhost points at has to exist when it is written.
 *
 * OpenLiteSpeed resolves a `context`'s `location` while loading the config and refuses one that
 * is not there:
 *
 *   [ERROR] [config:server:vhosts:vhost:panel:context:/_next/static]
 *       path is not accessible: /var/www/panel/frontend/.next/static
 *
 * nginx and Apache resolve per request and never notice. configure_web_server() deliberately
 * runs before build_frontend() (Next inlines NEXT_PUBLIC_* at build time), so `.next` does not
 * exist yet and `openlitespeed -t` would fail an otherwise correct config.
 *
 * Asserted here rather than in bash because the ordering is invisible where it breaks: a new
 * `context` in write_ols_vhost() is one line and nothing suggests a directory must exist first.
 */

/**
 * Paths the installer has already created by the time OpenLiteSpeed is configured, listed so
 * that a match against them is a decision rather than a hole in the pattern below.
 *
 * Both document roots are the repository's own directories, present since fetch_source(). The
 * fcgi socket is an address for an external app, not a path OpenLiteSpeed stats, and
 * configure_fpm() has already made it. Laravel's front controller is a repository file.
 */
const OLS_PREEXISTING_PATHS = [
    '${APP_DIR}/backend/public',
    '${APP_DIR}/backend/public/index.php',
    '${APP_DIR}/frontend',
];

/**
 * Every function that writes a `location` or `docRoot` into an OpenLiteSpeed vhost: the panel
 * and API vhosts plus the blocks they share. A path missed in any of them fails the install.
 */
const OLS_VHOST_WRITERS = [
    'write_ols_vhost',
    'write_ols_api_vhost',
    'ols_acme_context',
];

/**
 * @return array<int, string>
 */
function installerFunction(string $name): array
{
    $source = installerSource();

    // Up to the next top-level definition, not the next `}` at column 0: these functions write
    // heredocs, which are full of closing braces and comments at column 0. Stopping there
    // truncated the body to nothing and every assertion passed vacuously. Overshooting into the
    // next doc comment is the safe direction to be wrong in.
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

    // `docRoot ${doc_root}` is a shell variable, not a path. Resolve it from its own assignments
    // rather than skip it: it holds a real directory in each branch, the one every request needs.
    $docRoots = [];
    preg_match_all('/doc_root="([^"]+)"/', implode("\n", installerFunction('write_ols_vhost')), $m);
    $docRoots = $m[1];

    expect($docRoots)->not->toBeEmpty();

    $served = [];

    foreach (OLS_VHOST_WRITERS as $writer) {
        foreach (installerFunction($writer) as $line) {
            if (preg_match('/^\s*(?:location|docRoot)\s+(\S+)\s*$/', $line, $m) !== 1) {
                continue;
            }

            if ($m[1] === '${doc_root}') {
                $served = array_merge($served, $docRoots);

                continue;
            }

            $served[] = $m[1];
        }
    }

    // If this is empty the assertion below passes vacuously, which is the one
    // way a test like this fails silently.
    expect($served)->not->toBeEmpty();

    // `mkdir -p a/b/c` makes a and a/b as well, so an ancestor of a created directory counts as
    // created. Matched on path segments, not as a substring: `.../public/` is a text prefix of
    // `.../public/.well-known` either way, and that is how an uncreated directory would slip through.
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
    // The test itself lives in test_ols_config, called from configure_ols.
    $test = strpos($body, 'test_ols_config "$conf"');

    expect(implode("\n", installerFunction('test_ols_config')))->toContain('/usr/local/lsws/bin/openlitespeed -t')
        ->and($create)->toBeInt()
        ->and($write)->toBeInt()
        ->and($test)->toBeInt()
        ->and($create)->toBeLessThan($write)
        ->and($write)->toBeLessThan($test);
});

/**
 * The `listener Default` block and `virtualHost Example` exactly as OpenLiteSpeed 1.9.2 ships
 * them: no space before either brace, `map Example *` last inside the listener, and a
 * hand-indented closing brace.
 *
 * Copied from the .deb, not typed from the documentation: `listener Default {` with a space
 * matched nothing, and a listener on :8088 instead of :80 would register the panel on a port
 * nobody asks for.
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

    // Removing only the map left the virtualHost defined, and its root-owned document root trips
    //   [WARN] ... Uid of /usr/local/lsws/Example/html/ is 0 ...
    // on every config load.
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
    // nginx and Apache give the API its own server block on ${API_HOST}. OpenLiteSpeed routes by
    // listener `map`, and every map named only ${PANEL_HOST}: with the shipped `map Example *`
    // catch-all removed, nothing answered for the API hostname and every panel request failed.
    $maps = implode("\n", installerFunction('ols_listener_maps'));

    expect($maps)->toContain('"$PANEL_SLUG" "$PANEL_HOST"')
        ->and($maps)->toContain('"$PANEL_SLUG" "$API_HOST"')
        // Single-host installs put both roles on one name and one vhost;
        // a second map there would name a virtual host that does not exist.
        ->and($maps)->toContain('(( SINGLE_HOST ))');

    // Both listeners: the plain one register_ols_panel_vhost() maps into, and
    // the TLS one install_ols_certificate() creates. Getting one and not the
    // other means the panel works until the certificate lands.
    $source = installerSource();

    expect(substr_count($source, 'awk -v maps="$(ols_listener_maps)"'))->toBe(2)
        ->and($source)->not->toContain('-v host="${PANEL_HOST}"');
});

it('keeps the front controller out of the vhost that proxies to Next', function () {
    // A `rewrite` block is virtual-host scope: in the proxy vhost every SPA route (/dashboard is
    // not a file) would match `RewriteCond %{REQUEST_FILENAME} !-f` and be rewritten to
    // /index.php, so the panel would answer Laravel's 404 everywhere. Hence two vhosts, as on Apache.
    $body = implode("\n", installerFunction('write_ols_vhost'));

    // The rewrite is emitted through a variable that only the single-host
    // branch fills, and that branch fences it to the backend's own paths.
    expect($body)->toContain('front_controller=""')
        ->and($body)->not->toContain('$(ols_front_controller "")');

    $fenced = substr($body, strpos($body, 'if (( SINGLE_HOST )); then'));

    expect($fenced)->toContain('RewriteCond %{REQUEST_URI} ^/(api|sanctum)(/|$)');

    // The API vhost has no proxy in it, so there the plain rule is right — and
    // it must actually be there, or the backend serves 404 for every route
    // Laravel owns.
    expect(implode("\n", installerFunction('write_ols_api_vhost')))
        ->toContain('$(ols_front_controller "")')
        ->not->toContain('panel-next');
});

it('runs the rewritten /index.php as PHP on a single-host install, not through the proxy', function () {
    // OpenLiteSpeed picks the context again for the path a rewrite produces. /index.php is under
    // neither /api nor /sanctum, so without its own context it fell to the catch-all `/` proxy
    // and looped through Next until the proxy timed out.
    $body = implode("\n", installerFunction('write_ols_vhost'));
    $fenced = substr($body, strpos($body, 'if (( SINGLE_HOST )); then'));

    expect($fenced)->toMatch(
        '/context \/index\.php \{\n\s*location\s+\$\{APP_DIR\}\/backend\/public\/index\.php\n\s*allowBrowse\s+1\n\s*enableScript\s+1\n\}/'
    );
});

it('converges when the installer is re-run', function () {
    $once = applyOlsRewrite(OLS_SHIPPED_CONFIG);

    expect(applyOlsRewrite($once))->toBe($once);
});

it('hands the frontend build a tree it can still write to', function () {
    // .next is created here as root but written later by the panel account. Without the chown the
    // build would fail instead, later and with no hint about permissions.
    $body = implode("\n", installerFunction('ensure_ols_context_paths'));

    expect($body)->toContain('chown -R "${APP_USER}:${APP_USER}"')
        ->and($body)->toContain('${APP_DIR}/frontend/.next');
});

it('installs from the panel exactly what install.sh installs', function () {
    // config/server.php once listed FPM extension names, five of which are not lsphp packages;
    // apt fails the whole transaction on one missing name, so nothing installed on OpenLiteSpeed.
    //
    // install.sh's list is the one proven on hardware, so it is the source of truth; this test
    // keeps the two from drifting. Whichever a future edit changes, the other has to move with it.
    preg_match_all(
        '/\$\{lsphp\}-([a-z0-9]+)/',
        implode("\n", installerFunction('install_ols_packages')),
        $matches,
    );

    $fromInstaller = array_values(array_unique($matches[1]));
    $fromConfig = (array) config('server.php_stacks.lsphp.base_packages');

    expect($fromInstaller)->not->toBeEmpty('install.sh installs no lsphp packages?');

    sort($fromInstaller);
    $sortedConfig = $fromConfig;
    sort($sortedConfig);

    expect($sortedConfig)->toBe($fromInstaller);
});

it('never lists an extension that LiteSpeed compiles into the interpreter', function () {
    // These have no package because they are built in (all six load on a box with none of them
    // installed). Naming one is not harmless: it is an apt error that takes the whole install down.
    //
    // `gd` is the nastiest: it resolves as a name with no installation candidate rather than as
    // unknown, so it looks real in a search and still kills the transaction.
    $compiledIn = ['mbstring', 'xml', 'zip', 'gd', 'bcmath', 'soap'];

    expect(array_intersect(
        (array) config('server.php_stacks.lsphp.base_packages'),
        $compiledIn,
    ))->toBe([]);
});
