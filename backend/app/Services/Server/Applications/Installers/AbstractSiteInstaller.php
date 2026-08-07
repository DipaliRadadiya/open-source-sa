<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\SiteInstaller;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;

/**
 * Shared machinery for marketplace installers: fetching an archive, writing a
 * config file, and running the application's own CLI as the site's user.
 *
 * Two rules every installer inherits:
 *  - **Secrets go over stdin, never on the command line.** Admin passwords and
 *    database credentials would otherwise be visible in `ps` to every user on
 *    the box.
 *  - **The application's own commands run as the site user**, never as the
 *    panel. WordPress and friends are large bodies of third-party code; giving
 *    them root because the panel happens to have it would make
 *    `application:manage` equivalent to root.
 */
abstract class AbstractSiteInstaller implements SiteInstaller
{
    public function __construct(
        protected ServerOps $serverOps,
        protected ProvisionProgress $progress,
    ) {}

    /**
     * Which database engines this application can actually use.
     *
     * Most of the marketplace speaks MySQL, and MariaDB is a drop-in for it.
     * Anything else says so — NodeBB cannot use either, and handing it a MySQL
     * database would fail at its own setup with an error about a driver, long
     * after the point where the panel could have said something useful.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb'];
    }

    /**
     * How many leading path components to strip when unpacking.
     *
     * Most applications ship inside a single wrapping directory, so the
     * default drops it. Joomla's full package does not — its entries start at
     * `administrator/` — and stripping there would discard the top-level
     * directories and scatter their contents across the web root.
     */
    protected function stripComponents(): int
    {
        return 1;
    }

    /**
     * How this application's archive is packed.
     *
     * Applications ship in whatever they ship in: tarballs mostly, but Mautic
     * publishes zip only. `unzip` has no equivalent of tar's
     * `--strip-components`, so a zip that needs stripping would need more than
     * a flag — none of ours do.
     */
    protected function archiveFormat(): string
    {
        return 'tar';
    }

    /**
     * Where to fetch this application from.
     *
     * Overridable because not every project publishes a stable "latest" URL;
     * some have to be asked which version is current before anything can be
     * downloaded.
     */
    protected function downloadUrl(): string
    {
        return (string) config("server.installers.{$this->siteType()}.download_url");
    }

    /**
     * How long this application's steps may take.
     *
     * Per-installer because the applications differ by an order of magnitude:
     * WordPress is a 25 MB archive, Nextcloud a 280 MB one, and a timeout
     * sized for the first turns the second into a guaranteed failure on any
     * ordinary connection. {@see ProvisioningBudget}
     * sizes the queued job from this, so the two cannot disagree.
     */
    protected function timeout(): int
    {
        return (int) config(
            "server.installers.{$this->siteType()}.timeout",
            config('server.installer_timeout', 300),
        );
    }

    /**
     * Download an archive over HTTPS and unpack it into the document root.
     *
     * Unpacked into a temp directory first, then moved — a half-downloaded or
     * malformed archive must not be left strewn across a live web root.
     *
     * @throws ProvisioningFailedException
     */
    protected function downloadAndExtract(Application $application, ?string $url, string $documentRoot): void
    {
        $url ??= $this->downloadUrl();

        $work = rtrim((string) config('server.installer_work_dir', sys_get_temp_dir()), '/')
            .'/install-'.Str::uuid();
        $archive = "{$work}/archive.tar.gz";

        $this->run('download', ['mkdir', '-p', $work], $application);

        // --fail so an HTML error page is never mistaken for an archive;
        // --proto to refuse anything but https, including on redirects.
        $this->run('download', [
            'curl', '--fail', '--location', '--silent', '--show-error',
            '--proto', '=https', '--proto-redir', '=https',
            '--max-time', (string) $this->timeout(),
            '--output', $archive, $url,
        ], $application);

        $this->run('extract', ['mkdir', '-p', "{$work}/src"], $application);
        // `-xf` rather than `-xzf`: applications ship in whatever they ship
        // in, and Nextcloud's only tarball is bzip2. tar detects the
        // compression from the file itself, so the installer does not have to
        // know or care.
        $this->run('extract', $this->archiveFormat() === 'zip'
            // -q so a 90 MB listing doesn't end up in the ops log.
            ? ['unzip', '-q', $archive, '-d', "{$work}/src"]
            : array_filter([
                'tar', '-xf', $archive, '-C', "{$work}/src",
                $this->stripComponents() > 0 ? '--strip-components='.$this->stripComponents() : null,
            ]), $application);

        // Copy contents (not the directory) into the web root, overwriting so
        // a retry converges instead of nesting.
        //
        // Trailing `/.` on the source, not `-T` on the destination. `-T` was
        // tried first and reverted: it requires the destination to actually
        // *be* a directory rather than merely resolve to one, and when
        // web_root is empty $documentRoot IS the `current` symlink itself
        // (ReleaseManager points it at releases/<timestamp>) — cp -T sees
        // "not a directory" instead of following it. Resolving the symlink
        // in PHP first doesn't work either: this runs unprivileged as the
        // panel queue worker, which has no access into a site owned by its
        // own system user, so realpath() silently failed and fell back to
        // the same broken path. `src/.` sidesteps the whole problem — cp's
        // normal (non -T) directory-destination handling follows a symlink
        // to a directory exactly like a real one, and it runs elevated via
        // ServerOps regardless, so permissions were never actually the
        // blocker for the copy itself.
        $this->run('extract', ['cp', '-r', "{$work}/src/.", $documentRoot], $application);

        $this->serverOps->run(['rm', '-rf', $work], ['feature' => 'application', 'op' => 'installer.cleanup']);
    }

    /**
     * Write a file whose contents are sensitive (database credentials, keys).
     * Contents travel over stdin and the file is locked down immediately.
     *
     * @throws ProvisioningFailedException
     */
    protected function writeSecretFile(Application $application, string $path, string $contents, string $mode = '0640'): void
    {
        $this->run('configure', ['tee', $path], $application, input: $contents);
        $this->run('configure', ['chmod', $mode, $path], $application);
        $this->run('configure', [
            'chown',
            "{$application->systemUser->username}:{$application->systemUser->username}",
            $path,
        ], $application);
    }

    /**
     * Run a command as the site's own user.
     *
     * @param  array<int, string>  $command
     *
     * @throws ProvisioningFailedException
     */
    protected function runAsSiteUser(string $step, Application $application, array $command, ?string $input = null, ?string $cwd = null): void
    {
        $this->run($step, array_merge(
            ['runuser', '-u', $application->systemUser->username, '--'],
            $command,
        ), $application, $input, $cwd);
    }

    /**
     * @param  array<int, string>  $command
     *
     * @throws ProvisioningFailedException
     */
    protected function run(string $step, array $command, Application $application, ?string $input = null, ?string $cwd = null): void
    {
        $result = $this->serverOps->run(
            $command,
            ['feature' => 'application', 'op' => "installer.{$step}", 'application' => $application->id],
            timeout: $this->timeout(),
            input: $input,
            cwd: $cwd,
        );

        if ($result->failed()) {
            throw new ProvisioningFailedException($step, $result->reference);
        }

        // Every command an installer runs arrives here, so this one line is
        // what gives all of them live progress — no installer needs to keep a
        // list, and none can forget to. Recorded after success: a step the user
        // is shown as done has to actually be done.
        $this->progress->record($step);
    }
}
