<?php

namespace App\Services\Server\WebServers;

use App\Contracts\PhpStack;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * OpenLiteSpeed.
 *
 * Two things make it unlike the other two drivers, and both are handled here
 * rather than leaking into the provisioner:
 *
 *  - **A site is a directory, not a file.** The config lives at
 *    `conf/vhosts/<name>/vhconf.conf`, so the directory has to exist first.
 *  - **A site is also two entries in the shared httpd_config.conf**, which is
 *    what `OlsSharedConfig` exists for.
 *
 * The site name is the domain. It is already validated to a hostname charset,
 * it is already unique per application, and inventing a second identifier
 * would mean two things to keep in step for no gain.
 *
 * ⚠️ **Unverified against a real OpenLiteSpeed server.** Every path, command
 * and directive here comes from LiteSpeed's documentation and OLS's own
 * shipped config, not from observation — there was no OLS box available when
 * this was written. That is why every path is in `config/server.php`: a wrong
 * one is a config change, not a code change. Treat the first run on a real box
 * as the actual test.
 */
class OlsDriver extends AbstractWebServerDriver
{
    public function __construct(
        ServerOps $serverOps,
        ManagedFile $files,
        private OlsSharedConfig $shared,
        private PhpStack $stack,
    ) {
        parent::__construct($serverOps, $files);
    }

    /**
     * The template needs the interpreter itself, not a socket path.
     *
     * nginx and Apache hand PHP to a pool that is already running and already
     * knows which binary it is. OLS starts the process, so the vhost has to
     * name the binary and the user — and `lsphp84`, not `lsphp8.4`.
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        $data = parent::viewData($application, $documentRoot);
        $version = (string) $data['phpVersion'];

        return [
            ...$data,
            'lsphpVersion' => str_replace('.', '', $version),
            'lsphpBinary' => $this->stack->binaryPath($version),
        ];
    }

    public function name(): string
    {
        return 'openlitespeed';
    }

    /**
     * Per-site directory, unlike the single file the other drivers write.
     */
    public function configPath(Application $application): string
    {
        $root = rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root', '/usr/local/lsws/conf/vhosts'), '/');

        return "{$root}/{$application->domain}/vhconf.conf";
    }

    /**
     * Directory, then vhost file, then the shared entries — in that order.
     *
     * The shared file goes last on purpose. It is the one that can take every
     * site on the box down, so it is only touched once the per-site config it
     * points at is actually on disk; a `virtualHost` block naming a file that
     * does not exist fails the config test and would strand the whole server
     * rather than just this site.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult
    {
        $context = ['feature' => 'application', 'op' => 'write_config', 'application' => $application->id];

        $directory = $this->serverOps->run(
            ['mkdir', '-p', dirname($this->configPath($application))],
            $context,
        );

        if ($directory->failed()) {
            return $directory;
        }

        $written = $this->files->put(
            $this->configPath($application),
            $this->renderConfig($application, $documentRoot),
            $context,
        );

        if ($written->failed()) {
            return $written;
        }

        return $this->shared->register($application->domain, $this->domains($application));
    }

    /**
     * Shared entries first this time, then the files.
     *
     * The mirror image of `apply()`, for the same reason: stop OLS referring to
     * the site before removing what it refers to. The other order leaves a
     * `virtualHost` pointing at a deleted file, which fails the config test and
     * blocks every later reload.
     */
    public function remove(Application $application): ServerOpsResult
    {
        $context = ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id];

        $unregistered = $this->shared->unregister($application->domain);

        if ($unregistered->failed()) {
            return $unregistered;
        }

        // Verify it actually went, rather than trusting a success that can be
        // vacuous: `unregister()` reports ok when nothing changed, and nothing
        // changes if the entries sit outside the managed markers — which is
        // what the WebAdmin console leaves behind, since the markers are
        // comments. Deleting the vhost file while the shared config still
        // names it breaks the config test for every site on the box.
        if ($this->shared->references($application->domain)) {
            return $this->shared->test();
        }

        $directory = dirname($this->configPath($application));
        $root = rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root', '/usr/local/lsws/conf/vhosts'), '/');

        // `rm -rf` on a path built from a record is the most destructive
        // command in the panel. The domain is validated to a hostname charset
        // long before it reaches here, but a blank one would make this the
        // vhost root itself — deleting the configuration of every site on the
        // server. Refuse rather than rely on validation upstream staying put.
        abort_unless($directory !== $root && str_starts_with($directory, $root.'/'), 500);

        return $this->serverOps->run(['rm', '-rf', $directory], $context);
    }

    /**
     * @return array<int, string>
     */
    private function domains(Application $application): array
    {
        return [$application->domain, 'www.'.$application->domain];
    }

    /**
     * @return array<int, string>
     */
    protected function testCommand(): array
    {
        return (array) config('server.web_server_drivers.openlitespeed.test_command');
    }

    /**
     * A restart, not a reload.
     *
     * `lswsctrl restart` is graceful — it starts new workers and lets the old
     * ones finish what they are serving. There is no cheaper option that picks
     * up a new virtual host, and on OLS a `.htaccess` or PHP configuration
     * change needs this too.
     *
     * @return array<int, string>
     */
    protected function reloadCommand(): array
    {
        return (array) config('server.web_server_drivers.openlitespeed.reload_command');
    }
}
