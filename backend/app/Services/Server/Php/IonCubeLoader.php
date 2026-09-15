<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Http;
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
 * SAPI", and every operation it performs is `apt-get` or `phpenmod`. ionCube
 * has no package and no repository: it is a `zend_extension` pointing at an
 * absolute path to a closed-source `.so` downloaded from the vendor. Putting
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
     * @return array{supported: bool, installed: bool, php_version: string, loader_version: ?string, sha256: ?string, path: ?string}
     */
    public function status(string $version): array
    {
        if (! $this->supports($version)) {
            return [
                'supported' => false,
                'installed' => false,
                'php_version' => $version,
                'loader_version' => null,
                'sha256' => null,
                'path' => null,
            ];
        }

        $installed = $this->iniInstalled($version);

        return [
            'supported' => true,
            'installed' => $installed,
            'php_version' => $version,
            // Read out of PHP itself rather than from the filename, so it is
            // the version actually loaded and not the one we meant to install.
            'loader_version' => $installed ? $this->loadedVersion($version) : null,
            'sha256' => $installed ? $this->installedHash($version) : null,
            'path' => $installed ? $this->loaderPath($version) : null,
        ];
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

        $workDir = storage_path('app/ioncube/'.Str::random(8));
        $archive = $workDir.'/loaders.tar.gz';

        try {
            @mkdir($workDir, 0750, true);

            $this->download($archive);

            $member = $this->memberFor($version);
            $extracted = $this->extract($archive, $member, $workDir);

            $this->assertLoaderBinary($extracted, $version);

            $this->place($version, $extracted);
            $this->enable($version);
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
        foreach ($this->stack->sapis($version) as $sapi) {
            $this->files->delete($this->iniPath($version, $sapi), $this->context($version, 'ioncube_remove_ini'));
        }

        $this->files->delete($this->loaderPath($version), $this->context($version, 'ioncube_remove_loader'));

        $this->stack->reload($version);
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
                    'redirect.protocols' => ['https'],
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
    private function extract(string $archive, string $member, string $workDir): string
    {
        $result = $this->serverOps->run(
            ['tar', '-xzf', $archive, '-C', $workDir, $member],
            $this->context('', 'ioncube_extract'),
            timeout: 120,
        );

        $path = $workDir.'/'.$member;

        if ($result->failed() || ! is_file($path)) {
            // The likeliest cause by far: ionCube does not ship a loader for
            // this PHP version, and the configured list said otherwise.
            throw PhpConfigException::ionCubeUnsupportedVersion($version);
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
    private function place(string $version, string $extracted): void
    {
        $result = $this->serverOps->run(
            ['install', '-m', '0644', $extracted, $this->loaderPath($version)],
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
     * ionCube has to be in place before OPcache starts caching compiled code,
     * and the failure when it is not is the worst kind: the site works until
     * the cache warms up.
     *
     * If the config test fails the ini comes straight back out and nothing is
     * reloaded. A bad `zend_extension` line does not break one site — it stops
     * PHP from starting for every site on this version.
     *
     * @throws PhpConfigException
     */
    private function enable(string $version): void
    {
        $line = "; Managed by the panel. ionCube Loader for PHP {$version}.\n"
            ."zend_extension={$this->loaderPath($version)}\n";

        foreach ($this->stack->sapis($version) as $sapi) {
            $written = $this->files->put($this->iniPath($version, $sapi), $line, $this->context($version, 'ioncube_write_ini'));

            if ($written->failed()) {
                $this->rollBack($version);

                throw PhpConfigException::ionCubeInstallFailed($written->reference);
            }
        }

        $test = $this->stack->configTest($version);

        if ($test->failed()) {
            $this->rollBack($version);

            throw PhpConfigException::ionCubeConfigTestFailed($test->reference);
        }

        $this->stack->reload($version);
    }

    /**
     * Undo a half-applied install without reloading anything.
     *
     * The web server is still running the configuration it had before this
     * ran, which is a working one. Reloading here is the one action that could
     * turn a failed install into a downed server.
     */
    private function rollBack(string $version): void
    {
        foreach ($this->stack->sapis($version) as $sapi) {
            $this->files->delete($this->iniPath($version, $sapi), $this->context($version, 'ioncube_rollback'));
        }

        $this->files->delete($this->loaderPath($version), $this->context($version, 'ioncube_rollback'));
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

        return rtrim(trim($result->output()), '/');
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

    private function memberFor(string $version): string
    {
        return 'ioncube/'.$this->memberBasename($version);
    }

    private function threadSafe(string $version): bool
    {
        $result = $this->serverOps->run(
            [$this->stack->binaryPath($version), '-d', 'error_reporting=0', '-r', 'echo PHP_ZTS ? "1" : "0";'],
            $this->context($version, 'ioncube_thread_safety'),
        );

        return trim($result->output()) === '1';
    }

    private function iniPath(string $version, string $sapi): string
    {
        return $this->stack->sapiDir($version, $sapi).'/conf.d/'
            .(string) config('server.ioncube.ini_name', '01-ioncube.ini');
    }

    private function iniInstalled(string $version): bool
    {
        $sapis = $this->stack->sapis($version);

        foreach ($sapis as $sapi) {
            if ($this->serverOps->run(
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
        foreach ((array) glob($workDir.'/{,*/}*', GLOB_BRACE) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        @rmdir($workDir);
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
