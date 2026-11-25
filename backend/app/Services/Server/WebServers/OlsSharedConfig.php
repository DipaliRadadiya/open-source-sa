<?php

namespace App\Services\Server\WebServers;

use App\Exceptions\Server\WebServer\OlsListenerNotFoundException;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The two entries every OpenLiteSpeed site needs in the *shared* httpd_config.conf, and the only
 * thing allowed to edit that file.
 *
 * nginx and Apache read a directory, so adding a site is dropping a file in. OLS needs a per-site
 * `vhconf.conf` **plus** a `virtualHost` block at the top level of the shared config **plus** a `map`
 * line inside a `listener` block. Two of those live in a file every other site depends on, so a
 * mistake here breaks all sites, not one.
 *
 * Three rules make that survivable:
 *
 *  - **The panel owns a marked region and nothing else.** Everything it writes lives between
 *    BEGIN/END markers; anything outside is copied through untouched (migrated servers carry
 *    hand-written config in that file).
 *  - **The region is rebuilt, never appended to.** Add, replace and remove are the same operation
 *    (parse the region into entries, change one, render it back), so re-provisioning cannot
 *    duplicate a block.
 *  - **Back up, write, test, and restore the whole file if the test fails.**
 *
 * The WebAdmin console does not reliably sync with these files (deletions especially), so the panel
 * owns them outright rather than cooperating.
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
     * OpenLiteSpeed binds certificates to a *listener*, not a vhost: a site answers on 443 only if it
     * is mapped there too. Its own region because the two listeners are separate blocks.
     */
    private const BEGIN_SSL_MAPS = '  ### BEGIN panel-managed SSL maps — do not edit between these markers';

    private const END_SSL_MAPS = '  ### END panel-managed SSL maps';

    /** @see serverUser() — memoised, including a failed read. */
    private ?string $serverUser = null;

    private bool $serverUserRead = false;

    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private CertificateFiles $certificateFiles,
        private OlsConfigCheck $configCheck,
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
            // Adding a site is when a secure listener might be needed; creating one on removal would
            // change what the server binds as a side effect of a deletion.
            secureListener: true,
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
     * Read back out of the file, not the database: the file is what OpenLiteSpeed actually reads, and
     * a rebuild from our own records would "fix" a file somebody had legitimately changed.
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

        // …and each site's root from its own virtualHost block, so a rebuild preserves it instead of
        // regenerating every other site's vhRoot from a default.
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
     * Whether the shared config still refers to a site, anywhere in the file, not just the managed
     * region.
     *
     * The question before deleting a site's files is "does OpenLiteSpeed still expect them", not "did
     * we remove our entry". They differ when entries have escaped the markers, which the WebAdmin
     * console does (the markers are comments and it strips them).
     */
    public function references(string $name): bool
    {
        $read = $this->serverOps->run(
            ['cat', $this->path()],
            ['feature' => 'application', 'op' => 'ols_read', 'site' => $name],
        );

        if (! $read->ok) {
            // Unreadable is not "absent": treat it as still referenced so a failed read never leads
            // to a delete.
            return true;
        }

        $quoted = preg_quote($name, '/');

        return preg_match('/^\s*(virtualHost\s+'.$quoted.'\s*\{|map\s+'.$quoted.'\s)/m', $read->output()) === 1;
    }

    /**
     * Whether `$name` is already a vhost that is not a site: the panel's own `panel` / `panel-api`,
     * or OpenLiteSpeed's `Example`.
     *
     * {@see absorbUnmanaged()} takes over an entry outside the markers so a migrated server does
     * not end up with two definitions of one site, but it cannot tell a site from the panel itself
     * by name (a site created as `panel` took over the panel's own vhost). So the driver asks this
     * **before writing anything**.
     *
     * Told apart by `vhRoot`: a site's is its own folder (v7's template is `vhRoot {{
     * $applicationPath }}`, ours {@see OlsDriver::vhRoot()}), while install.sh roots the panel's
     * vhosts in the config directory and OpenLiteSpeed ships `Example` with a relative `Example/`.
     *
     * An unreadable file answers false: the write that follows fails on its own, and inventing a
     * conflict would refuse every site on a box whose config could not be read once.
     */
    public function ownedByServer(string $name): bool
    {
        $read = $this->serverOps->run(
            ['cat', $this->path()],
            ['feature' => 'application', 'op' => 'ols_read', 'site' => $name],
        );

        return $read->ok && $this->serverOwned($read->output(), $name);
    }

    private function serverOwned(string $contents, string $name): bool
    {
        $root = $this->unmanagedRoot($contents, $name);

        if ($root === null) {
            return false;
        }

        $configDir = rtrim(app(OlsVhostLayout::class)->root(), '/').'/';

        return ! str_starts_with($root, '/') || str_starts_with(rtrim($root, '/').'/', $configDir);
    }

    /**
     * The `vhRoot` of the `virtualHost <name>` block outside the markers, or null when there is none.
     * Same line walk as absorbUnmanaged(), so the two cannot disagree about which block is "outside".
     */
    private function unmanagedRoot(string $contents, string $name): ?string
    {
        $quoted = preg_quote($name, '/');
        $managed = false;
        $inside = false;
        $depth = 0;

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/### (BEGIN|END) panel-managed/', $line, $marker) === 1) {
                $managed = $marker[1] === 'BEGIN';

                continue;
            }

            if ($managed) {
                continue;
            }

            if (! $inside) {
                if (preg_match('/^\s*virtualHost\s+'.$quoted.'\s*\{/', $line) === 1) {
                    $inside = true;
                    $depth = substr_count($line, '{') - substr_count($line, '}');
                }

                continue;
            }

            if (preg_match('/^\s*vhRoot\s+(\S+)/', $line, $found) === 1) {
                return $found[1];
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');

            if ($depth <= 0) {
                // A block with no vhRoot says nothing about where it lives; treat it like the
                // relative case: not a site of ours.
                return '';
            }
        }

        return null;
    }

    /**
     * The account lsws drops its workers to: the top-level `user` directive.
     *
     * Here because this class owns the file and is the only place that should know its shape.
     *
     * Anchored to column zero deliberately: a vhost's `extUser` is the *site's* account, not the
     * server's, and matching it would give an answer that looks right and is not.
     *
     * Null when the file cannot be read or names no user: the caller decides what to do without one.
     */
    public function serverUser(): ?string
    {
        if ($this->serverUserRead) {
            return $this->serverUser;
        }

        // Memoised including failures: `ensure()` runs per site on a resync, and an unreadable config
        // should not be reported once per site.
        $this->serverUserRead = true;

        $read = $this->serverOps->run(
            ['cat', $this->path()],
            ['feature' => 'application', 'op' => 'ols_read_user'],
        );

        if (! $read->ok) {
            return $this->serverUser = null;
        }

        if (preg_match('/^user\s+(\S+)/m', $read->output(), $matches) !== 1) {
            return $this->serverUser = null;
        }

        return $this->serverUser = $matches[1];
    }

    /**
     * @param  callable(array<string, array<int, string>>): array<string, array<int, string>>  $change
     */
    private function rewrite(callable $change, string $name, string $op, bool $secureListener = false): ServerOpsResult
    {
        // Read-modify-write on a file shared by every site, from a queue with more than one worker.
        // Without the lock the second site's read misses the first's entry and writes it back out, so
        // the site reports Active with a vhost the server has never heard of.
        return Cache::lock('ols-shared-config', 30)->block(20, fn () => $this->rewriteLocked($change, $name, $op, $secureListener));
    }

    /**
     * @param  callable(array<string, array<int, string>>): array<string, array<int, string>>  $change
     */
    private function rewriteLocked(callable $change, string $name, string $op, bool $secureListener): ServerOpsResult
    {
        $path = $this->path();
        $context = ['feature' => 'application', 'op' => $op, 'site' => $name];

        $read = $this->serverOps->run(['cat', $path], $context);

        if ($read->failed()) {
            return $read;
        }

        $original = $read->output();
        $sites = $change($this->sites($original));

        // A site with no known directory is refused, not written: it is a map line with no
        // virtualHost block beside it, and rendering it gave `vhRoot /` (the whole filesystem).
        // OpenLiteSpeed accepts that until the site's own config names a log under $VH_ROOT, so the
        // test passed and the next change to that site failed. Nothing is changed and the reference
        // says why.
        $unknown = array_keys(array_filter($sites, fn (array $site): bool => trim((string) ($site['root'] ?? ''), '/') === ''));

        if ($unknown !== []) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->error('ols shared config refused: a site has no known directory', $context + [
                'reference' => $reference,
                'sites' => $unknown,
                'path' => $path,
            ]);

            return new ServerOpsResult(false, $reference);
        }

        // Never take over a vhost that is not a site: see ownedByServer(). The driver asks first;
        // this catches every other way in (resync, a rename) and is the last line before the shared
        // file.
        //
        // Only the site being written: asking of every site would let one odd entry elsewhere stop
        // every site on the box from saving.
        if (array_key_exists($name, $sites) && $this->serverOwned($original, $name)) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->error('ols shared config refused: name belongs to a vhost that is not a site', $context + [
                'reference' => $reference,
                'path' => $path,
            ]);

            return new ServerOpsResult(false, $reference);
        }

        $updated = $this->render(
            $original,
            $sites,
            $secureListener ? $this->tlsFallback($context) : null,
        );

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

        // `tee` truncates before it writes, so a failure here (no disk space, a killed worker, a
        // timeout) leaves the file empty or half written. Restoring is not optional.
        if ($written->failed()) {
            $this->serverOps->run(['cp', '-f', $backup, $path], $context);

            return $written;
        }

        $test = $this->test();

        if ($test->failed()) {
            // The whole file back, not just our region: a config that fails its own test must never
            // survive to the next reload.
            $this->serverOps->run(['cp', '-f', $backup, $path], $context);

            return $test;
        }

        $this->discardBackup($backup, $context);

        return $test;
    }

    /**
     * The shared TLS-reject key pair, but only if it is really on disk.
     *
     * `OlsDriver::apply()` creates it first, but ask anyway: a secure listener naming missing files
     * stops OpenLiteSpeed starting for every site, and the config test has been seen passing a config
     * it should have rejected. Null means the listener is simply not created.
     *
     * @param  array<string, mixed>  $context
     * @return array{certificate: string, private_key: string}|null
     */
    private function tlsFallback(array $context): ?array
    {
        $paths = $this->certificateFiles->fallbackPaths();

        $present = $this->serverOps->run(
            ['test', '-s', $paths['certificate'], '-a', '-s', $paths['private_key']],
            $context,
        );

        return $present->ok ? $paths : null;
    }

    /**
     * @param  array<string, array<int, string>>  $sites
     * @param  array{certificate: string, private_key: string}|null  $tlsFallback  null when the key pair is not on disk
     */
    /**
     * Remove a site's `virtualHost` block and `map` line from the text outside the managed markers,
     * so the managed region can own them.
     *
     * Brace-counted rather than regex-matched: a pattern that stops at the first `}` would cut a
     * block in half and leave a config that fails to parse, which here means the whole server.
     *
     * Only ever called for a name the panel is about to write, and only outside the markers;
     * everything else is copied through.
     */
    private function absorbUnmanaged(string $contents, string $name): string
    {
        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $quoted = preg_quote($name, '/');

        $out = [];
        $managed = false;
        $depth = 0;
        $dropping = false;

        foreach ($lines as $line) {
            // Anything between any BEGIN/END pair belongs to the panel already.
            if (preg_match('/### (BEGIN|END) panel-managed/', $line, $marker) === 1) {
                $managed = $marker[1] === 'BEGIN';
                $out[] = $line;

                continue;
            }

            if ($managed) {
                $out[] = $line;

                continue;
            }

            if ($dropping) {
                $depth += substr_count($line, '{') - substr_count($line, '}');

                if ($depth <= 0) {
                    $dropping = false;
                }

                continue;
            }

            if (preg_match('/^\s*virtualHost\s+'.$quoted.'\s*\{/', $line) === 1) {
                $depth = substr_count($line, '{') - substr_count($line, '}');
                $dropping = $depth > 0;

                continue;
            }

            if (preg_match('/^\s*map\s+'.$quoted.'\s/', $line) === 1) {
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private function render(string $contents, array $sites, ?array $tlsFallback = null): string
    {
        if ($tlsFallback !== null) {
            $contents = $this->ensureSecureListener($contents, $tlsFallback);
        }

        ksort($sites);

        // Take over any entry for these sites that sits outside the markers, rather than writing a
        // second one beside it. `sites()` reads the managed regions only, which is wrong for a file
        // inherited from the old panel (it already has `virtualHost <name>` and `map <name>` for
        // every site): two definitions of one site would break every site, not one.
        //
        // Scoped to exactly the names being written, so anything else (hand-written, or from the
        // WebAdmin console) is still copied through untouched. It rides the write that already backs
        // up, tests and rolls back.
        foreach (array_keys($sites) as $name) {
            $contents = $this->absorbUnmanaged($contents, (string) $name);
        }

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

        // Skipped rather than fatal when the box has no SSL listener: a server that never had a
        // certificate legitimately has none, and refusing a plain HTTP site would break
        // provisioning on every OLS box until TLS was set up by hand.
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
        // From the layout, not from config: `OlsDriver::configPath()` learned where a migrated
        // server keeps its vhosts, and this block (which tells OpenLiteSpeed which file to read)
        // must agree, or the panel writes config to one path and points the server at another.
        $layout = app(OlsVhostLayout::class);
        $conf = $layout->root();
        $file = $layout->filename();
        $vhRoot = rtrim($vhRoot, '/');

        return implode("\n", [
            "virtualHost {$name} {",
            // vhRoot is the *site* directory, not the config directory: with `restrained 1` (access
            // confined to vhRoot) a config-directory root would put the document root outside the
            // only tree the vhost may read, and every request would be refused.
            "  vhRoot                  {$vhRoot}/",
            "  configFile              {$conf}/{$name}/{$file}",
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

        // Spliced by offset, not preg_replace: a replacement string has its own semantics (`$1`,
        // `\1`) that would silently eat such sequences in operator-set config paths, in the file
        // every site depends on. Substr has none.
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 1) {
            [$matched, $offset] = $matches[0];

            return substr($contents, 0, $offset).$region.substr($contents, $offset + strlen($matched));
        }

        return $append
            ? rtrim($contents)."\n\n".$region."\n"
            : $this->insertInListener($contents, $region, $listener);
    }

    /**
     * The secure listener, created when the server has none.
     *
     * OpenLiteSpeed binds certificates to a **listener**: a site answers on 443 only if a secure
     * listener exists *and* the site is mapped into it. A fresh OLS install ships no secure one;
     * install.sh creates `Defaultssl`, but **only when the panel itself gets TLS**. Without this,
     * issuing a certificate succeeded, `vhssl` named the real key pair, the panel reported HTTPS, and
     * nothing listened on 443.
     *
     * Inventing this listener is safe, unlike the plain one: there is exactly one right answer for
     * TLS (`*:443`), the other web servers already listen there, and install.sh writes this very
     * block, which this mirrors.
     *
     * The key pair is the shared TLS-reject pair that vhosts without a certificate use; it is only
     * the SNI fallback and each site overrides it in `vhssl`. It is caller-supplied and only used
     * when confirmed present: a listener naming missing files stops OpenLiteSpeed starting, and
     * `openlitespeed -t` has been seen passing a config it should have rejected.
     *
     * @param  array{certificate: string, private_key: string}  $tlsFallback
     */
    private function ensureSecureListener(string $contents, array $tlsFallback): string
    {
        $listener = (string) config('server.web_server_drivers.openlitespeed.ssl_listener', 'Defaultssl');

        // A box that already has one has it for a reason (an operator's own certificate, a
        // non-standard port). Same rule as install.sh: create only when absent, never rewrite.
        if (preg_match('/^listener\s+'.preg_quote($listener, '/').'\s*\{/m', $contents) === 1) {
            return $contents;
        }

        $block = implode("\n", [
            '',
            "listener {$listener} {",
            '  address                 *:443',
            '  secure                  1',
            "  keyFile                 {$tlsFallback['private_key']}",
            "  certFile                {$tlsFallback['certificate']}",
            '  certChain               1',
            // TLS 1.2 as the floor, spelled as OLS spells it; matches the vhost templates and
            // install.sh.
            '  sslProtocol             24',
            // Empty on purpose: the maps region below is written by the same pass and needs the
            // markers already inside a listener to find them.
            self::BEGIN_SSL_MAPS,
            self::END_SSL_MAPS,
            '}',
        ]);

        return rtrim($contents)."\n".$block."\n";
    }

    /**
     * Put a new region just before the listener block's closing brace.
     *
     * With no listener block there is nothing sane to do: a `map` is only legal inside one, and
     * inventing one would mean guessing which address and port to answer on.
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
     * Counted rather than found by looking for a line starting with `}`: a hand-written config that
     * indents its closing brace would send that search past the listener and splice the maps into the
     * next block, where `map` is illegal.
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

    /**
     * Stop the secure listener presenting the panel's own certificate to names no site claims.
     *
     * The TLS handshake uses the listener's certificate before OLS answers an unmapped name with its
     * 404, and install.sh set that to the panel's, so any domain pointed at the server showed the
     * panel's hostname. The panel's vhosts carry the same pair in their own `vhssl`, so the listener
     * gets the reserved self-signed pair instead (the one sites without a certificate use).
     *
     * Only when the listener's certificate is exactly the one on the panel's own vhost; anything else
     * was set on purpose and is left.
     *
     * @param  array<int, string>  $panelHosts
     * @return array{status: 'updated'|'current'|'skipped'|'failed', reference: ?string}
     */
    public function neutralListenerCertificate(array $panelHosts): array
    {
        return Cache::lock('ols-shared-config', 30)->block(20, fn () => $this->neutralListenerCertificateLocked($panelHosts));
    }

    /**
     * @param  array<int, string>  $panelHosts
     * @return array{status: 'updated'|'current'|'skipped'|'failed', reference: ?string}
     */
    private function neutralListenerCertificateLocked(array $panelHosts): array
    {
        $path = $this->path();
        $context = ['feature' => 'web_server', 'op' => 'catch_all_listener'];
        $read = $this->files->get($path, $context);

        if ($read->failed()) {
            return ['status' => 'failed', 'reference' => $read->reference];
        }

        $contents = $read->output();
        $listener = (string) config('server.web_server_drivers.openlitespeed.ssl_listener', 'Defaultssl');

        if (preg_match('/^listener\s+'.preg_quote($listener, '/').'\s*\{/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $open = $match[0][1] + strlen($match[0][0]) - 1;
        $close = $this->matchingBrace($contents, $open);

        if ($close === null) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $block = substr($contents, $open, $close - $open);
        $fallback = $this->certificateFiles->fallbackPaths();
        $current = $this->directive($block, 'certFile');

        if ($current === $fallback['certificate']) {
            return ['status' => 'current', 'reference' => null];
        }

        $panelCertificate = $this->panelCertificate($contents, $block, $panelHosts, $context);

        if ($current === null || $panelCertificate === null || $panelCertificate !== $current) {
            return ['status' => 'skipped', 'reference' => null];
        }

        if ($this->tlsFallback($context) === null) {
            return ['status' => 'failed', 'reference' => null];
        }

        $replaced = preg_replace(
            ['/^(\s*keyFile\s+)\S+/m', '/^(\s*certFile\s+)\S+/m'],
            ['${1}'.$fallback['private_key'], '${1}'.$fallback['certificate']],
            $block,
        );
        $updated = substr($contents, 0, $open).$replaced.substr($contents, $close);

        $backup = $path.'.panel-bak';
        $backedUp = $this->serverOps->run(['cp', '-f', $path, $backup], $context);

        if ($backedUp->failed()) {
            return ['status' => 'failed', 'reference' => $backedUp->reference];
        }

        $written = $this->files->put($path, $updated, $context);
        $test = $written->ok ? $this->test() : $written;

        if ($test->failed()) {
            $this->serverOps->run(['cp', '-f', $backup, $path], $context);

            return ['status' => 'failed', 'reference' => $test->reference];
        }

        $this->discardBackup($backup, $context);

        return ['status' => 'updated', 'reference' => null];
    }

    /**
     * Use the visitor's address from X-Forwarded-For, but only on a request from one of `$ranges`
     * (see CloudflareRealIp). OpenLiteSpeed's "Trusted IP Only" (2): its docs advise against "Yes"
     * (1), v7's setting, because then any visitor can choose the address the server logs and bans.
     * The trusted addresses are the server's Allowed List entries that end in `T`.
     *
     * Left alone: a server whose owner chose another mode (every server moved from v7 has 1), and one
     * whose Allowed List is not open to ALL (adding Cloudflare there would let it in).
     *
     * Applied only by a cold restart (OlsColdRestart): after a graceful one OpenLiteSpeed kept the
     * old trusted list.
     *
     * @param  array<int, string>  $ranges
     * @return array{status: 'updated'|'current'|'skipped'|'failed', reference: ?string}
     */
    public function trustProxies(array $ranges): array
    {
        return Cache::lock('ols-shared-config', 30)->block(20, fn () => $this->trustProxiesLocked($ranges));
    }

    /**
     * @param  array<int, string>  $ranges
     * @return array{status: 'updated'|'current'|'skipped'|'failed', reference: ?string}
     */
    private function trustProxiesLocked(array $ranges): array
    {
        $path = $this->path();
        $context = ['feature' => 'web_server', 'op' => 'real_ip'];
        $read = $this->files->get($path, $context);

        if ($read->failed()) {
            return ['status' => 'failed', 'reference' => $read->reference];
        }

        $contents = $read->output();
        $hasMode = preg_match('/^useIpInProxyHeader\s+(\d+)/m', $contents, $mode) === 1;

        if ($hasMode && $mode[1] !== '2') {
            return ['status' => 'skipped', 'reference' => null];
        }

        if (preg_match('/^accessControl\s*\{/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $start = $match[0][1];
        $open = $start + strlen($match[0][0]) - 1;
        $close = $this->matchingBrace($contents, $open);

        if ($close === null) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $block = substr($contents, $open, $close - $open);

        if (preg_match('/^(\s*allow\s+)(.*)$/m', $block, $allow) !== 1) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $entries = array_values(array_filter(array_map('trim', explode(',', $allow[2])), fn (string $entry) => $entry !== ''));

        if (! in_array('ALL', array_map('strtoupper', $entries), true)) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $missing = array_values(array_filter(
            array_map(fn (string $range) => $range.'T', $ranges),
            fn (string $entry) => ! in_array($entry, $entries, true),
        ));

        if ($missing === [] && $hasMode) {
            return ['status' => 'current', 'reference' => null];
        }

        $line = $allow[1].implode(', ', array_merge($entries, $missing));
        $block = preg_replace('/^\s*allow\s+.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $block, 1);
        $updated = substr($contents, 0, $open).$block.substr($contents, $close);

        // At the end of the file, where v7 has it: the same line above `accessControl` is ignored by
        // OpenLiteSpeed 1.9.4, at either value.
        if (! $hasMode) {
            $updated = rtrim($updated, "\n")."\nuseIpInProxyHeader       2\n";
        }

        $backup = $path.'.panel-bak';
        $backedUp = $this->serverOps->run(['cp', '-f', $path, $backup], $context);

        if ($backedUp->failed()) {
            return ['status' => 'failed', 'reference' => $backedUp->reference];
        }

        $written = $this->files->put($path, $updated, $context);
        $test = $written->ok ? $this->test() : $written;

        if ($test->failed()) {
            $this->serverOps->run(['cp', '-f', $backup, $path], $context);

            return ['status' => 'failed', 'reference' => $test->reference];
        }

        $this->discardBackup($backup, $context);

        return ['status' => 'updated', 'reference' => null];
    }

    /**
     * The copy has done its job once the new file passed its test: no copy stays on the user's server
     * unasked. Kept only while the change is in flight; a failure to remove it fails nothing.
     *
     * @param  array<string, mixed>  $context
     */
    private function discardBackup(string $backup, array $context): void
    {
        $this->serverOps->run(['rm', '-f', '--', $backup], $context + ['op' => 'ols_discard_backup']);
    }

    /**
     * The certificate on the `vhssl` of the vhost the listener maps a panel
     * host to, or null when there is no such vhost or it carries none.
     *
     * @param  array<int, string>  $panelHosts
     * @param  array<string, mixed>  $context
     */
    private function panelCertificate(string $contents, string $listenerBlock, array $panelHosts, array $context): ?string
    {
        preg_match_all('/^\s*map\s+(\S+)\s+(.+)$/m', $listenerBlock, $maps, PREG_SET_ORDER);

        foreach ($maps as $map) {
            $domains = preg_split('/[\s,]+/', trim($map[2])) ?: [];

            if (array_intersect($panelHosts, $domains) === []) {
                continue;
            }

            if (preg_match('/^virtualHost\s+'.preg_quote($map[1], '/').'\s*\{(.*?)^\}/ms', $contents, $vhost) !== 1
                || ($configFile = $this->directive($vhost[1], 'configFile')) === null) {
                continue;
            }

            $vhconf = $this->files->get(str_replace('$SERVER_ROOT', dirname($this->path(), 2), $configFile), $context);

            if ($vhconf->ok && preg_match('/^vhssl\s*\{(.*?)^\}/ms', $vhconf->output(), $ssl) === 1) {
                return $this->directive($ssl[1], 'certFile');
            }
        }

        return null;
    }

    private function directive(string $block, string $name): ?string
    {
        return preg_match('/^\s*'.$name.'\s+(\S+)/m', $block, $m) === 1 ? $m[1] : null;
    }

    /**
     * Runs the config check through `OlsConfigCheck`: `openlitespeed -t` exits 0 on any configuration
     * unless a directory OLS does not create in test mode exists, so a rollback wired to it directly
     * could never fire.
     */
    public function test(): ServerOpsResult
    {
        return $this->configCheck->run();
    }

    private function path(): string
    {
        return (string) config('server.web_server_drivers.openlitespeed.shared_config', '/usr/local/lsws/conf/httpd_config.conf');
    }
}
