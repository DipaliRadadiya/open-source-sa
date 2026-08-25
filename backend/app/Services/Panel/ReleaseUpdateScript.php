<?php

namespace App\Services\Panel;

use App\Models\PanelUpdate;

/**
 * The update, as a script, for an installation using release directories.
 *
 * Replaces {@see UpdateScript} — which checks out a new version **over the
 * running one** — for servers that have been migrated. Both exist on purpose:
 * every server already running is the old shape, and the runner picks by asking
 * {@see PanelLayout::isReleased()}. Rendering this for a legacy install would
 * build a layout beside a working one and point services at neither.
 *
 * **The ordering is the design.** Everything that can fail happens before
 * anything that changes what is live:
 *
 *   preflight · backup · create · link · composer · build   ← free to fail
 *   maintenance · migrate                                    ← irreversible
 *   swap · restart · verify                                  ← reversible by flip
 *   maintenance off · prune
 *
 * Steps one to six do not touch the running panel at all: they build a release
 * beside it. A failure there removes a directory and nothing else — the old
 * version is still serving, and no user ever knew. That is the property the
 * in-place update could not have, and the reason a failed update used to be
 * able to strand a server in maintenance mode with a half-checked-out tree.
 *
 * Migration is the hinge, and it is deliberately as late as it can be while
 * still preceding the swap: after it, rollback restores the code but not the
 * schema. The database backup taken at step two is what covers that, and the
 * failure message says so rather than implying the rollback was complete.
 */
class ReleaseUpdateScript
{
    /** Ordered steps. The runner echoes each one into the state file. */
    public const STEPS = [
        'preflight',
        'backup_database',
        'create_release',
        'link_shared',
        'composer_install',
        'frontend_build',
        'sync_privileges',
        'maintenance_on',
        'migrate',
        'swap',
        'restart_services',
        'verify',
        'maintenance_off',
        'prune',
    ];

    /**
     * The last step at which nothing live has changed yet.
     *
     * Everything through this point fails by deleting a directory. Named rather
     * than left implicit because a step added on the wrong side of it silently
     * gives up the property the whole design exists for — and the test asserts
     * against this constant, so moving a step moves the test with it.
     */
    public const LAST_SAFE_STEP = 'frontend_build';

    public function __construct(
        private PanelLayout $layout,
        private PanelReleases $releases,
    ) {}

    public function render(PanelUpdate $update, string $version, bool $dryRun = false): string
    {
        $tag = 'v'.ltrim($version, 'vV');
        $stamp = now()->format('Ymd-His');

        $root = $this->layout->root();
        $repository = $this->layout->sharedPath().'/repo';
        $release = $this->layout->newReleasePath($stamp);
        $state = rtrim((string) config('panel_update.state_dir'), '/').'/update-'.$update->getKey().'.json';

        $php = '/usr/bin/php'.config('panel_update.php_version');
        $user = (string) config('panel_update.app_user');
        $node = (string) config('panel_update.node_bin_dir');
        $currentCommit = escapeshellarg((string) $update->from_commit);
        $git = sprintf(
            'git -c %s -C %s',
            escapeshellarg('safe.directory='.$repository),
            escapeshellarg($repository),
        );

        // The *currently live* release, captured before anything moves. This is
        // what rollback returns to, and it has to be read now: after the swap
        // the symlink no longer knows what it used to point at.
        $previous = '$(readlink -f '.escapeshellarg($this->layout->currentLink()).')';

        // Backend commands run against the NEW release from `migrate` onward —
        // migrations belong to the version being installed, not the one being
        // replaced. Before that, artisan is still the old release's.
        $newBackend = $release.'/backend';
        $liveBackend = $this->layout->currentLink().'/backend';

        $run = $dryRun ? 'echo DRY-RUN: ' : '';
        $asUser = $dryRun ? 'echo DRY-RUN: ' : "sudo -u {$user} -H ";

        $health = rtrim((string) config('app.url'), '/');

        return <<<BASH
        #!/usr/bin/env bash
        # Generated for panel update #{$update->getKey()}. Do not edit: this file
        # is a disposable copy, rewritten on every update.
        set -uo pipefail

        STATE="{$state}"
        STEP=""
        SWAPPED=0
        MIGRATED=0
        PREVIOUS=""

        note() {
            STEP="\$1"
            printf '{"step":"%s","status":"running","at":"%s"}\\n' "\$1" "\$(date -u +%FT%TZ)" > "\$STATE"
        }

        finish() {
            printf '{"step":"%s","status":"%s","reason":"%s","rolled_back":%s,"release":"%s","at":"%s"}\\n' \\
                "\$STEP" "\$1" "\${2:-}" "\${3:-false}" "{$release}" "\$(date -u +%FT%TZ)" > "\$STATE"
        }

        # Undo as much as was done, in the reverse order it was done.
        #
        # The symlink goes back first: while it points at a half-working release
        # every request is served by it, so that is the most urgent thing to
        # correct. Only then the services, then maintenance mode, and the new
        # release directory last — it is inert once nothing points at it.
        #
        # The migration is NOT undone, because it cannot be. If one ran, the
        # reason carries that so the panel can say the code is back and the
        # schema is not, and where the backup is.
        rollback() {
            local failed_step="\$STEP"
            note "rollback"

            if [ "\$SWAPPED" = "1" ] && [ -n "\$PREVIOUS" ]; then
                {$run}ln -sfn "\$PREVIOUS" {$root}/.current.pending
                {$run}mv -T {$root}/.current.pending {$this->layout->currentLink()}
                {$run}sudo systemctl reload {$this->service('php_fpm')}
                {$run}sudo systemctl restart {$this->service('frontend')}
                {$run}sudo systemctl restart {$this->service('queue')}
            fi

            {$asUser}{$php} {$liveBackend}/artisan up
            {$run}rm -rf {$release}

            if [ "\$MIGRATED" = "1" ]; then
                finish failed "\$failed_step:migrated" true
            else
                finish failed "\$failed_step" true
            fi
            exit 1
        }

        trap rollback ERR
        set -e

        # ---- Nothing below here touches the running panel, until maintenance_on.

        note preflight
        # Real in both modes. A dry run exists to answer "would this work", and
        # one that echoes every command cannot answer it.
        git -c safe.directory={$repository} -C {$repository} rev-parse HEAD > /dev/null
        test -f {$this->layout->sharedPath()}/.env
        # An APP_KEY that is absent means a release would generate its own, and
        # every encrypted column — storage secrets, git tokens, database
        # passwords — becomes unreadable. Refuse rather than find out later.
        grep -q '^APP_KEY=.\\+' {$this->layout->sharedPath()}/.env
        PREVIOUS={$previous}

        note backup_database
        {$asUser}{$php} {$liveBackend}/artisan panel:backup-database

        note create_release
        {$run}{$git} fetch --depth 1 origin refs/tags/{$tag}:refs/tags/{$tag}
        if {$git} merge-base --is-ancestor {$tag} {$currentCommit}; then
            echo "Refusing update: {$tag} is already contained in current commit {$currentCommit}."
            finish failed target_not_newer
            exit 1
        fi
        {$this->releases->create($repository, $tag, $release)}

        note link_shared
        {$this->renderLinks($release, $dryRun)}

        note composer_install
        {$asUser}composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d {$newBackend}

        # Built as the panel's own user, exactly as install.sh does. A build run
        # as root leaves node_modules and .next owned by root under a service
        # that is not, and Next.js cannot write its cache at runtime.
        note frontend_build
        BUILD_RAM_MB=\$(awk '/MemTotal/ {printf "%d", \$2/1024}' /proc/meminfo)
        if [ "\$BUILD_RAM_MB" -ge 7500 ]; then BUILD_HEAP_MB=4096
        elif [ "\$BUILD_RAM_MB" -ge 3500 ]; then BUILD_HEAP_MB=3072
        else BUILD_HEAP_MB=2048; fi
        {$asUser}env "PATH={$node}:/usr/local/bin:/usr/bin:/bin" npm --prefix {$release}/frontend ci --no-audit --no-fund
        {$asUser}env "PATH={$node}:/usr/local/bin:/usr/bin:/bin" "NODE_OPTIONS=--max-old-space-size=\${BUILD_HEAP_MB}" npm --prefix {$release}/frontend run build

        # The first step that changes anything outside the release directory,
        # and so the first one after LAST_SAFE_STEP. It is deliberately not
        # allowed to fail the update: an otherwise-good update refused over a
        # privilege grant makes the update itself the outage, and the grant
        # that is already there still works for the code that is already
        # running. A failure leaves the existing file untouched -- see
        # PanelSudoers -- and panel:doctor's PrivilegeCheck reports the drift
        # by name afterwards, which is the surface an operator already reads.
        note sync_privileges
        {$run}{$php} {$newBackend}/artisan panel:sudoers || echo "WARNING: sudoers not synced; run 'artisan panel:sudoers' as root"

        # ---- Past here, failures are visible to users.

        note maintenance_on
        {$asUser}{$php} {$liveBackend}/artisan down --retry=60

        # The hinge. Run against the NEW release, because the migrations belong
        # to the version being installed. Nothing after this is undone by the
        # rollback except the code.
        note migrate
        {$asUser}{$php} {$newBackend}/artisan migrate --force
        MIGRATED=1
        {$asUser}{$php} {$newBackend}/artisan db:seed --class=PermissionSeeder --force

        note swap
        {$this->releases->activate($release)}
        SWAPPED=1

        note restart_services
        {$asUser}{$php} {$liveBackend}/artisan optimize:clear
        {$asUser}{$php} {$liveBackend}/artisan optimize
        {$run}sudo systemctl reload {$this->service('php_fpm')}
        {$run}sudo systemctl restart {$this->service('frontend')}
        {$run}sudo systemctl restart {$this->service('queue')}

        note verify
        {$this->verify($health, $version, $dryRun)}

        note maintenance_off
        {$asUser}{$php} {$liveBackend}/artisan up

        # Last, and its failure is not the update's failure: disk left
        # uncollected is untidy; an update reported as failed over it is a lie.
        note prune
        {$this->releases->prune()} || true

        finish succeeded
        BASH;
    }

    /**
     * The release path is passed in, never recomputed.
     *
     * An earlier version called `now()` again here to rebuild it. One second's
     * drift between the two calls and the links would point into a directory
     * that does not exist — the release would be built with no `.env`, and the
     * first thing to run in it would generate an APP_KEY of its own.
     */
    private function renderLinks(string $release, bool $dryRun): string
    {
        $prefix = $dryRun ? 'echo DRY-RUN: ' : '';

        return implode("\n        ", array_map(
            fn (string $command): string => $prefix.$command,
            $this->releases->linkShared($release),
        ));
    }

    /**
     * The gates that decide whether the new version stays.
     *
     * Both are retried rather than asked once: `systemctl restart` returns when
     * a unit has been *started*, not when it is *ready*, and Next.js takes
     * seconds to answer. Asking once turns a healthy update into a rollback.
     *
     * The frontend is checked at all because it never was — the old health
     * check curled the backend only, which is why a client could be told the
     * update succeeded and then reload into a service that was still booting.
     */
    private function verify(string $baseUrl, string $version, bool $dryRun): string
    {
        if ($dryRun) {
            return "echo 'DRY-RUN: verification skipped'";
        }

        $healthUrl = escapeshellarg($baseUrl.'/api/health');
        $frontendUrl = escapeshellarg($baseUrl.'/');
        $expected = escapeshellarg(ltrim($version, 'vV'));
        $queue = escapeshellarg($this->service('queue'));

        return <<<BASH
        HEALTH_URL={$healthUrl}
        EXPECTED_VERSION={$expected}
        HEALTH_OK=0
        for attempt in \$(seq 1 30); do
            response="\$(curl -sS --max-time 5 "\$HEALTH_URL" 2>&1)" && curl_status=0 || curl_status=\$?
            if [ "\$curl_status" = "0" ] && printf '%s' "\$response" | grep -q "\\\"version\\\":\\\"\$EXPECTED_VERSION\\\""; then
                echo "Backend health check passed on attempt \$attempt/30."
                HEALTH_OK=1
                break
            fi
            echo "Backend health attempt \$attempt/30 failed (curl exit \$curl_status); expected version \$EXPECTED_VERSION."
            printf 'Health response: %.1000s\\n' "\$response"
            sleep 1
        done
        test "\$HEALTH_OK" = "1"

        FRONTEND_URL={$frontendUrl}
        FRONTEND_OK=0
        for attempt in \$(seq 1 30); do
            if status="\$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 "\$FRONTEND_URL")" && [ "\$status" -ge 200 ] && [ "\$status" -lt 400 ]; then
                echo "Frontend health check passed on attempt \$attempt/30 with HTTP \$status."
                FRONTEND_OK=1
                break
            fi
            echo "Frontend health attempt \$attempt/30 failed with HTTP \${status:-000}."
            sleep 1
        done
        test "\$FRONTEND_OK" = "1"

        sudo systemctl is-active --quiet {$queue}
        BASH;
    }

    private function service(string $key): string
    {
        return (string) config('panel_update.services.'.$key);
    }
}
