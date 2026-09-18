<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\PhpStack;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationConfigMutator;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Php\PhpShim;
use App\Services\Server\Php\RuntimeOwnership;
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
        ProvisionProgress $progress,
        protected PhpStack $stack,
        ApplicationConfigMutator $configMutator,
        ProcessSupervisor $supervisor,
        RuntimeOwnership $ownership,
        PhpShim $shim,
    ) {
        parent::__construct($serverOps, $progress, $configMutator, $supervisor, $ownership, $shim);
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
     * The command prefix an application's own CLI should run under: the
     * interpreter, plus the memory limit that run is allowed.
     *
     * **The binary** is the site's version, from the stack that serves it.
     * Every installer used to build this from `php_binary_pattern`, which is
     * the FPM answer — on an OpenLiteSpeed box the interpreter lives in the
     * lsws tree, and running /usr/bin/php there means installing an
     * application against a different PHP than the one that will serve it.
     *
     * **The memory limit** is set explicitly because the ambient one differs
     * per stack, and on one of them it is too small to install with. Debian's
     * CLI ini sets `memory_limit = -1`, so `/usr/bin/php8.4` on nginx and
     * Apache is effectively unlimited; LiteSpeed's lsphp lands on its
     * *production* ini at 128M instead, and Mautic's Symfony container compile
     * does not fit in it — "Allowed memory size of 134217728 bytes exhausted",
     * on OpenLiteSpeed only. Every PHP site type here runs a CLI step, so
     * Mautic was simply the heaviest and therefore the first to hit the wall.
     *
     * The site's own `ApplicationPhpSettings` cannot answer this: that value is
     * written into the FPM pool or the site's ini, which a CLI invocation never
     * reads. It also describes a request-serving budget, which is a different
     * job from a one-off container compile.
     *
     * Passing `-d` rather than editing a php.ini follows {@see MoodleInstaller},
     * which already does this for `max_input_vars`: fix the run, and leave the
     * ini the user may later want to own alone.
     *
     * Returned as an array, and `phpBinary()` deliberately no longer exists, so
     * that running an application's CLI *without* a limit is unexpressible
     * rather than merely discouraged — a new site type cannot forget it.
     *
     * @return array<int, string>
     */
    protected function phpCommand(Application $application): array
    {
        $version = (string) ($application->php_version ?: config('server.default_php_version', '8.4'));

        return [
            $this->stack->binaryPath($version),
            '-d',
            // Top level, and deliberately not inside `server.installers`: the
            // keys of that array are site types (`ProvisioningBudget` iterates
            // them), so a scalar in there would become a one-click app.
            'memory_limit='.(string) config('server.installer_php_memory_limit', '512M'),
        ];
    }
}
