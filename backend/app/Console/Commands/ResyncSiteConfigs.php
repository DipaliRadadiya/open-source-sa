<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\SystemUser;
use App\Models\Worker;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\SecretFilePrivacy;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\Applications\WorkerSupervisor;
use App\Services\Server\Php\AdditionalDirectives;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;
use App\Services\Server\WebServers\CatchAllSite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-render every live site's vhost from the current templates and lists.
 *
 * Run by the update script after migrations, because the AI bot list, the 8G
 * ruleset and the vhost templates all ship inside the panel: an update
 * changes what a site's config *would* say without changing what it does
 * say. See `SiteConfigResyncer` for why that gap is a correctness problem
 * rather than a missing convenience.
 *
 * Exits 0 even when individual sites fail. A site whose config test failed
 * has already been rolled back and is still serving; failing the command
 * would abort an otherwise-good panel update over it.
 */
class ResyncSiteConfigs extends Command
{
    protected $signature = 'sites:resync';

    protected $description = 'Re-render every live site config from the current templates and bot lists';

    public function handle(SiteConfigResyncer $resyncer, SiteRootLock $rootLock, HomeDirectoryAccess $homeAccess): int
    {
        $result = $resyncer->run();

        // The grant is reported separately because it is the one thing here
        // that can be the *only* reason the web server was restarted. Folded
        // into "updated" it would read as a config change that did not happen;
        // left out entirely, a run saying "0 updated. Web server reloaded."
        // would look like a bug.
        $this->info(sprintf(
            '%d site(s): %d updated, %d already current, %d failed.%s%s',
            $result['total'],
            $result['updated'],
            $result['unchanged'],
            count($result['failed']),
            $result['granted'] > 0
                ? sprintf(' Log access granted for %d site(s).', $result['granted'])
                : '',
            $result['reloaded'] ? ' Web server reloaded.' : '',
        ));

        foreach ($result['failed'] as $failure) {
            $this->warn(sprintf(
                'Left unchanged: %s (#%d)%s',
                $failure['name'],
                $failure['id'],
                $failure['reference'] ? ' — reference '.$failure['reference'] : '',
            ));
        }

        $this->ensureCatchAll(app(CatchAllSite::class));
        $this->lockSiteRoots($rootLock);
        $this->closeHomes($homeAccess);
        // The panel's own checkout, closed for the same reason as the homes
        // and by the same means — see PanelDirectoryAccess.
        $this->call('panel:close-directory');
        $this->refreshUnits(app(ProcessSupervisor::class), app(ApplicationProvisioner::class));
        $this->refreshDisabledWorkers(app(WorkerSupervisor::class));
        $this->narrowSecretFiles(app(SecretFilePrivacy::class));
        $this->reportSkippedDirectives();

        return self::SUCCESS;
    }

    /**
     * Switched-off workers were written with autostart=true and came back on
     * every reboot (bug #73). Rewritten once here; running ones untouched.
     */
    private function refreshDisabledWorkers(WorkerSupervisor $workers): void
    {
        // Never fails the resync: a server without supervisor, or a grant
        // that refuses it, simply keeps what it has.
        try {
            $refreshed = Worker::query()->where('enabled', false)->with('application.systemUser')->get()
                ->filter(fn (Worker $worker) => $worker->application !== null && $workers->refreshDisabled($worker))
                ->count();
        } catch (Throwable) {
            return;
        }

        if ($refreshed > 0) {
            $this->info("Switched-off workers: {$refreshed} kept off at boot.");
        }
    }

    /**
     * A neutral answer for domains no site claims (bug #55) — see
     * CatchAllSite. Here so every install, update and manual deploy gets it.
     * Never fails the command: a server whose web server refuses it keeps
     * the default it already had.
     */
    private function ensureCatchAll(CatchAllSite $catchAll): void
    {
        $result = $catchAll->ensure();

        match ($result['status']) {
            'updated' => $this->info('Unknown domains: now refused (web server reloaded).'),
            'current' => $this->info('Unknown domains: already refused.'),
            'skipped' => $this->info('Unknown domains: left to the server\'s own default.'),
            'failed' => $this->warn('Unknown domains: not changed — the web server refused the default site'
                .($result['reference'] ? ' (reference '.$result['reference'].')' : '').'.'),
        };
    }

    /**
     * Keep every site's `.env` and config secrets to the account that runs
     * it — see SecretFilePrivacy. Here because the installs that left them
     * readable happened before the fix did. Only ever narrows, so a file the
     * user tightened is left as it is.
     */
    private function narrowSecretFiles(SecretFilePrivacy $privacy): void
    {
        $count = 0;

        foreach (Application::query()->with('systemUser')->get() as $application) {
            if ($application->systemUser === null) {
                continue;
            }

            $privacy->narrow($application);
            $count++;
        }

        $this->info("Secret files (.env, wp-config.php and the like): narrowed where present ({$count} site(s) checked).");
    }

    /**
     * Additional directives saved before only PHP settings were accepted.
     * Those were written into the PHP-FPM pool verbatim, and a pool is only
     * rewritten when its settings are saved — so a pool whose text differs
     * from what is written today is rewritten here, through the same apply
     * (config test before reload) a save uses. OpenLiteSpeed's site php.ini
     * was already rewritten by the resync above. A line that is not a PHP
     * setting at all is named so the owner can review what was dropped.
     */
    private function reportSkippedDirectives(): void
    {
        $directives = app(AdditionalDirectives::class);
        $pools = app(PoolManager::class);
        $rewritten = 0;

        ApplicationPhpSettings::query()
            ->whereNotNull('additional_directives')
            ->with('application.systemUser')
            ->each(function (ApplicationPhpSettings $settings) use ($directives, $pools, &$rewritten) {
                $text = (string) $settings->additional_directives;
                $application = $settings->application;

                if ($application === null) {
                    return;
                }

                $line = $directives->firstInvalidLine($text);

                if ($pools->supported() && $this->poolPredatesDirectives($pools, $directives, $application, $text)) {
                    if (! $pools->apply($application, $settings)['ok']) {
                        $this->error("PHP pool for application #{$application->id} still holds additional directives written before only PHP settings were accepted, and rewriting it failed. Save its PHP settings to retry.");

                        return;
                    }

                    $rewritten++;
                }

                if ($line !== null) {
                    $this->warn("PHP additional directives for application #{$application->id}: \"{$line}\" is not a PHP setting and is no longer applied. Review and save them again.");
                }
            });

        if ($rewritten > 0) {
            $this->info("PHP pools rewritten with additional directives as PHP settings only: {$rewritten}.");
        }
    }

    /**
     * Whether this site's pool file still holds a directive as it was typed,
     * which only the pre-2026-09-30 writer produced. Asked of the file, not
     * the saved text — the text keeps a dropped line until the owner saves
     * again, so asking it would rewrite (and reload) the pool on every run.
     * A pool someone hand-edited is left alone, as a save would warn first.
     */
    private function poolPredatesDirectives(PoolManager $pools, AdditionalDirectives $directives, Application $application, string $text): bool
    {
        $path = $pools->poolPath($application);
        $legacy = $directives->legacyFpmLines($text);

        if ($path === null || $legacy === [] || $pools->exists($application) !== true) {
            return false;
        }

        $lines = array_map('trim', explode("\n", (string) $pools->readPool($path)));

        return array_intersect($legacy, $lines) !== [];
    }

    /**
     * Bring every application's systemd unit up to the current template —
     * see ProcessSupervisor::refreshUnit(). Never restarts anything.
     */
    private function refreshUnits(ProcessSupervisor $supervisor, ApplicationProvisioner $provisioner): void
    {
        $refreshed = 0;

        foreach (Application::query()->with('systemUser')->get() as $application) {
            if ($application->systemUser === null || ! $supervisor->runs($application)) {
                continue;
            }

            if ($supervisor->refreshUnit($application, $provisioner->documentRoot($application))) {
                $refreshed++;
            }
        }

        if ($refreshed > 0) {
            $this->info("Process units: {$refreshed} updated to the current template (nothing restarted).");
        }
    }

    /**
     * Close every system user's home to other local accounts — see
     * HomeDirectoryAccess. Here for the reason lockSiteRoots() is: a change
     * made only when a user is created protects new accounts and none of the
     * existing ones. Installs the `acl` package first when it is missing
     * (Ubuntu 26.04 does not ship it); never fails the command.
     */
    private function closeHomes(HomeDirectoryAccess $homeAccess): void
    {
        $users = SystemUser::query()->orderBy('id')->get();

        if ($users->isEmpty()) {
            return;
        }

        if (! $homeAccess->ensureTools()) {
            $this->warn('Home directories left open: the acl package could not be installed (setfacl is missing).');

            return;
        }

        $counts = array_fill_keys([
            HomeDirectoryAccess::SECURED, HomeDirectoryAccess::ALREADY, HomeDirectoryAccess::SKIPPED,
            HomeDirectoryAccess::NO_ACL, HomeDirectoryAccess::NO_READER, HomeDirectoryAccess::FAILED,
        ], 0);
        $open = [];

        foreach ($users as $user) {
            $result = $homeAccess->secure($user);
            $counts[$result]++;

            if (in_array($result, [HomeDirectoryAccess::NO_ACL, HomeDirectoryAccess::NO_READER, HomeDirectoryAccess::FAILED], true)) {
                $open[] = [$user, $result];
            }
        }

        $this->info(sprintf(
            'Home directories: %d closed to other users, %d already closed%s%s.',
            $counts[HomeDirectoryAccess::SECURED],
            $counts[HomeDirectoryAccess::ALREADY],
            $counts[HomeDirectoryAccess::SKIPPED] > 0
                ? sprintf(', %d left alone (outside %s, a link, or not the user\'s own)', $counts[HomeDirectoryAccess::SKIPPED], config('server.home_base', '/home'))
                : '',
            $open !== [] ? sprintf(', %d still open', count($open)) : '',
        ));

        foreach ($open as [$user, $result]) {
            $this->warn(sprintf('Still open: %s — %s', $user->username, match ($result) {
                HomeDirectoryAccess::NO_READER => 'the web server\'s account is not known',
                HomeDirectoryAccess::NO_ACL => 'setfacl is missing',
                default => 'the ACL did not take; see the server-ops log',
            }));
        }
    }

    /**
     * Make every existing site root immutable — see SiteRootLock.
     *
     * Here because this command is already run on every deploy and by the
     * panel's own updater: a lock applied only when a site is created would
     * protect new sites and leave every existing one open, the same shape as a
     * template fix that reaches new sites only.
     *
     * A site root that is not a root-owned directory is reported and left
     * alone. Locking it would pin whatever is there in place — and if the site
     * user has already swapped it, that is exactly what must not be trusted.
     */
    private function lockSiteRoots(SiteRootLock $rootLock): void
    {
        $counts = [SiteRootLock::LOCKED => 0, SiteRootLock::UNSUPPORTED => 0];
        $problems = [];

        foreach (Application::query()->with('systemUser')->get() as $application) {
            if ($application->systemUser === null) {
                continue;
            }

            $result = $rootLock->lock($application);

            if (array_key_exists($result, $counts)) {
                $counts[$result]++;
            } elseif ($result !== SiteRootLock::MISSING) {
                $problems[] = [$application, $result];
            }
        }

        $this->info(sprintf(
            'Site roots: %d locked%s%s.',
            $counts[SiteRootLock::LOCKED],
            $counts[SiteRootLock::UNSUPPORTED] > 0
                ? sprintf(', %d on a filesystem without the immutable flag', $counts[SiteRootLock::UNSUPPORTED])
                : '',
            $problems !== [] ? sprintf(', %d need attention', count($problems)) : '',
        ));

        foreach ($problems as [$application, $result]) {
            $this->warn(sprintf(
                'Not locked: %s (#%d) — %s',
                $application->name,
                $application->id,
                $result === SiteRootLock::UNSAFE
                    ? 'its directory is not a root-owned directory, so it was left alone; check '.$application->rootPath().' (a site server sync adopted: use Lock on the site\'s page)'
                    : 'chattr failed; see the server-ops log',
            ));
        }
    }
}
