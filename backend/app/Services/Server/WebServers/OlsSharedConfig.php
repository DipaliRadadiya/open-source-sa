<?php

namespace App\Services\Server\WebServers;

use App\Exceptions\Server\WebServer\OlsListenerNotFoundException;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Cache;

/**
 * The two entries every OpenLiteSpeed site needs in the *shared*
 * httpd_config.conf, and the only thing allowed to edit that file.
 *
 * nginx and Apache read a directory, so adding a site is dropping a file in.
 * OLS does not: a site is a per-site `vhconf.conf` **plus** a `virtualHost`
 * block at the top level of the shared config **plus** a `map` line inside a
 * `listener` block. Two of those three live in a file every other site on the
 * box depends on, which makes editing it the dangerous part of OLS support —
 * a mistake here is not one broken site, it is all of them.
 *
 * Three rules make that survivable:
 *
 *  - **The panel owns a marked region and nothing else.** Everything it writes
 *    lives between BEGIN/END markers; anything outside them is copied through
 *    untouched. Users migrate real servers into this panel with hand-written
 *    config in that file, and losing it would be unforgivable.
 *  - **The region is rebuilt, never appended to.** Add, replace and remove are
 *    the same operation — parse the region into entries, change one, render it
 *    back — so re-provisioning a site cannot duplicate its block.
 *  - **Back up, write, test, and restore the whole file if the test fails.**
 *
 * Community reports say the WebAdmin console does not reliably sync with these
 * files, deletions especially. The panel therefore owns them outright rather
 * than trying to cooperate.
 */
class OlsSharedConfig
{
    private const BEGIN_VHOSTS = '### BEGIN panel-managed virtual hosts — do not edit between these markers';

    private const END_VHOSTS = '### END panel-managed virtual hosts';

    private const BEGIN_MAPS = '  ### BEGIN panel-managed maps — do not edit between these markers';

    private const END_MAPS = '  ### END panel-managed maps';

    /**
     * The same maps again, inside the SSL listener.
     *
     * OpenLiteSpeed binds certificates to a *listener*, not to a vhost — a site
     * only answers on 443 if it is mapped there as well. Its own marked region
     * because the two listeners are separate blocks in the file, and a single
     * region cannot span both.
     */
    private const BEGIN_SSL_MAPS = '  ### BEGIN panel-managed SSL maps — do not edit between these markers';

    private const END_SSL_MAPS = '  ### END panel-managed SSL maps';

    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
    ) {}

    /**
     * Ensure a site is registered, then validate and keep or roll back.
     *
     * @param  array<int, string>  $domains
     */
    public function register(string $name, array $domains, string $vhRoot): ServerOpsResult
    {
        return $this->rewrite(
            fn (array $sites) => [...$sites, $name => ['domains' => $domains, 'root' => $vhRoot]],
            $name,
            'ols_register',
        );
    }

    public function unregister(string $name): ServerOpsResult
    {
        return $this->rewrite(function (array $sites) use ($name) {
            unset($sites[$name]);

            return $sites;
        }, $name, 'ols_unregister');
    }

    /**
     * Sites the panel currently has registered, name => domains.
     *
     * Read back out of the file rather than from the database: the file is the
     * thing OpenLiteSpeed actually reads, so it is the state that matters. A
     * rebuild driven from our own records would happily "fix" a file that
     * somebody had legitimately changed underneath us.
     *
     * @return array<string, array<int, string>>
     */
    public function sites(string $contents): array
    {
        $sites = [];

        // Domains come from the listener maps…
        foreach (preg_split('/\r?\n/', $this->region($contents, self::BEGIN_MAPS, self::END_MAPS)) ?: [] as $line) {
            if (preg_match('/^\s*map\s+(\S+)\s+(.+?)\s*$/', $line, $matches) === 1) {
                $sites[$matches[1]] = [
                    'domains' => array_values(array_filter(array_map('trim', explode(',', $matches[2])))),
                    'root' => null,
                ];
            }
        }

        // …and each site's root from its own virtualHost block, so a rebuild
        // preserves it. Reading only the maps would mean regenerating every
        // other site's vhRoot from a default and silently moving it.
        $vhosts = $this->region($contents, self::BEGIN_VHOSTS, self::END_VHOSTS);

        preg_match_all('/virtualHost\s+(\S+)\s*\{[^}]*?vhRoot\s+(\S+)/s', $vhosts, $found, PREG_SET_ORDER);

        foreach ($found as [, $name, $root]) {
            if (isset($sites[$name])) {
                $sites[$name]['root'] = rtrim($root, '/');
            }
        }

        return $sites;
    }

    /**
     * Whether the shared config still refers to a site — anywhere in the file,
     * not just inside the managed region.
     *
     * The whole file on purpose. Checking only the region would answer "did we
     * remove our entry", when the question before deleting a site's files is
     * "does OpenLiteSpeed still expect them". Those differ exactly when the
     * entries have escaped the markers — which is what the WebAdmin console
     * leaves behind, since the markers are comments and it strips them.
     */
    public function references(string $name): bool
    {
        $read = $this->serverOps->run(
            ['cat', $this->path()],
            ['feature' => 'application', 'op' => 'ols_read', 'site' => $name],
        );

        if (! $read->ok) {
            // Unreadable is not "absent". Treat it as still referenced so the
            // caller does not delete on the strength of a failed read.
            return true;
        }

        $quoted = preg_quote($name, '/');

        return preg_match('/^\s*(virtualHost\s+'.$quoted.'\s*\{|map\s+'.$quoted.'\s)/m', $read->output()) === 1;
    }

    /**
     * @param  callable(array<string, array<int, string>>): array<string, array<int, string>>  $change
     */
    private function rewrite(callable $change, string $name, string $op): ServerOpsResult
    {
        // Read-modify-write on a file shared by every site, from a queue that
        // runs more than one worker. Without this, two sites provisioning at
        // once means the second read misses the first's entry and writes it
        // back out — the site reports Active with a vhost the server has never
        // heard of.
        return Cache::lock('ols-shared-config', 30)->block(20, fn () => $this->rewriteLocked($change, $name, $op));
    }

    /**
     * @param  callable(array<string, array<int, string>>): array<string, array<int, string>>  $change
     */
    private function rewriteLocked(callable $change, string $name, string $op): ServerOpsResult
    {
        $path = $this->path();
        $context = ['feature' => 'application', 'op' => $op, 'site' => $name];

        $read = $this->serverOps->run(['cat', $path], $context);

        if ($read->failed()) {
            return $read;
        }

        $original = $read->output();
        $updated = $this->render($original, $change($this->sites($original)));

        // Nothing to do — and no reason to touch a file every site depends on.
        if ($updated === $original) {
            return $read;
        }

        $backup = $path.'.panel-bak';

        $backedUp = $this->serverOps->run(['cp', '-f', $path, $backup], $context);

        if ($backedUp->failed()) {
            return $backedUp;
        }

        $written = $this->files->put($path, $updated, $context);

        // `tee` truncates before it writes, so a failure here — no disk space,
        // a killed worker, a timeout — leaves the file empty or half written.
        // Restoring is not optional: this is every site on the box.
        if ($written->failed()) {
            $this->serverOps->run(['cp', '-f', $backup, $path], $context);

            return $written;
        }

        $test = $this->test();

        if ($test->failed()) {
            // The whole file back, not just our region: a config that fails its
            // own test must never survive to the next reload, and this one is
            // shared with every site on the box.
            $this->serverOps->run(['cp', '-f', $backup, $path], $context);
        }

        return $test;
    }

    /**
     * @param  array<string, array<int, string>>  $sites
     */
    private function render(string $contents, array $sites): string
    {
        ksort($sites);

        $vhosts = [];
        $maps = [];

        foreach ($sites as $name => $site) {
            $vhosts[] = $this->vhostBlock((string) $name, (string) ($site['root'] ?? ''));
            $maps[] = '  map                     '.$name.' '.implode(', ', $site['domains'] ?? []);
        }

        $contents = $this->replaceRegion(
            $contents,
            self::BEGIN_VHOSTS,
            self::END_VHOSTS,
            implode("\n", $vhosts),
            append: true,
        );

        $contents = $this->replaceRegion(
            $contents,
            self::BEGIN_MAPS,
            self::END_MAPS,
            implode("\n", $maps),
            append: false,
        );

        // Skipped rather than fatal when the box has no SSL listener. A server
        // that has never had a certificate legitimately does not have one, and
        // refusing to register a plain HTTP site because of that would break
        // provisioning on every OLS box until somebody set up TLS by hand.
        try {
            return $this->replaceRegion(
                $contents,
                self::BEGIN_SSL_MAPS,
                self::END_SSL_MAPS,
                implode("\n", $maps),
                append: false,
                listener: (string) config('server.web_server_drivers.openlitespeed.ssl_listener', 'Defaultssl'),
            );
        } catch (OlsListenerNotFoundException) {
            return $contents;
        }
    }

    /**
     * @param  string  $vhRoot  the site's own directory, NOT the config directory
     */
    private function vhostBlock(string $name, string $vhRoot): string
    {
        $conf = rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root', '/usr/local/lsws/conf/vhosts'), '/');
        $vhRoot = rtrim($vhRoot, '/');

        return implode("\n", [
            "virtualHost {$name} {",
            // vhRoot is the *site* directory. It used to be the config
            // directory, which with `restrained 1` — access confined to
            // vhRoot — put the document root outside the only tree the vhost
            // was allowed to read. Every request would have been refused.
            "  vhRoot                  {$vhRoot}/",
            "  configFile              {$conf}/{$name}/vhconf.conf",
            '  allowSymbolLink         1',
            '  enableScript            1',
            '  restrained              1',
            '}',
        ]);
    }

    /**
     * Swap a marked region's contents, creating the region if it is absent.
     *
     * `append: true` puts a missing region at the end of the file, which is
     * where a top-level `virtualHost` belongs. `append: false` puts it inside
     * the listener block, because that is the only place a `map` is legal.
     */
    private function replaceRegion(string $contents, string $begin, string $end, string $body, bool $append, ?string $listener = null): string
    {
        $region = trim($body) === ''
            ? $begin."\n".$end
            : $begin."\n".$body."\n".$end;

        $pattern = '/'.preg_quote($begin, '/').'.*?'.preg_quote($end, '/').'/s';

        // Spliced by offset rather than with preg_replace. A replacement string
        // has its own semantics — `$1` and `\1` are backreferences — so any
        // path or name containing them would be silently eaten. Nothing does
        // today, but the input includes operator-set config paths, and this is
        // the file every site on the box depends on. Substr has no semantics
        // to get wrong.
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 1) {
            [$matched, $offset] = $matches[0];

            return substr($contents, 0, $offset).$region.substr($contents, $offset + strlen($matched));
        }

        return $append
            ? rtrim($contents)."\n\n".$region."\n"
            : $this->insertInListener($contents, $region, $listener);
    }

    /**
     * Put a new region just before the listener block's closing brace.
     *
     * If there is no listener block there is nothing sane to do: a `map` is
     * only legal inside one, and inventing a listener would mean guessing which
     * address and port this server is supposed to answer on.
     */
    private function insertInListener(string $contents, string $region, ?string $listener = null): string
    {
        $listener ??= (string) config('server.web_server_drivers.openlitespeed.listener', 'Default');
        $pattern = '/^listener\s+'.preg_quote($listener, '/').'\s*\{/m';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            throw new OlsListenerNotFoundException($listener);
        }

        $close = $this->matchingBrace($contents, $matches[0][1] + strlen($matches[0][0]) - 1);

        if ($close === null) {
            throw new OlsListenerNotFoundException($listener);
        }

        return substr($contents, 0, $close).$region."\n".substr($contents, $close);
    }

    /**
     * The offset of the brace closing the one at `$open`.
     *
     * Counted rather than found by looking for a line starting with `}`. A
     * hand-written config that indents its closing brace would send that
     * search past the end of the listener and splice the maps region into
     * whatever block came next — where `map` is illegal, so every later
     * registration would fail its config test.
     */
    private function matchingBrace(string $contents, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $length = strlen($contents); $i < $length; $i++) {
            $depth += match ($contents[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0 && $contents[$i] === '}') {
                return $i;
            }
        }

        return null;
    }

    private function region(string $contents, string $begin, string $end): string
    {
        $pattern = '/'.preg_quote($begin, '/').'(.*?)'.preg_quote($end, '/').'/s';

        return preg_match($pattern, $contents, $matches) === 1 ? $matches[1] : '';
    }

    public function test(): ServerOpsResult
    {
        return $this->serverOps->run(
            (array) config('server.web_server_drivers.openlitespeed.test_command', ['/usr/local/lsws/bin/lswsctrl', 'config_test']),
            ['feature' => 'application', 'op' => 'ols_config_test'],
        );
    }

    private function path(): string
    {
        return (string) config('server.web_server_drivers.openlitespeed.shared_config', '/usr/local/lsws/conf/httpd_config.conf');
    }
}
