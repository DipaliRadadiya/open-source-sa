<?php

namespace App\Contracts;

use App\Models\Application;
use App\Services\Server\ServerOpsResult;

/**
 * One web server the panel can configure. Only one runs on a server — they
 * fight over port 80 — so exactly one driver is active at a time.
 *
 * A driver knows its own config paths, syntax, test command and reload
 * command. Nothing outside a driver may name a config file or a reload
 * command, which is what lets nginx / apache / OpenLiteSpeed differ without
 * the rest of the feature caring.
 */
interface WebServerDriver
{
    /** nginx | apache | openlitespeed */
    public function name(): string;

    /** Absolute path of the site's config file. */
    public function configPath(Application $application): string;

    /**
     * Render the site config for the application's serving profile.
     *
     * Profiles, not site types: `php` and `static` between them cover every
     * PHP app we ship, so adding WordPress #19 costs no template work.
     */
    public function renderConfig(Application $application, string $documentRoot): string;

    /**
     * Check the configuration is valid. **Must** be called before any reload —
     * reloading a broken config takes every other site on the box down with it.
     */
    public function test(): ServerOpsResult;

    /** Apply the configuration without dropping connections. */
    public function reload(): ServerOpsResult;
}
