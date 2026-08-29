<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\SiteInstaller;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationConfigMutator;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
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
        protected ApplicationConfigMutator $configMutator,
        protected ProcessSupervisor $supervisor,
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
     * Most installers persist no canonical URL. Applications that do own the
     * exact, application-native reconciliation command in their class.
     */
    public function syncUrl(Application $application, string $url): void
    {
        // Deliberate no-op.
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
     * and Nextcloud publish zip. `unzip` has no equivalent of tar's
     * `--strip-components`, so a zip that wraps its contents in a directory
     * names it in {@see archiveRoot()} instead.
     */
    protected function archiveFormat(): string
    {
        return 'tar';
    }

    /**
     * The directory inside the archive holding the application, for zips that
     * wrap their contents — null when the entries are already at the top.
     *
     * This is `stripComponents()`'s job for tarballs, done by tar itself. A
     * zip has no such flag, so the wrapper is stepped over when copying out
     * instead. Named explicitly rather than detected: "the single top-level
     * directory" is a guess that silently copies the wrong tree the first time
     * an archive ships a stray `__MACOSX` or a readme beside the folder.
     */
    protected function archiveRoot(): ?string
    {
        return null;
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
        // in, so tar detects the compression from the file itself and the
        // installer does not have to know or care. Note this makes tar shell
        // out to a decompressor the panel never declared — Nextcloud's bzip2
        // tarball failed here on a server with no `bzip2`, reporting a
        // missing `lbzip2` that appears nowhere in this codebase. That is why
        // Nextcloud takes the zip: `unzip` is already a required binary.
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
        // Past the wrapping directory for a zip that has one — tar already
        // dropped it via --strip-components, so this is null everywhere else.
        $root = $this->archiveRoot();
        $source = $root === null ? "{$work}/src" : "{$work}/src/".trim($root, '/');

        $this->run('extract', ['cp', '-r', "{$source}/.", $documentRoot], $application);

        // The copy above runs elevated, so everything it just wrote is owned
        // by root. Provisioning's `set_ownership` step cannot help: it runs
        // *before* the download, so it chowns an empty directory and never
        // sees these files.
        //
        // Whatever the installer does next runs as the site's own user, and a
        // root-owned tree stops it dead — Mautic's `mautic:install` failed
        // with "Unable to create the cache directory
        // (.../var/cache/prod)" for exactly this reason. Six installers had
        // each grown their own `chown -R` afterwards to compensate; doing it
        // here instead means the next one added does not have to remember,
        // and does not get a bug report first.
        //
        // Trailing `/.` for the same reason the `cp` above uses it: when
        // web_root is empty, $documentRoot IS the `current` symlink, and a
        // plain `chown -R` on a symlink argument changes the link itself
        // rather than descending into the release it points at — which would
        // leave every file exactly as root-owned as before.
        $owner = $application->systemUser->username;
        $this->run('extract', ['chown', '-R', "{$owner}:{$owner}", "{$documentRoot}/."], $application);

        $this->serverOps->run(['rm', '-rf', $work], ['feature' => 'application', 'op' => 'installer.cleanup']);
    }

    /**
     * Build an application into its project root with Composer.
     *
     * The counterpart to {@see downloadAndExtract()} for the projects that
     * publish no archive. Two things it has to get right, and both were wrong
     * when Craft and Statamic each did this inline:
     *
     *  - **`composer create-project` refuses a directory that is not empty**,
     *    and the project root never is by the time an installer runs. Both of
     *    these applications serve from a subdirectory (`web/`, `public/`), so
     *    the provisioner has already created that subdirectory *inside* the
     *    project root and written a placeholder into it. Composer stopped with
     *    "Project directory ... is not empty" before it fetched a single
     *    package, which made one-click Craft and Statamic fail every time.
     *    Building into a temporary directory and copying the contents in
     *    sidesteps it, and is also what makes a retry after a failure converge
     *    rather than half-build.
     *  - **Composer runs as the site user**, like every other application's own
     *    tooling here. Run as the panel it writes a root-owned tree and runs
     *    the project's own post-install scripts as root — Statamic's
     *    `create-project` runs several — which is exactly the privilege the
     *    rest of this class is careful not to hand third-party code.
     *
     * @param  array<int, string>  $flags  passed to create-project verbatim
     *
     * @throws ProvisioningFailedException
     */
    protected function composerCreateProject(
        Application $application,
        string $package,
        string $projectRoot,
        string $documentRoot,
        array $flags = [],
    ): void {
        $owner = $application->systemUser->username;

        $work = rtrim((string) config('server.installer_work_dir', sys_get_temp_dir()), '/')
            .'/composer-'.$application->id;

        // A leftover from an earlier attempt would fail create-project for the
        // very reason this method exists.
        $this->run('download', ['rm', '-rf', $work], $application);
        $this->run('download', ['mkdir', '-p', $work], $application);
        // `mkdir` ran elevated, so the directory belongs to root; Composer runs
        // as the site user and has to be able to write into it.
        $this->run('download', ['chown', "{$owner}:{$owner}", $work], $application);

        // Run *in* the work directory rather than wherever the queue worker
        // happens to be: that is the panel's own application root, and Composer
        // reads configuration from its working directory. An empty directory is
        // fine as a create-project target — only a non-empty one is refused.
        $this->runAsSiteUser('download', $application, array_merge([
            (string) config('server.composer_binary', 'composer'),
            'create-project', $package, $work,
        ], $flags), null, $work);

        // The placeholder lives in the document root, which is a directory
        // inside what is about to be copied over. Both of these projects ship
        // their own front controller there and would overwrite it anyway, but
        // relying on that would make the next Composer application's behaviour
        // depend on whether it happens to ship an `index.php`.
        $this->run('extract', ['rm', '-f', "{$documentRoot}/index.php", "{$documentRoot}/index.html"], $application);

        // Contents, not the directory — `{$work}/.` for the same reason
        // downloadAndExtract() uses it.
        $this->run('extract', ['cp', '-r', "{$work}/.", $projectRoot], $application);
        $this->run('extract', ['rm', '-rf', $work], $application);

        // The copy ran elevated even though the build did not, so the tree
        // arrives root-owned; whatever the installer runs next runs as the site
        // user and would be stopped by it.
        $this->run('extract', ['chown', '-R', "{$owner}:{$owner}", "{$projectRoot}/."], $application);
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
        $this->run('configure', ['chown', $this->runtimePhpOwner($application), $path], $application);
    }

    /**
     * Owner and group for a secret file: the site user, and whoever actually
     * executes PHP for this site.
     *
     * Two accounts need this file and they are not always the same one.
     * Isolation gives a site its own PHP-FPM pool running as its own user;
     * without it PHP runs under the shared, server-wide pool as the web
     * server's own account. A 0640 file owned by the site user is unreadable
     * by that pool — confirmed in production as phpMyAdmin's "Existing
     * configuration file ... is not readable" on a non-isolated site.
     *
     * Handing the whole file to `www-data` fixed that and broke the other
     * side: the application's own CLI runs **as the site user** immediately
     * afterwards, and `wp core install`, `craft install`, `mautic:install` and
     * Moodle's installer all read the file this method just made unreadable to
     * them. Nobody saw it on nginx, because `ApplicationProvisioner` creates a
     * pool before installing and PHP-FPM is the only stack `PoolManager` calls
     * supported() for — so on OpenLiteSpeed, where no pool is ever made, every
     * one of those installs failed and only there.
     *
     * So: **owner is always the site user, group is whoever runs PHP.** At
     * 0640 that satisfies both, and it stops being a choice between them.
     */
    private function runtimePhpOwner(Application $application): string
    {
        // Only a *PHP* site's secrets are read by php-fpm. A node site has no
        // pool at all — its systemd unit runs as the site's own user — and
        // `isolated_at` is never set for one, because that flag is written by
        // the create_php_pool step which only runs for serving_profile=php.
        //
        // So the www-data branch was being taken for every node site, handing
        // its `.env` to a user that has nothing to do with running it. systemd
        // reads EnvironmentFile as root and so still started the app, which is
        // what made this quiet: the site came up, and only the things running
        // *as the site* — the file manager, the app's own tooling — found a
        // file they could neither read nor write.
        $runsAsSiteUser = $application->serving_profile !== 'php'
            || $application->isolated_at !== null;

        $user = $application->systemUser->username;

        $group = $runsAsSiteUser
            ? $user
            : (string) config('server.web_server_user', 'www-data');

        return "{$user}:{$group}";
    }

    /**
     * Run a command as the site's own user.
     *
     * @param  array<int, string>  $command
     *
     * @throws ProvisioningFailedException
     */
    protected function runAsSiteUser(string $step, Application $application, array $command, ?string $input = null, ?string $cwd = null): ServerOpsResult
    {
        return $this->run($step, array_merge(
            ['runuser', '-u', $application->systemUser->username, '--'],
            $command,
        ), $application, $input, $cwd);
    }

    /**
     * @param  array<int, string>  $command
     *
     * @throws ProvisioningFailedException
     */
    protected function run(string $step, array $command, Application $application, ?string $input = null, ?string $cwd = null): ServerOpsResult
    {
        $result = $this->serverOps->run(
            $command,
            [
                'feature' => 'application',
                'op' => "installer.{$step}",
                'application' => $application->id,
                // Third-party installers report what they did on stdout and
                // are not reliable about exit codes — PrestaShop's returned 0
                // having installed nothing. These commands are quiet when they
                // work, so keeping their output costs little and is the only
                // way to answer why one did nothing.
                'log_output' => true,
            ],
            timeout: $this->timeout(),
            input: $input,
            cwd: $cwd,
        );

        if ($result->failed()) {
            // Classified here rather than at any one call site, because the
            // failure this names is not specific to a command: any step can be
            // chosen by the OOM killer on a small server, and every one of
            // them reaches this line. Classifying in `NodeBbInstaller` — where
            // the problem was found — would have fixed the build and left
            // `npm install`, `composer create-project` and the rest reporting
            // a reference to an empty log.
            throw ProvisioningFailedException::fromResult($step, $result);
        }

        // Every command an installer runs arrives here, so this one line is
        // what gives all of them live progress — no installer needs to keep a
        // list, and none can forget to. Recorded after success: a step the user
        // is shown as done has to actually be done.
        $this->progress->record($step);

        // Returned so a caller that has to *verify* the command's effect can
        // report the failure against the command's own log entry, rather than
        // against whatever probe discovered the problem afterwards.
        return $result;
    }
}
