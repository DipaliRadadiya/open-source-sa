<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Models\RuntimeInstall;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The ionCube Loader for one PHP version.
 *
 * Commercial PHP applications — WHMCS and most licensed scripts — ship their
 * source encrypted, and PHP cannot run a byte of it without this loader. It is
 * the one thing a hosting panel is expected to offer that apt cannot provide.
 *
 * Deliberately NOT part of {@see PhpExtensionManager}. That class's whole
 * model is "apt package → modules → ini in mods-available → phpenmod per
 * SAPI", and every operation it performs is `apt-get` or `phpenmod`. Here
 * ionCube is a `zend_extension` pointing at an absolute path to a
 * closed-source `.so` downloaded from the vendor — the same way on both
 * stacks. (LiteSpeed's repository does carry `lsphpNN-ioncube`; one installed
 * by v7 is reported as {@see SOURCE_EXTERNAL} and left alone.) Putting
 * it in that catalog would give the screen one row whose Install button means
 * something entirely different from every other row's.
 *
 * ⚠️ What this installs is a closed-source binary, fetched from ioncube.com,
 * loaded into every PHP process on the server. ionCube publishes no checksum
 * file — I checked for `.sha256` and `SHA256SUMS` and neither exists — so
 * there is nothing to verify the download against beyond TLS. What is done
 * instead: HTTPS only, a size cap, and the extracted file is checked to be an
 * ELF shared object for this machine's architecture before it is installed.
 * The SHA-256 of what was installed is recorded so an operator can compare it
 * across servers. That is a sanity check, not a supply-chain guarantee, and
 * the feature is off until somebody asks for it.
 *
 * One loader per PHP version, never shared: `ioncube_loader_lin_8.3.so` and
 * `..._8.4.so` are different binaries compiled against different PHP ABIs, and
 * loading the wrong one does not degrade — it stops PHP from starting at all,
 * for every site on that version at once. Which is why nothing here reloads a
 * web server before the stack's own config test has passed.
 */
class IonCubeLoader
{
    /**
     * `\x7fELF`. Checked before the file is installed, because the one thing
     * worse than a failed download is a successful one that is not a loader.
     */
    private const ELF_MAGIC = "\x7fELF";

    /**
     * ELF `e_machine`, at offset 18. The download URL is chosen by
     * architecture, so a mismatch here means the vendor's archive is not what
     * the URL says it is — worth refusing rather than installing.
     */
    private const ELF_MACHINES = ['x86_64' => 0x3E, 'aarch64' => 0xB7];

    /** Installed by this panel: every ini it writes starts with this line. */
    public const SOURCE_PANEL = 'panel';

    /**
     * Loaded, but not by this panel: v7 appended a `zend_extension` line to
     * php.ini, and on OpenLiteSpeed it installed LiteSpeed's
     * `lsphpXX-ioncube` package — which writes the same `01-ioncube.ini` name
     * this panel uses. Shown, never touched: installing over it loads ionCube
     * twice, and removing the package's file is undone by the next upgrade.
     */
    public const SOURCE_EXTERNAL = 'external';

    private const PANEL_MARKER = '; Managed by the panel.';

    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private PhpStack $stack,
    ) {}

    /**
     * What the PHP screen shows for this version.
     *
     * Read from the server every time, like every other PHP state in this
     * panel: the loader is a file on disk, and a stored flag would go on
     * claiming it was there after somebody removed the PHP version.
     *
     * @return array{supported: bool, installed: bool, source: ?string, php_version: string, loader_version: ?string, sha256: ?string, path: ?string}
     */
    public function status(string $version): array
    {
        // Asked on every version, supported or not. The panel offers ionCube
        // for 8.1+ only, but v7 installed it on 7.4 as well — and a card that
        // answered "not available" without looking said so about a loader
        // that was running.
        $source = $this->source($version);
        $panel = $source === self::SOURCE_PANEL;

        return [
            'supported' => $this->supports($version),
            'installed' => $source !== null,
            // `external`: installed outside the panel. Install and Remove are
            // refused for it; the card should say so instead of offering them.
            'source' => $source,
            'php_version' => $version,
            // Read out of PHP itself rather than from the filename, so it is
            // the version actually loaded and not the one we meant to install.
            'loader_version' => $source !== null ? $this->loadedVersion($version) : null,
            'sha256' => $panel ? $this->installedHash($version) : null,
            'path' => $panel ? $this->loaderPath($version) : null,
        ];
    }

    /**
     * Who installed the loader for this version, or null when there is none.
     *
     * The panel's own ini carries {@see PANEL_MARKER}. An ini under the same
     * name without it is LiteSpeed's package; a loader PHP reports with no ini
     * of ours is v7's php.ini line. Either is external.
     */
    public function source(string $version): ?string
    {
        if ($this->iniInstalled($version)) {
            return $this->panelWroteIni($version) ? self::SOURCE_PANEL : self::SOURCE_EXTERNAL;
        }

        if ($this->anyIniPresent($version) || $this->loadedVersion($version) !== null) {
            return self::SOURCE_EXTERNAL;
        }

        return null;
    }

    /**
     * The panel's own files for a version, for a PHP removal to delete once
     * the purge has succeeded — the purge leaves them, because dpkg never
     * owned them, and a leftover ini would load ionCube again the day that
     * version is reinstalled. Empty when the panel did not install it, or
     * when the version can no longer answer where its loader lives.
     *
     * @return array<int, string>
     */
    public function panelFiles(string $version): array
    {
        try {
            if ($this->source($version) !== self::SOURCE_PANEL) {
                return [];
            }

            $paths = [];
            foreach ($this->stack->sapis($version) as $sapi) {
                $paths[] = $this->iniPath($version, $sapi);
            }

            return [...array_unique($paths), $this->loaderPath($version)];
        } catch (Throwable $e) {
            Log::warning('ionCube files could not be listed for a PHP removal', [
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The sentence for a failed install run, in the viewer's locale.
     *
     * The run stores the exception's own cause (`ioncube_download_failed`),
     * and every one of those already has a sentence under `errors/php`. A
     * cause without one — the worker dying (`worker`), or a row written before
     * causes were kept (`install_failed`) — falls back to the shared install
     * messages rather than showing a code.
     */
    public function failureMessage(?RuntimeInstall $run, string $version): ?string
    {
        // A run that has not failed has no reason — the tracker clears it on
        // every start — so it falls through to message(), which says nothing.
        if ($run === null) {
            return null;
        }

        $key = 'errors/php.'.$run->reason;

        if ($run->reason !== null && Lang::has($key)) {
            return __($key, ['version' => $version, 'architecture' => php_uname('m')]);
        }

        return $run->message();
    }

    /**
     * Does ionCube publish a loader for this PHP version?
     *
     * A hint, not the arbiter. The archive is the authority and
     * {@see install()} refuses there if the file is genuinely absent — this
     * exists so the screen can say "not supported" without downloading 29 MB
     * to find out. PHP 8.0 is the live example: our panel offers it and the
     * current archive stops at 8.1.
     */
    public function supports(string $version): bool
    {
        return in_array($version, (array) config('server.ioncube.versions', []), true);
    }

    /**
     * Download, verify, install, test, reload.
     *
     * @throws PhpConfigException
     */
    public function install(string $version): void
    {
        if (! $this->supports($version)) {
            throw PhpConfigException::ionCubeUnsupportedVersion($version);
        }

        $this->refuseExternal($version);

        $loader = $this->loaderPath($version);
        $member = 'ioncube/'.basename($loader);
        $workDir = storage_path('app/ioncube/'.Str::random(8));
        $archive = $workDir.'/loaders.tar.gz';

        try {
            @mkdir($workDir, 0750, true);

            $this->download($archive);

            $extracted = $this->extract($archive, $member, $workDir, $version);

            $this->assertLoaderBinary($extracted, $version);

            $backups = $this->snapshot($version, $loader);
            try {
                $this->place($version, $extracted, $loader);
                $this->enable($version, $loader);
            } catch (Throwable $e) {
                $this->restore($version, $backups);
                throw $e;
            }

            $this->reload($version);
            $this->discardBackups($version, $backups);
        } finally {
            // The archive is 29 MB and the work directory is ours. Leaving it
            // behind would quietly fill the panel's own disk one install at a
            // time.
            $this->cleanUp($workDir);
        }
    }

    /**
     * Take the loader back out: the ini first, then the binary.
     *
     * In that order, and reloading in between is not needed — a `.so` nothing
     * references is inert, while an ini pointing at a file that has been
     * deleted stops PHP from starting.
     */
    public function remove(string $version): void
    {
        $this->refuseExternal($version);

        $loader = $this->loaderPath($version);
        $backups = $this->snapshot($version, $loader);
        try {
            foreach ($this->stack->sapis($version) as $sapi) {
                $result = $this->files->delete($this->iniPath($version, $sapi), $this->context($version, 'ioncube_remove_ini'));
                if ($result->failed()) {
                    throw PhpConfigException::ionCubeRemovalFailed($result->reference);
                }
            }

            $test = $this->stack->configTest($version);
            if ($test->failed()) {
                throw PhpConfigException::ionCubeConfigTestFailed($test->reference);
            }
            $result = $this->files->delete($loader, $this->context($version, 'ioncube_remove_loader'));
            if ($result->failed()) {
                throw PhpConfigException::ionCubeRemovalFailed($result->reference);
            }
        } catch (Throwable $e) {
            $this->restore($version, $backups);
            throw $e;
        }

        $this->reload($version);
        $this->discardBackups($version, $backups);
    }

    /**
     * Fetch the vendor archive.
     *
     * Per `laravel13-security-hardening-research-v2.md [1003–1087]`: TLS only,
     * bounded time, bounded size, and nothing about the response trusted
     * beyond the bytes landing on disk.
     *
     * @throws PhpConfigException
     */
    private function download(string $to): void
    {
        $url = (string) config('server.ioncube.urls.'.$this->architecture(), '');

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw PhpConfigException::ionCubeUnsupportedArchitecture($this->architecture());
        }

        try {
            $response = Http::timeout((int) config('server.ioncube.timeout', 300))
                ->connectTimeout(10)
                ->withOptions([
                    // A redirect to plain HTTP would hand us an archive
                    // somebody on the path chose. There is no checksum to
                    // catch that afterwards, so it is refused here.
                    'protocols' => ['https'],
                    'allow_redirects' => ['protocols' => ['https']],
                    'progress' => function ($total, $downloaded): void {
                        $limit = (int) config('server.ioncube.max_bytes', 104857600);
                        if ($total > $limit || $downloaded > $limit) {
                            throw new \RuntimeException('ionCube archive exceeds download limit');
                        }
                    },
                ])
                ->sink($to)
                ->get($url);
        } catch (Throwable $e) {
            throw PhpConfigException::ionCubeDownloadFailed($this->reference('download_failed', $e->getMessage()));
        }

        if (! $response->successful() || ! is_file($to) || filesize($to) === 0) {
            throw PhpConfigException::ionCubeDownloadFailed($this->reference('download_failed', 'status '.$response->status()));
        }

        if (filesize($to) > (int) config('server.ioncube.max_bytes', 104857600)) {
            throw PhpConfigException::ionCubeDownloadFailed($this->reference('too_large', (string) filesize($to)));
        }
    }

    /**
     * Pull exactly one file out of the archive.
     *
     * Named, never a wildcard extract: the archive holds every loader back to
     * PHP 4.2 plus a PDF, and we want one 500 KB file. `tar` runs as the panel
     * user here and is deliberately not in the sudo allowlist — extracting a
     * just-downloaded archive as root is the shape to avoid.
     *
     * @throws PhpConfigException
     */
    private function extract(string $archive, string $member, string $workDir, string $version): string
    {
        $listing = $this->serverOps->run(['tar', '-tzf', $archive], $this->context($version, 'ioncube_list'), timeout: 120);
        if ($listing->failed()) {
            throw PhpConfigException::ionCubeExtractionFailed($listing->reference);
        }
        if (! in_array($member, explode("\n", trim($listing->output())), true)) {
            throw PhpConfigException::ionCubeUnsupportedVersion($version);
        }
        $result = $this->serverOps->run(
            ['tar', '-xzf', $archive, '-C', $workDir, $member],
            $this->context('', 'ioncube_extract'),
            timeout: 120,
        );

        $path = $workDir.'/'.$member;

        if ($result->failed() || ! is_file($path)) {
            throw PhpConfigException::ionCubeExtractionFailed($result->reference);
        }

        return $path;
    }

    /**
     * Is this actually a loader for this machine?
     *
     * @throws PhpConfigException
     */
    private function assertLoaderBinary(string $path, string $version): void
    {
        $header = (string) @file_get_contents($path, length: 20);

        $machine = self::ELF_MACHINES[$this->architecture()] ?? null;

        $isElf = str_starts_with($header, self::ELF_MAGIC)
            && ord($header[4] ?? "\0") === 2 // 64-bit
            && ($machine === null || ord($header[18] ?? "\0") === $machine);

        if (! $isElf) {
            throw PhpConfigException::ionCubeInvalidLoader($this->reference('not_a_loader', $version));
        }
    }

    /**
     * Copy the loader into the PHP version's own extension directory.
     *
     * `install` rather than `cp`, because it sets the mode in the same call
     * and is already in the privilege allowlist — this adds no new sudo grant,
     * so no `panel:sudoers` run is needed on deploy.
     *
     * @throws PhpConfigException
     */
    private function place(string $version, string $extracted, string $loader): void
    {
        $result = $this->serverOps->run(
            ['install', '-m', '0644', $extracted, $loader],
            $this->context($version, 'ioncube_install_loader'),
        );

        if ($result->failed()) {
            throw PhpConfigException::ionCubeInstallFailed($result->reference);
        }
    }

    /**
     * Write the ini into every SAPI, test, and only then reload.
     *
     * 🔴 The ini is numbered `01-` so it loads **before** OPcache's `10-`.
     * Loading it after OPcache can immediately prevent PHP from starting.
     *
     * If the config test fails the caller restores the previous file state
     * without reloading. A bad `zend_extension` line does not break one site — it stops
     * PHP from starting for every site on this version.
     *
     * @throws PhpConfigException
     */
    private function enable(string $version, string $loader): void
    {
        $line = "; Managed by the panel. ionCube Loader for PHP {$version}.\n"
            ."zend_extension={$loader}\n";

        foreach ($this->stack->sapis($version) as $sapi) {
            $path = $this->iniPath($version, $sapi);

            // `tee` does not create parent directories, and a scan directory
            // is not guaranteed to exist: it comes from a package on the FPM
            // stacks and the panel has already been bitten once by assuming
            // otherwise (see SupervisorMissingException). Failing here is
            // loud but unhelpful — "No such file or directory" against a path
            // the user never chose.
            $dir = $this->serverOps->run(
                ['mkdir', '-p', dirname($path)],
                $this->context($version, 'ioncube_ensure_ini_dir'),
            );

            if ($dir->failed()) {
                throw PhpConfigException::ionCubeInstallFailed($dir->reference);
            }

            $written = $this->files->put($path, $line, $this->context($version, 'ioncube_write_ini'));

            if ($written->failed()) {
                throw PhpConfigException::ionCubeInstallFailed($written->reference);
            }
        }

        $test = $this->stack->configTest($version);

        if ($test->failed()) {
            throw PhpConfigException::ionCubeConfigTestFailed($test->reference);
        }
    }

    /** @return array<string, ?string> Original path => recovery copy, or null if absent. */
    private function snapshot(string $version, string $loader): array
    {
        // Unique adjacent copies survive download cleanup and a failed recovery.
        $suffix = '.panel-ioncube-'.Str::uuid().'.bak';
        $backups = [];
        $paths = [$loader];
        foreach ($this->stack->sapis($version) as $sapi) {
            $paths[] = $this->iniPath($version, $sapi);
        }
        foreach ($paths as $path) {
            $exists = $this->serverOps->run(['test', '-e', $path], $this->context($version, 'ioncube_snapshot'), expectedExitCodes: [1]);
            if (! $exists->answered) {
                throw PhpConfigException::ionCubeDiscoveryFailed($exists->reference);
            }
            $backups[$path] = null;
            if ($exists->ok) {
                $backup = $path.$suffix;
                $copy = $this->serverOps->run(['cp', '-p', $path, $backup], $this->context($version, 'ioncube_backup'));
                if ($copy->failed()) {
                    throw PhpConfigException::ionCubeInstallFailed($copy->reference);
                }
                $backups[$path] = $backup;
            }
        }

        return $backups;
    }

    /** @param array<string, ?string> $backups */
    private function restore(string $version, array $backups): void
    {
        // Restore an old binary before its INIs. For a fresh install, remove
        // INIs first and never delete the binary if any INI removal fails.
        $ordered = array_filter($backups, fn ($backup) => $backup !== null);
        foreach (array_reverse($backups, true) as $path => $backup) {
            if ($backup === null) {
                $ordered[$path] = null;
            }
        }
        foreach ($ordered as $path => $backup) {
            $result = $backup === null
                ? $this->files->delete($path, $this->context($version, 'ioncube_restore'))
                : $this->serverOps->run(['cp', '-p', $backup, $path], $this->context($version, 'ioncube_restore'));
            if ($result->failed()) {
                throw PhpConfigException::ionCubeRollbackFailed($result->reference);
            }
        }
        $this->discardBackups($version, $backups);
    }

    /** @param array<string, ?string> $backups */
    private function discardBackups(string $version, array $backups): void
    {
        foreach (array_filter($backups) as $backup) {
            // Cleanup failure is logged by ServerOps; it does not undo an
            // otherwise successful operation. The recovery copy is harmless.
            $this->files->delete($backup, $this->context($version, 'ioncube_cleanup_backup'));
        }
    }

    private function reload(string $version): void
    {
        $result = $this->stack->reload($version);
        if ($result->failed()) {
            throw PhpConfigException::ionCubeReloadFailed($result->reference);
        }
    }

    /**
     * Where the `.so` goes: PHP's own extension directory for this version.
     *
     * Asked of the interpreter rather than assembled from a convention. The
     * directory is an ABI stamp (`/usr/lib/php/20240924`), it differs between
     * the Debian packages and LiteSpeed's lsphp build, and the one place that
     * always knows it is PHP itself.
     */
    public function extensionDir(string $version): string
    {
        $result = $this->serverOps->run(
            [$this->stack->binaryPath($version), '-d', 'error_reporting=0', '-r', 'echo ini_get("extension_dir");'],
            $this->context($version, 'ioncube_extension_dir'),
        );

        $dir = rtrim(trim($result->output()), '/');
        if ($result->failed() || ! preg_match('~^/[a-zA-Z0-9_./+-]+$~D', $dir) || in_array('..', explode('/', $dir), true)) {
            throw PhpConfigException::ionCubeDiscoveryFailed($result->reference);
        }
        $exists = $this->serverOps->run(['test', '-d', $dir], $this->context($version, 'ioncube_extension_dir'));
        if ($exists->failed()) {
            throw PhpConfigException::ionCubeDiscoveryFailed($exists->reference);
        }

        return $dir;
    }

    public function loaderPath(string $version): string
    {
        return $this->extensionDir($version).'/'.$this->memberBasename($version);
    }

    /**
     * Thread safety decides which file: ionCube ships `_ts` and non-`_ts`
     * builds and they are not interchangeable. php-fpm and lsphp are both
     * non-thread-safe in every build the panel installs — but that is a fact
     * to read off the interpreter, not one to hard-code.
     */
    private function memberBasename(string $version): string
    {
        $suffix = $this->threadSafe($version) ? '_ts' : '';

        return "ioncube_loader_lin_{$version}{$suffix}.so";
    }

    private function threadSafe(string $version): bool
    {
        $result = $this->serverOps->run(
            [$this->stack->binaryPath($version), '-d', 'error_reporting=0', '-r', 'echo PHP_ZTS ? "1" : "0";'],
            $this->context($version, 'ioncube_thread_safety'),
        );

        $value = trim($result->output());
        if ($result->failed() || ! in_array($value, ['0', '1'], true)) {
            throw PhpConfigException::ionCubeDiscoveryFailed($result->reference);
        }

        return $value === '1';
    }

    /**
     * Where the ini goes — the directory this PHP really scans.
     *
     * 🔴 Was `sapiDir()."/conf.d"`, which is Debian's layout and wrong on
     * OpenLiteSpeed in both directions: LSPHP ships no `conf.d` (so `tee`
     * failed with "No such file or directory" and the install aborted) and
     * scans `mods-available` instead (so creating the directory would have
     * given an install that reported success and never loaded). The stack
     * answers this now; see PhpStack::scanDir().
     */
    private function iniPath(string $version, string $sapi): string
    {
        return $this->stack->scanDir($version, $sapi).'/'
            .(string) config('server.ioncube.ini_name', '01-ioncube.ini');
    }

    /** @throws PhpConfigException */
    private function refuseExternal(string $version): void
    {
        if ($this->source($version) === self::SOURCE_EXTERNAL) {
            throw PhpConfigException::ionCubeExternal($version);
        }
    }

    private function panelWroteIni(string $version): bool
    {
        foreach ($this->stack->sapis($version) as $sapi) {
            $read = $this->serverOps->run(
                ['cat', $this->iniPath($version, $sapi)],
                $this->context($version, 'ioncube_read_ini'),
            );

            if ($read->failed() || ! str_starts_with($read->output(), self::PANEL_MARKER)) {
                return false;
            }
        }

        return true;
    }

    private function anyIniPresent(string $version): bool
    {
        foreach ($this->stack->sapis($version) as $sapi) {
            if ($this->serverOps->probe(
                ['test', '-f', $this->iniPath($version, $sapi)],
                $this->context($version, 'ioncube_status'),
            )->ok) {
                return true;
            }
        }

        return false;
    }

    private function iniInstalled(string $version): bool
    {
        $sapis = $this->stack->sapis($version);

        foreach ($sapis as $sapi) {
            // `probe()`, not `run()`: `test -f` answers exit 1 for "no file",
            // which is the ordinary answer on every server without ionCube.
            // Through `run()` each one was logged as a failed server operation
            // and shown on the admin error dashboard as "Server operation
            // failed." with a reference — an alarming entry for a question
            // that had been answered correctly. Reported from a real server.
            if ($this->serverOps->probe(
                ['test', '-f', $this->iniPath($version, $sapi)],
                $this->context($version, 'ioncube_status'),
            )->failed()) {
                return false;
            }
        }

        return $sapis !== [];
    }

    /**
     * The loader version PHP reports, which is the only one worth showing.
     *
     * Null rather than a guess when the line is absent: that means the ini is
     * there and the loader did not load, and inventing a version number would
     * describe a working install.
     */
    private function loadedVersion(string $version): ?string
    {
        $result = $this->serverOps->run(
            [$this->stack->binaryPath($version), '-v'],
            $this->context($version, 'ioncube_loaded_version'),
        );

        preg_match('/ionCube PHP Loader.*?v([0-9][0-9.]*)/i', $result->output(), $matches);

        return $matches[1] ?? null;
    }

    private function installedHash(string $version): ?string
    {
        $result = $this->serverOps->run(
            ['openssl', 'dgst', '-sha256', $this->loaderPath($version)],
            $this->context($version, 'ioncube_hash'),
        );

        preg_match('/([a-f0-9]{64})/', $result->output(), $matches);

        return $matches[1] ?? null;
    }

    private function architecture(): string
    {
        return match (php_uname('m')) {
            'aarch64', 'arm64' => 'aarch64',
            default => 'x86_64',
        };
    }

    private function cleanUp(string $workDir): void
    {
        File::deleteDirectory($workDir);
    }

    /**
     * A reference for a failure that never shelled out, so there is no
     * server-ops result to borrow one from — the same reasoning
     * InstallerManager uses for its non-process failures.
     */
    private function reference(string $op, string $detail): string
    {
        $reference = (string) Str::uuid();

        Log::channel('server-ops')->error('ioncube loader failure', [
            'feature' => 'php',
            'op' => 'ioncube_'.$op,
            'detail' => $detail,
            'reference' => $reference,
        ]);

        return $reference;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $version, string $op): array
    {
        return ['feature' => 'php', 'op' => $op, 'version' => $version];
    }
}
