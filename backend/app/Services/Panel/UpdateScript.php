<?php

namespace App\Services\Panel;

use App\Models\PanelUpdate;

/**
 * Renders the shell script that performs an in-place update.
 *
 * Why a shell script and not PHP: the update replaces the PHP source of the
 * process performing it. A long-lived PHP runner would be executing code that
 * no longer matches what is on disk, and any class it had not yet autoloaded
 * would resolve against the *new* tree — the worst kind of half-and-half. A
 * shell script is read into memory by bash and every `artisan` call inside it
 * is a fresh process against whichever code is current at that moment.
 *
 * The script is written OUTSIDE the repository. A copy that lived in the tree
 * would be swapped out from under bash by the checkout it just performed.
 *
 * Nothing from the request reaches this. The version is validated against a
 * strict pattern before it is ever interpolated, and every path comes from
 * server-side config — there is no arrangement of user input that changes
 * which commands run.
 */
class UpdateScript
{
    /** Ordered steps. The runner echoes each one into the state file. */
    public const STEPS = [
        'maintenance_on',
        'backup_database',
        'fetch_release',
        'checkout_release',
        'composer_install',
        'migrate',
        'seed_permissions',
        'resync_site_configs',
        'optimize',
        'frontend_build',
        'restart_services',
        'maintenance_off',
        'health_check',
    ];

    public function __construct(private InstalledPanelInfo $installed) {}

    /**
     * A release version is only ever a dotted number, optionally v-prefixed.
     * Anything else never reaches the script.
     */
    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^v?\d+(\.\d+){0,3}$/', $version) === 1;
    }

    public function render(PanelUpdate $update, string $version, bool $dryRun = false): string
    {
        $repo = $this->installed->repositoryPath();
        $backend = $repo.'/backend';
        $frontend = $repo.'/frontend';
        $php = '/usr/bin/php'.config('panel_update.php_version');
        $tag = 'v'.ltrim($version, 'vV');
        $state = $this->statePath($update);
        $rollbackTo = (string) $update->from_commit;

        // Health check goes through the panel's own URL, not 127.0.0.1: the
        // web server routes by hostname and a bare-IP request 404s, which
        // would fail every check and roll back successful updates.
        $healthUrl = rtrim((string) config('app.url'), '/').'/api/health';

        $run = $dryRun ? 'echo DRY-RUN: ' : '';

        return <<<BASH
        #!/usr/bin/env bash
        # Generated for panel update #{$update->getKey()}. Do not edit: this file
        # is a disposable copy, rewritten on every update.
        set -uo pipefail

        STATE="{$state}"
        STEP=""

        # Progress is a file, not a database row and not this process's memory.
        # restart_services kills whatever is watching, so the state has to
        # outlive the watcher; the panel reconciles from this file on boot.
        note() {
            STEP="\$1"
            printf '{"step":"%s","status":"running","at":"%s"}\\n' "\$1" "\$(date -u +%FT%TZ)" > "\$STATE"
        }

        finish() {
            printf '{"step":"%s","status":"%s","reason":"%s","commit":"%s","at":"%s"}\\n' \\
                "\$STEP" "\$1" "\${2:-}" "\$(git -C {$repo} rev-parse HEAD 2>/dev/null || echo unknown)" \\
                "\$(date -u +%FT%TZ)" > "\$STATE"
        }

        # Any failure lands here. The old commit goes back before anything else
        # so a half-updated tree is never left behind, and the panel comes out
        # of maintenance either way — a panel stuck "down" is worse than a
        # panel that failed to update.
        rollback() {
            local failed_step="\$STEP"
            note "rollback"
            {$run}git -C {$repo} checkout --force {$rollbackTo}
            {$run}{$php} {$backend}/artisan up
            finish failed "\$failed_step"
            exit 1
        }

        trap rollback ERR
        set -e

        note maintenance_on
        {$run}{$php} {$backend}/artisan down --retry=60

        note backup_database
        {$run}{$php} {$backend}/artisan panel:backup-database

        note fetch_release
        {$run}git -C {$repo} fetch --depth 1 origin refs/tags/{$tag}:refs/tags/{$tag}

        note checkout_release
        {$run}git -C {$repo} checkout --force {$tag}

        note composer_install
        {$run}composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d {$backend}

        note migrate
        {$run}{$php} {$backend}/artisan migrate --force

        # Idempotent, and required on every deploy: it re-syncs the permission
        # catalogue so permissions added by this release reach administrators.
        note seed_permissions
        {$run}{$php} {$backend}/artisan db:seed --class=PermissionSeeder --force

        # A vhost is a rendered file, so the AI bot list, the 8G ruleset and
        # the templates shipped in this release do not reach an existing site
        # until its config is written again. Without this the panel reports
        # the new list while sites still enforce the old one, and neither
        # side can tell. Never fails the update: a site that could not be
        # re-rendered was rolled back and is still serving.
        note resync_site_configs
        {$run}{$php} {$backend}/artisan sites:resync

        note optimize
        {$run}{$php} {$backend}/artisan optimize:clear
        {$run}{$php} {$backend}/artisan optimize

        # PATH is pinned because npm's shebang is `env node`: unpinned, the
        # build silently uses whatever node is first on PATH.
        note frontend_build
        {$run}env "PATH={$this->nodeBinDir()}:/usr/local/bin:/usr/bin:/bin" npm --prefix {$frontend} ci --no-audit --no-fund
        {$run}env "PATH={$this->nodeBinDir()}:/usr/local/bin:/usr/bin:/bin" npm --prefix {$frontend} run build

        note restart_services
        {$run}sudo systemctl reload {$this->service('php_fpm')}
        {$run}sudo systemctl restart {$this->service('frontend')}
        {$run}sudo systemctl restart {$this->service('queue')}

        note maintenance_off
        {$run}{$php} {$backend}/artisan up

        # Verify the NEW code is what answered. Without asserting the version,
        # a health check passes just as happily against the old release.
        note health_check
        {$this->healthCheck($healthUrl, $version, $dryRun)}

        finish succeeded
        BASH;
    }

    /**
     * Outside the repository on purpose — a state file inside the tree is
     * destroyed by the checkout whose progress it is recording.
     */
    public function statePath(PanelUpdate $update): string
    {
        return rtrim((string) config('panel_update.state_dir'), '/').'/update-'.$update->getKey().'.json';
    }

    public function scriptPath(PanelUpdate $update): string
    {
        return rtrim((string) config('panel_update.state_dir'), '/').'/update-'.$update->getKey().'.sh';
    }

    /**
     * The health check cannot be prefixed with `echo` like the other steps:
     * it is a pipeline, and echoing the left-hand side just feeds the printed
     * command text into grep, which never matches. A dry run would then always
     * fail here and roll back — reporting a failure that says nothing about
     * whether the real update would have worked.
     */
    private function healthCheck(string $url, string $version, bool $dryRun): string
    {
        if ($dryRun) {
            // Not `echo <the pipeline>`: the shell would split that at the
            // pipe and feed the printed text straight into grep, which is the
            // same failure by another route.
            return "echo 'DRY-RUN: health check skipped'";
        }

        return sprintf(
            'curl -fsS --max-time 15 "%s" | grep -q \'"version":"%s"\'',
            $url,
            $this->bareVersion($version),
        );
    }

    private function bareVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }

    private function service(string $key): string
    {
        return (string) config('panel_update.services.'.$key);
    }

    /**
     * Directory holding the node binary install.sh put on the box. Pinned into
     * PATH for the frontend build.
     */
    private function nodeBinDir(): string
    {
        return (string) config('panel_update.node_bin_dir');
    }
}
