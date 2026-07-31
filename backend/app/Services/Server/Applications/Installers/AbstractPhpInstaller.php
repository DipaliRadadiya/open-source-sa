<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\PhpStack;
use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * Shared machinery for the PHP marketplace: WordPress, Moodle, Nextcloud and
 * the rest.
 *
 * Split from the base so a Node application does not carry a PHP stack it
 * never asks a question of. The two families share fetching and file writing;
 * they share nothing about which interpreter runs the application's own CLI.
 */
abstract class AbstractPhpInstaller extends AbstractSiteInstaller
{
    public function __construct(
        ServerOps $serverOps,
        protected PhpStack $stack,
    ) {
        parent::__construct($serverOps);
    }

    /**
     * Nearly every PHP application in the catalog stores its content in a
     * database; the ones that don't (Statamic) say so.
     */
    public function needsDatabase(): bool
    {
        return true;
    }

    /**
     * Nothing to run: a PHP application is served from its directory.
     *
     * Left unimplemented on the shared base on purpose, so a Node installer
     * that forgets it is a fatal error at boot rather than an application that
     * quietly never starts.
     */
    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return null;
    }

    /**
     * The PHP binary an application's own CLI should run under.
     *
     * The site's version, from the stack that serves it. Every installer used
     * to build this from `php_binary_pattern`, which is the FPM answer — on an
     * OpenLiteSpeed box the interpreter lives in the lsws tree, and running
     * /usr/bin/php there means installing an application against a different
     * PHP than the one that will serve it.
     */
    protected function phpBinary(Application $application): string
    {
        $version = (string) ($application->php_version ?: config('server.default_php_version', '8.4'));

        return $this->stack->binaryPath($version);
    }
}
