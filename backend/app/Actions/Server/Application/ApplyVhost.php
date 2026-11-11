<?php

namespace App\Actions\Server\Application;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Rewrite one application's web-server configuration and put it live.
 *
 * Provisioning already does this as part of a longer sequence; a domain change
 * needs the same three steps on their own — write, test, reload — with the same
 * rule that a failed test puts the previous configuration back.
 *
 * Rolling back to the previous *contents* rather than removing the file, which
 * is what provisioning does: a new site has nothing to fall back to, but a live
 * site does, and taking its vhost away over a rejected domain would turn a
 * mistyped hostname into an outage.
 *
 * Those contents are read off disk. They used to be re-rendered from the
 * database, which is not the same thing and was wrong in both directions: the
 * render already reflects the change being applied — the new domain is saved
 * before this runs — so "restore the previous config" wrote the *rejected* one
 * back, and a file somebody had edited by hand was silently replaced by
 * template output the moment any unrelated action failed its config test. A
 * rollback that cannot reproduce what was serving is not a rollback.
 */
class ApplyVhost
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private ManagedFile $files,
    ) {}

    /**
     * @throws ProvisioningFailedException
     */
    public function execute(Application $application): void
    {
        $driver = $this->webServers->driver();
        $documentRoot = $this->provisioner->documentRoot($application);

        // Keep whatever is currently serving, so a rejected config can be
        // undone rather than merely deleted. Read, not rendered — see the note
        // on the class.
        $context = ['feature' => 'application', 'op' => 'read_config', 'application' => $application->id];

        // Every file a change can touch — the vhost, its HTTPS half and the
        // site's conf-folder rules — so a refused change puts all of them
        // back, not just the first: a rule file left behind would keep the
        // web server's config test failing for every site after this one.
        $previous = [];

        foreach (array_keys($driver->configFiles($application->load('domains'), $documentRoot)) as $path) {
            $previous[$path] = $this->files->get($path, $context);
        }

        $written = $driver->apply($application->load('domains'), $documentRoot);

        if ($written->failed()) {
            throw new ProvisioningFailedException('write_config', $written->reference);
        }

        $test = $driver->test();

        if ($test->failed()) {
            $this->restore($application, $previous);

            throw new ProvisioningFailedException('test_config', $test->reference);
        }

        $reload = $driver->reload();

        if ($reload->failed()) {
            throw new ProvisioningFailedException('reload', $reload->reference);
        }
    }

    /**
     * Put the previous configuration back and reload, so the site keeps
     * serving what it was serving a moment ago.
     *
     * Does nothing when the previous contents could not be read. That is the
     * normal case for a site whose vhost does not exist yet, and the dangerous
     * one otherwise: writing an empty file over a live config, because `cat`
     * was refused, would take the site down in the name of rescuing it. The
     * rejected config stays on disk instead — untested and unreloaded, so it
     * is not serving — and the caller's exception reports the failure.
     */
    /** @param  array<string, ServerOpsResult>  $previous */
    private function restore(Application $application, array $previous): void
    {
        $driver = $this->webServers->driver();
        $context = ['feature' => 'application', 'op' => 'restore_config', 'application' => $application->id];

        foreach ($previous as $path => $read) {
            if ($read->ok) {
                $this->files->put($path, $read->output(), $context);
                $driver->link($path, $context);

                continue;
            }

            // Not there before: whatever the change wrote goes again. Only on
            // "no such file" — a read that failed for any other reason says
            // nothing about whether the file was there.
            if (! str_contains($read->errorOutput(), 'No such file')) {
                continue;
            }

            if (! $driver->isSiteConfPath($application, $path)) {
                $this->files->delete($driver->enabledPathFor($path), $context);
            }
            $this->files->delete($path, $context);
        }

        if ($driver->test()->ok) {
            $driver->reload();
        }
    }
}
