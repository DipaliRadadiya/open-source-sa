<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\SiteInstaller;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationConfigMutator;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\ProvisioningBudget;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Php\PhpShim;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared machinery for marketplace installers: fetching an archive, writing a config file, and
 * running the application's own CLI as the site's user.
 *
 * Two rules every installer inherits:
 *  - **Secrets go over stdin, never on the command line**: they would show in `ps` to every user.
 *  - **The application's own commands run as the site user**, never as the panel: handing large
 *    bodies of third-party code root would make `application:manage` equivalent to root.
 */
abstract class AbstractSiteInstaller implements SiteInstaller
{
    public function __construct(
        protected ServerOps $serverOps,
        protected ProvisionProgress $progress,
        protected ApplicationConfigMutator $configMutator,
        protected ProcessSupervisor $supervisor,
        protected RuntimeOwnership $ownership,
        protected PhpShim $shim,
    ) {}

    /**
     * Which database engines this application can actually use.
     *
     * Most of the marketplace speaks MySQL, and MariaDB is a drop-in. An application that cannot
     * (NodeBB) says so here, so the panel can refuse early instead of failing at the app's own setup
     * with a driver error.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb'];
    }

    /**
     * Most applications name no minimum: they run on any version of an engine
     * they accept, and the panel has nothing to check.
     *
     * @return array<string, string>
     */
    public function minimumEngineVersions(): array
    {
        return [];
    }

    /**
     * The port the application has to be told about, or null when telling it would say nothing it
     * does not already assume.
     *
     * The panel's database connection is editable (`PUT /databases/connections/{engine}`), so a panel
     * pointed at a managed MySQL on 25060 would otherwise hand every new site a config naming 3306.
     *
     * Compared against what the *application* assumes when told nothing, not against
     * `config("server.databases.engines.{$engine}.default_port")`: that value is the panel's and is
     * env-overridable, so moving PostgreSQL to 5433 would silence exactly the case that needs the
     * port.
     *
     * Null rather than the number when they match: Joomla and PrestaShop document the port as
     * something you add *when it differs*, and Joomla treats `host` and `host:3306` as two
     * connections.
     *
     * @param  array<string, mixed>  $context
     */
    protected function enginePort(array $context): ?int
    {
        $assumed = match ($context['engine'] ?? '') {
            'mysql', 'mariadb' => 3306,
            'postgresql' => 5432,
            'mongodb' => 27017,
            default => null,
        };

        $port = (int) ($context['db_port'] ?? 0);

        // An engine we have no assumption for: write whatever we were given, since silence would be a
        // guess.
        if ($port <= 0 || $port === $assumed) {
            return null;
        }

        return $port;
    }

    /**
     * The database host with the port appended, for the applications whose only channel for a port is
     * the host field.
     *
     * Joomla's CLI has no `--db-port` and PrestaShop's no `--db_port`; both document `host:port`.
     * WordPress parses the same shape in `DB_HOST`.
     *
     * @param  array<string, mixed>  $context
     */
    protected function hostWithPort(array $context): string
    {
        $host = (string) ($context['db_host'] ?? '127.0.0.1');
        $port = $this->enginePort($context);

        return $port === null ? $host : "{$host}:{$port}";
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
     * Set one `KEY=value` line in a dotenv-style file, replacing or appending.
     *
     * Appends when the key is absent (a framework may drop a key from its example file between
     * releases), so a site's URL cannot silently stay at `localhost`. Returns whether anything
     * changed, so a caller can skip the cache clear that usually follows.
     */
    protected function setEnvValue(Application $application, string $path, string $key, string $value): bool
    {
        return $this->configMutator->transform($application, $path, function (string $contents) use ($key, $value): string {
            $line = "{$key}={$value}";
            $updated = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents, 1, $count);

            if (! is_string($updated)) {
                throw new RuntimeException("{$key} could not be updated.");
            }

            return $count === 1 ? $updated : rtrim($contents, "\n")."\n{$line}\n";
        });
    }

    /**
     * How many leading path components to strip when unpacking.
     *
     * Most applications ship in a single wrapping directory, so the default drops it. Joomla's full
     * package starts at `administrator/`; stripping there would scatter the top-level directories'
     * contents across the web root.
     */
    protected function stripComponents(): int
    {
        return 1;
    }

    /**
     * How this application's archive is packed.
     *
     * Mostly tarballs, but Mautic and Nextcloud publish zip. `unzip` has no `--strip-components`,
     * so a zip that wraps its contents names the directory in {@see archiveRoot()} instead.
     */
    protected function archiveFormat(): string
    {
        return 'tar';
    }

    /**
     * The directory inside the archive holding the application, for zips that wrap their contents —
     * null when the entries are already at the top.
     *
     * Named explicitly rather than detected: "the single top-level directory" silently copies the
     * wrong tree once an archive ships a stray `__MACOSX` or a readme beside the folder.
     */
    protected function archiveRoot(): ?string
    {
        return null;
    }

    /**
     * Where to fetch this application from.
     *
     * Overridable: some projects publish no stable "latest" URL and must be asked which version is
     * current.
     */
    protected function downloadUrl(): string
    {
        return (string) config("server.installers.{$this->siteType()}.download_url");
    }

    /**
     * How long this application's steps may take.
     *
     * Per-installer because applications differ by an order of magnitude (WordPress is a 25 MB
     * archive, Nextcloud 280 MB). {@see ProvisioningBudget} sizes the queued job from this, so the
     * two cannot disagree.
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
        // `-xf` rather than `-xzf`: tar detects the compression itself. It then shells out to a
        // decompressor the panel never declared (Nextcloud's bzip2 tarball failed on a server with no
        // `bzip2`), which is why Nextcloud takes the zip: `unzip` is already a required binary.
        $this->run('extract', $this->archiveFormat() === 'zip'
            // -q so a 90 MB listing doesn't end up in the ops log.
            ? ['unzip', '-q', $archive, '-d', "{$work}/src"]
            : array_filter([
                'tar', '-xf', $archive, '-C', "{$work}/src",
                $this->stripComponents() > 0 ? '--strip-components='.$this->stripComponents() : null,
            ]), $application);

        // Copy contents (not the directory) into the web root, overwriting so a retry converges
        // instead of nesting. Trailing `/.` on the source, not `-T` on the destination: when
        // web_root is empty $documentRoot IS the `current` symlink, which `cp -T` rejects and which
        // realpath() cannot resolve as the unprivileged queue worker. Past the wrapping directory
        // for a zip that has one; tar already dropped it via --strip-components, so this is null
        // everywhere else.
        $root = $this->archiveRoot();
        $source = $root === null ? "{$work}/src" : "{$work}/src/".trim($root, '/');

        $this->copyIntoSite($application, $source, $documentRoot, $work);

        // The copy above runs elevated, so everything it wrote is root-owned, and provisioning's
        // `set_ownership` ran before the download. The installer's own commands run as the site
        // user and a root-owned tree stops them (Mautic's cache directory). Done here so no
        // installer has to remember its own `chown -R`.
        //
        // Trailing `/.` as for the `cp` above: when web_root is empty, $documentRoot IS the `current`
        // symlink, and a plain `chown -R` would change the link itself, not the release it points at.
        $owner = $application->systemUser->username;
        $this->run('extract', ['chown', '-R', "{$owner}:{$owner}", "{$documentRoot}/."], $application);

        $this->serverOps->run(['rm', '-rf', $work], ['feature' => 'application', 'op' => 'installer.cleanup']);
    }

    /**
     * Build an application into its project root with Composer.
     *
     * The counterpart to {@see downloadAndExtract()} for projects that publish no archive. Two
     * things it has to get right:
     *
     *  - **`composer create-project` refuses a directory that is not empty**, and the project root
     *    never is by now (the provisioner already put a placeholder in `web/` or `public/`). So it
     *    builds into a temporary directory and copies the contents in, which also makes a retry
     *    converge rather than half-build.
     *  - **Composer runs as the site user**: as the panel it would write a root-owned tree and run
     *    the project's own post-install scripts as root.
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

        // Run *in* the work directory: the queue worker's own cwd is the panel's application root,
        // and Composer reads configuration from its working directory. An empty directory is fine
        // as a create-project target.
        //
        // Under the site's PHP, not the server's: Composer's shebang is `#!/usr/bin/env php`, so it
        // starts under the server default and resolves packages against that interpreter. For Craft
        // that silently resolves *backwards* (Craft 4 on a site reported as Craft 5). The shim is
        // the same one `GitDeployer` uses.
        $this->runAsSiteUser('download', $application, array_merge(
            $this->shim->envPrefixFor($application),
            [
                (string) config('server.composer_binary', 'composer'),
                'create-project', $package, $work,
            ],
            $flags,
        ), null, $work);

        // The placeholder lives in the document root, inside what is about to be copied over. These
        // projects ship their own front controller there, but relying on that would make the next
        // Composer application depend on whether it happens to ship an `index.php`.
        $this->run('extract', ['rm', '-f', "{$documentRoot}/index.php", "{$documentRoot}/index.html"], $application);

        // Contents, not the directory — `{$work}/.` for the same reason
        // downloadAndExtract() uses it.
        $this->copyIntoSite($application, $work, $projectRoot, $work);
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
    protected function writeSecretFile(Application $application, string $path, string $contents, ?string $mode = null): void
    {
        // A mode passed in is deliberate (a PHP file the web server must be
        // able to read, say); the default is private — see
        // RuntimeOwnership::secretFileMode().
        $mode ??= $this->ownership->secretFileMode($application);

        // Written and narrowed as the site user: every path here is in a tree that user already
        // owns, and root's `tee` and `chown` would follow a link planted at the path. As the user,
        // a link reaches only what they could already write.
        $this->runAsSiteUser('configure', $application, ['tee', $path], input: $contents);
        $this->runAsSiteUser('configure', $application, ['chmod', $mode, $path]);

        // The group is the one thing the user may not be able to set — PHP can
        // run as `www-data` — so it stays root's, with `-h` so a link is
        // changed itself rather than followed.
        $owner = $this->runtimePhpOwner($application);

        if ($owner !== $application->systemUser->username.':'.$application->systemUser->username) {
            $this->run('configure', ['chown', '-h', $owner, $path], $application);
        }
    }

    /**
     * Owner and group for a secret file: the site user, and whoever actually executes PHP for this
     * site.
     *
     * Isolation gives a site its own PHP-FPM pool running as its own user; without it PHP runs
     * under the shared pool as the web server's account, and a 0640 file owned by the site user is
     * unreadable by it (phpMyAdmin's "configuration file is not readable"). Handing the whole file
     * to `www-data` instead breaks the application's own CLI, which runs **as the site user** right
     * afterwards.
     *
     * So: **owner is always the site user, group is whoever runs PHP.** At 0640 that satisfies both.
     */
    private function runtimePhpOwner(Application $application): string
    {
        // Only a *PHP* site's secrets are read by php-fpm: a node site has no pool, and
        // `isolated_at` is only set for serving_profile=php, so the www-data branch wrongly handed
        // a node site's `.env` to an unrelated user (systemd still read it as root, which hid the
        // bug). Through RuntimeOwnership: `isolated_at` records an FPM pool and is null for every
        // site on OpenLiteSpeed, which would have handed the group to an account that does not run
        // PHP there.
        return $application->systemUser->username.':'.$this->ownership->secretFileGroup($application);
    }

    /**
     * Nothing, for the installers that finish before the process starts.
     */
    public function afterStart(Application $application, string $documentRoot): void {}

    /**
     * Copy a prepared tree into the site — as the site's own user.
     *
     * The destination is not always new ("Retry setup" copies into a document root the user has had
     * all along), and `cp` run as root writes *through* a symlink at a destination path, so a
     * planted link could redirect root's copy to a file of the user's choosing. As the user, a link
     * reaches only what the user could already write.
     *
     * The staging tree is handed to the user first so they can read it (it is the panel's own
     * temporary directory, under a name nobody could guess). So is the destination directory
     * itself, and only that: where the web root is a subfolder (Statamic, Craft serve
     * `public_html/public`) the provisioner leaves `public_html` root-owned and the user's `cp`
     * would be refused. `-h`, so a link at that path is the one thing changed, never what it points
     * to.
     *
     * @param  string  $work  the staging directory to hand over (the source or its parent)
     */
    protected function copyIntoSite(Application $application, string $source, string $destination, string $work): void
    {
        $owner = $application->systemUser->username;

        $this->run('extract', ['chown', '-R', "{$owner}:{$owner}", $work], $application);
        $this->run('extract', ['chown', '-h', "{$owner}:{$owner}", $destination], $application);
        $this->runAsSiteUser('extract', $application, ['cp', '-r', "{$source}/.", $destination]);
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
        $this->progress->begin($step);

        $result = $this->serverOps->run(
            $command,
            [
                'feature' => 'application',
                'op' => "installer.{$step}",
                'application' => $application->id,
                // Third-party installers report on stdout and are unreliable about exit codes
                // (PrestaShop returned 0 having installed nothing). They are quiet when they work,
                // so keeping the output is cheap and the only way to answer why one did nothing.
                'log_output' => true,
            ],
            timeout: $this->timeout(),
            input: $input,
            cwd: $cwd,
        );

        if ($result->failed()) {
            // Classified here, not at one call site: any step can be chosen by the OOM killer on a
            // small server, and every one reaches this line. Classifying in one installer would
            // leave `npm install`, `composer create-project` and the rest reporting a reference to
            // an empty log.
            throw ProvisioningFailedException::fromResult($step, $result, $command);
        }

        // Every installer command arrives here, so this one line gives all of them live progress.
        // Recorded after success: a step shown as done has to actually be done.
        $this->progress->record($step);

        // Returned so a caller that has to *verify* the command's effect can report the failure
        // against the command's own log entry.
        return $result;
    }
}
