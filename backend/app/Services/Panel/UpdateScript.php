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
        'preflight_git',
        'fetch_release',
        'maintenance_on',
        'backup_database',
        'checkout_release',
        'composer_install',
        'migrate',
        'seed_permissions',
        'configure_services',
        'resync_site_configs',
        'optimize',
        'frontend_build',
        'sync_privileges',
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

        // Every git call carries the safe.directory exception itself rather
        // than relying on one being configured somewhere.
        //
        // The repository is owned by the panel's own account and this script
        // runs as root, which git refuses since CVE-2022-24765 — "detected
        // dubious ownership", exit 128. install.sh knows this and writes the
        // exception into root's ~/.gitconfig, but the update is launched
        // through `systemd-run`, which starts the unit with its own
        // environment: HOME is not root's shell home, git never reads that
        // file, and the exception may as well not exist.
        //
        // It went unnoticed because a dry run echoes every step, so the only
        // git command that actually ran was the `rev-parse` inside finish() —
        // whose failure was swallowed by `|| echo unknown` and recorded as
        // `"commit":"unknown"` beside a cheerful `"status":"succeeded"`. A
        // real update would have failed at fetch_release and then failed to
        // roll back, because rollback is a git checkout too.
        // ...and it runs as the account that owns the repository, not as root.
        //
        // Running it as root left 1331 files under /var/www/panel owned by
        // root on a real server — `git checkout --force` writes every file it
        // touches as the calling user, and install.sh had chowned the tree to
        // `panel`. The damage compounds: the panel account can then no longer
        // check out or clean its own repository, so `clean_working_tree` fails
        // for good and *every subsequent update is refused by preflight*. One
        // update attempt was enough to wedge the box permanently.
        //
        // As the owning account there is no ownership mismatch left for
        // safe.directory to forgive, but it is kept: the exception costs
        // nothing and the fetch still has to work if an operator has been
        // root in this tree by hand.
        $git = sprintf(
            'sudo -u %s -H git -c %s -C %s',
            escapeshellarg((string) config('panel_update.app_user')),
            escapeshellarg('safe.directory='.$repo),
            escapeshellarg($repo),
        );

        // Health check goes through the panel's own URL, not 127.0.0.1: the
        // web server routes by hostname and a bare-IP request 404s, which
        // would fail every check and roll back successful updates.
        $healthUrl = rtrim((string) config('app.url'), '/').'/api/health';

        $run = $dryRun ? 'echo DRY-RUN: ' : '';

        // Build steps run as the panel's own account, never as root.
        //
        // Two separate failures, one cause: `systemd-run` starts the update in
        // a transient unit with a minimal environment, and HOME is not in it.
        // Composer refuses outright -- "The HOME or COMPOSER_HOME environment
        // variable must be set for composer to run correctly" -- so every
        // update failed at composer_install and rolled back. Verified on a
        // real box: `systemd-run --pipe sh -c 'echo $HOME'` prints nothing.
        //
        // The same emptiness sends npm's cache to /root/.npm and leaves
        // vendor/, node_modules/ and .next/ owned by root under services that
        // run as `panel` -- which Next.js cannot write its cache into. That
        // one would only have surfaced *after* fixing composer, on the first
        // update that got far enough to build.
        //
        // `sudo -u <user> -H` fixes both: -H sets HOME to that account's home.
        // Exactly what ReleaseUpdateScript and install.sh already do; this is
        // the same lesson as the `safe.directory` fix in 92b20be, which is
        // also about systemd-run not carrying root's environment.
        $user = (string) config('panel_update.app_user');
        $asUser = $dryRun ? 'echo DRY-RUN: ' : "sudo -u {$user} -H ";

        // artisan too, and for a reason the first fix did not cover. After a
        // successful update on a real box, 50 files under backend/storage were
        // still owned by root: the compiled Blade views written by `optimize`,
        // and the database dump from `panel:backup-database`. php-fpm runs as
        // `panel` and cannot overwrite a root-owned view cache, and a 0640
        // root-owned backup is one the panel cannot read back.
        //
        // The migration step is the sharper one: SQLite writes `-wal` and
        // `-shm` beside the database, and root-owned ones make the panel's own
        // database unwritable -- "attempt to write a readonly database" on a
        // panel that just reported a successful update.
        //
        // `panel:sudoers` is the deliberate exception: it writes
        // /etc/sudoers.d and is the one command here that genuinely needs
        // root.

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
            printf '{"step":"%s","status":"%s","reason":"%s","rolled_back":%s,"commit":"%s","at":"%s"}\\n' \\
                "\$STEP" "\$1" "\${2:-}" "\${3:-false}" \\
                "\$({$git} rev-parse HEAD 2>/dev/null || echo unknown)" "\$(date -u +%FT%TZ)" > "\$STATE"
        }

        # Any failure lands here. The old commit goes back before anything else
        # so a half-updated tree is never left behind, and the panel comes out
        # of maintenance either way — a panel stuck "down" is worse than a
        # panel that failed to update.
        rollback() {
            local failed_step="\$STEP"
            note "rollback"
            {$run}{$git} checkout --force {$rollbackTo}
            {$asUser}{$php} {$backend}/artisan up
            finish failed "\$failed_step" true
            exit 1
        }

        trap rollback ERR
        set -e

        # Real in both modes, and first. A dry run exists to answer "would the
        # update work", and one that echoes every command cannot answer it —
        # this one reported success on a box where the very first real command
        # fails. Reading HEAD changes nothing and proves the thing every later
        # step depends on: that git can operate on this repository as this
        # user. Cheap enough to be worth doing before maintenance mode goes on.
        note preflight_git
        {$git} rev-parse HEAD > /dev/null

        # Fetch and compare before maintenance. A panel deployed from main can
        # already contain the latest release plus newer commits; VERSION used
        # to report 1.0.1 there, so the updater offered v1.0.7 and checked out
        # older code. The API prevents that now, and this second guard closes
        # the race between checking and executing.
        note fetch_release
        {$run}{$git} fetch --depth 1 origin refs/tags/{$tag}:refs/tags/{$tag}
        if {$git} merge-base --is-ancestor {$tag} {$rollbackTo}; then
            echo "Refusing update: {$tag} is already contained in current commit {$rollbackTo}."
            finish failed target_not_newer
            exit 1
        fi

        note maintenance_on
        {$asUser}{$php} {$backend}/artisan down --retry=60

        note backup_database
        {$asUser}{$php} {$backend}/artisan panel:backup-database

        note checkout_release
        {$run}{$git} checkout --force {$tag}

        note composer_install
        {$asUser}composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d {$backend}

        note migrate
        {$asUser}{$php} {$backend}/artisan migrate --force

        # Idempotent, and required on every deploy: it re-syncs the permission
        # catalogue so permissions added by this release reach administrators.
        note seed_permissions
        {$asUser}{$php} {$backend}/artisan db:seed --class=PermissionSeeder --force

        # Existing installs were written with the queue and sessions on the
        # database, which for a SQLite panel means the worker polls the same
        # single-writer file every request writes to — "database is locked" on
        # an idle box. The installer can only fix that for new installs, and
        # an update never rewrites .env, so it is fixed here. Proves Redis
        # answers first and changes nothing if it does not; must run before
        # `optimize`, which caches the config this rewrites.
        note configure_services
        {$asUser}{$php} {$backend}/artisan panel:configure-services

        # A vhost is a rendered file, so the AI bot list, the 8G ruleset and
        # the templates shipped in this release do not reach an existing site
        # until its config is written again. Without this the panel reports
        # the new list while sites still enforce the old one, and neither
        # side can tell. Never fails the update: a site that could not be
        # re-rendered was rolled back and is still serving.
        note resync_site_configs
        {$asUser}{$php} {$backend}/artisan sites:resync

        note optimize
        {$asUser}{$php} {$backend}/artisan optimize:clear
        {$asUser}{$php} {$backend}/artisan optimize

        # PATH is pinned because npm's shebang is `env node`: unpinned, the
        # build silently uses whatever node is first on PATH.
        #
        # NODE_OPTIONS caps V8's old space, which it will not grow past no
        # matter how much memory is free -- a large `next build` otherwise
        # dies with "Reached heap limit ... JavaScript heap out of memory"
        # while the box still has RAM spare. Sized from RAM the same way
        # install.sh's build_frontend() does; the two must not disagree, or an
        # update fails on a box its own installer built fine.
        note frontend_build
        BUILD_RAM_MB=\$(awk '/MemTotal/ {printf "%d", \$2/1024}' /proc/meminfo)
        if [ "\$BUILD_RAM_MB" -ge 7500 ]; then BUILD_HEAP_MB=4096
        elif [ "\$BUILD_RAM_MB" -ge 3500 ]; then BUILD_HEAP_MB=3072
        else BUILD_HEAP_MB=2048; fi
        {$asUser}env "PATH={$this->nodeBinDir()}:/usr/local/bin:/usr/bin:/bin" npm --prefix {$frontend} ci --no-audit --no-fund
        {$asUser}env "PATH={$this->nodeBinDir()}:/usr/local/bin:/usr/bin:/bin" "NODE_OPTIONS=--max-old-space-size=\${BUILD_HEAP_MB}" npm --prefix {$frontend} run build

        # Before the restart, so the new code comes up with the grant it needs
        # rather than one install-time snapshot older.
        #
        # This flow gets it too, and not only the release flow the design doc
        # describes, because every server in the field is still this shape --
        # a fix that waits for the migration is a fix nobody has. It is the
        # single largest source of post-update breakage: touch, certbot and
        # mysqldump each broke a shipped feature in one week on servers whose
        # sudoers had not been rewritten since the day they were installed.
        #
        # Never fatal: the grant already on disk still serves the code already
        # running, and refusing an otherwise-good update over it would make the
        # update the outage. PanelSudoers leaves the existing file untouched on
        # failure, and panel:doctor names the drift afterwards.
        note sync_privileges
        {$run}{$php} {$backend}/artisan panel:sudoers || echo "WARNING: sudoers not synced; run 'artisan panel:sudoers' as root"

        note restart_services
        {$run}sudo systemctl reload {$this->service('php_fpm')}
        {$run}sudo systemctl restart {$this->service('frontend')}
        {$run}sudo systemctl restart {$this->service('queue')}

        note maintenance_off
        {$asUser}{$php} {$backend}/artisan up

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

        $healthUrl = escapeshellarg($url);
        $expected = escapeshellarg($this->bareVersion($version));

        return <<<BASH
        HEALTH_URL={$healthUrl}
        EXPECTED_VERSION={$expected}
        HEALTH_OK=0
        for attempt in \$(seq 1 30); do
            response="\$(curl -sS --max-time 5 "\$HEALTH_URL" 2>&1)" && curl_status=0 || curl_status=\$?
            if [ "\$curl_status" = "0" ] && printf '%s' "\$response" | grep -q "\\\"version\\\":\\\"\$EXPECTED_VERSION\\\""; then
                echo "Health check passed on attempt \$attempt/30."
                HEALTH_OK=1
                break
            fi
            echo "Health check attempt \$attempt/30 failed (curl exit \$curl_status); expected version \$EXPECTED_VERSION."
            printf 'Health response: %.1000s\\n' "\$response"
            sleep 1
        done
        test "\$HEALTH_OK" = "1"
        BASH;
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
