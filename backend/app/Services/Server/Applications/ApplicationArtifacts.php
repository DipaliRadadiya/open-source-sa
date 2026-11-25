<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Application\RemoveCertificate;
use App\Actions\Server\Backup\DeleteBackup;
use App\Actions\Server\Cronjob\DeleteCronjob;
use App\Enums\CertificateType;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\Application\UnprovenWorkerProgramException;
use App\Models\Application;
use App\Models\Backup;
use App\Models\Cronjob;
use App\Models\Worker;
use App\Services\Addons\LogMonitoringSuite;
use App\Services\Addons\SiteRedisAccount;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything the panel wrote for an application that is not its vhost or its files: the PHP-FPM
 * pool, worker units, its own background cron job, the fail2ban jail, and the certificate renewal.
 *
 * These live outside the application's directory, so deleting the site leaves them pointing at
 * something gone:
 *
 *  - a pool naming a deleted Linux user stops php-fpm initialising for the whole server;
 *  - a worker unit stays enabled and restarts on boot, holding the deleted files open;
 *  - a fail2ban jail points at a missing log path and complains on every reload;
 *  - a certbot renewal keeps running forever (see {@see RemoveCertificate}).
 *
 * Backups are removed only on `$removeData`: they are the copy that makes a mistaken deletion
 * survivable, so a plain delete leaves the archives recoverable.
 *
 * Every removal is independent: a failure in one must not skip the rest. Failures are logged with
 * the artefact they belong to.
 */
class ApplicationArtifacts
{
    /**
     * `WorkerSupervisor` is deliberately resolved at call time: it depends on
     * {@see ApplicationProvisioner}, which depends on this class, and injecting it here makes the
     * container recurse until the process dies.
     */
    public function __construct(
        private PoolManager $pools,
        private ApplicationFail2banManager $fail2ban,
        private CertbotClient $certbot,
        private CertificateFiles $certificateFiles,
        private ServerOps $serverOps,
    ) {}

    /**
     * @param  bool  $removeData  the caller asked for the site's data to go too
     */
    public function remove(Application $application, bool $removeData = false): void
    {
        // Backups follow `remove_files`: `backups.application_id` cascades, so a plain delete would
        // orphan the archives in the bucket, but they are also the way to undo a mistaken delete.
        // First, while the rows still exist (the controller deprovisions before deleting the record).
        if ($removeData) {
            $this->removeBackups($application);
        }

        $this->attempt($application, 'php_pool', function () use ($application) {
            if ($application->serving_profile === 'php' && $this->pools->supported()) {
                $this->pools->remove($application);
            }
        });

        $this->attempt($application, 'workers', function () use ($application) {
            // `workers.application_id` cascades, so remove each unit before its row goes: the row
            // is the only record of the unit.
            $workers = app(WorkerSupervisor::class);
            $adopted = app(AdoptedSiteConfig::class);

            // One attempt per worker, so one that will not stop does not leave the rest running.
            foreach (Worker::where('application_id', $application->id)->get() as $worker) {
                $this->attempt($application, 'workers', function () use ($application, $worker, $workers, $adopted) {
                    // A program adopted from a shared file or systemd unit is never the panel's
                    // to stop, so it is named instead.
                    $adopted->reportWorkerSource($application, $worker);

                    try {
                        $workers->remove($worker);
                    } catch (UnprovenWorkerProgramException $e) {
                        $adopted->reportWorker($application, $e->path, $e->reason);
                    }
                });
            }
        });

        // The vhost Server Sync adopted the site from, removed only once proven to be this site's
        // alone ({@see AdoptedSiteConfig}). Before the driver's removal and any `rm -rf`.
        $this->attempt($application, 'adopted_vhost', function () use ($application) {
            app(AdoptedSiteConfig::class)->remove($application);
        });

        $this->attempt($application, 'cronjobs', function () use ($application) {
            // Only panel-created jobs (an installer's background job); a user-added job is kept
            // (`cronjobs.application_id` is nullOnDelete). Uses the Cronjobs screen's own delete so
            // file, log and row go in order. Resolved lazily like WorkerSupervisor.
            $delete = app(DeleteCronjob::class);

            foreach (Cronjob::where('application_id', $application->id)->where('application_owned', true)->get() as $cronjob) {
                $delete->execute($cronjob);
            }
        });

        $this->attempt($application, 'site_rules', function () use ($application) {
            $this->removeSiteRules($application);
        });

        $this->attempt($application, 'redis_account', function () use ($application) {
            // Object Cache Pro's Redis user; a site without one has nothing to remove.
            app(SiteRedisAccount::class)->remove($application);
        });

        $this->attempt($application, 'log_monitoring_suite', function () use ($application) {
            // Registered only through Central; a site it never heard of has nothing to remove.
            $logMonitoring = app(LogMonitoringSuite::class);

            if ($application->log_monitoring_suite_id !== null && $logMonitoring->installed()) {
                $logMonitoring->unregister($application);
            }
        });

        $this->attempt($application, 'log_rotation', function () use ($application) {
            // Left behind, logrotate warns about the missing files on every nightly run.
            app(ApplicationLogRotation::class)->remove($application);
        });

        $this->attempt($application, 'fail2ban', function () use ($application) {
            $this->fail2ban->disableForApp($application);
        });

        $this->attempt($application, 'certificate', function () use ($application) {
            $certificate = $application->certificate;

            if ($certificate === null) {
                return;
            }

            $result = match ($certificate->type) {
                CertificateType::LetsEncrypt => ($lineage = $certificate->lineageName()) === null
                    ? null
                    : $this->certbot->revoke($lineage, $application->id),
                CertificateType::Custom, CertificateType::SelfSigned => $this->certificateFiles->remove([
                    $certificate->certificate_path,
                    $certificate->private_key_path,
                    $certificate->chain_path,
                ], $application->slug, $application->id),
            };

            if ($result?->failed()) {
                throw new ProvisioningFailedException('remove_certificate', $result->reference);
            }
        });
    }

    /**
     * The site's rules directory, which its vhost includes (the WordPress toolkit's blocks).
     *
     * `find -delete`, not `rm -rf`: it never follows a link and needs no extra sudo grant. It is
     * still a recursive delete on a path built from a record, so a blank slug (which would make it
     * the rules root, i.e. every site's rules) is refused outright.
     */
    private function removeSiteRules(Application $application): void
    {
        $root = rtrim((string) config('server.site_rules_root'), '/');
        $name = basename($application->siteRulesPath());

        // Exactly one level under the root; with no slug the basename is the root's own name.
        abort_unless($root !== '' && $application->siteRulesPath() === $root.'/'.$name && ! in_array($name, ['.', '..'], true), 500);

        $this->serverOps->run(
            ['find', $application->siteRulesPath(), '-delete'],
            ['feature' => 'application', 'op' => 'remove_site_rules', 'application' => $application->id],
        );
    }

    /**
     * Delete every archive this site has, one at a time.
     *
     * Each backup is tried separately: one that will not delete must not abort the loop, or the
     * rows never reached cascade away and orphan their objects in the bucket.
     *
     * A failure never stops the deletion (an unreachable bucket must not block the certificate
     * revoke). Surviving keys are logged by name, since the rows are about to cascade and this is
     * the last moment anything knows them.
     */
    private function removeBackups(Application $application): void
    {
        $deleteBackup = app(DeleteBackup::class);
        $orphaned = [];

        foreach (Backup::where('application_id', $application->id)->get() as $backup) {
            try {
                $deleteBackup->execute($backup);
            } catch (Throwable $e) {
                $orphaned[] = [
                    'backup' => $backup->id,
                    'key' => $backup->manifest['key'] ?? null,
                    'detail' => $e->getMessage(),
                ];
            }
        }

        if ($orphaned !== []) {
            Log::channel('server-ops')->warning('backup archives left on the destination', [
                'feature' => 'application',
                'op' => 'deprovision_artifact',
                'artifact' => 'backups',
                'application' => $application->id,
                'orphaned' => $orphaned,
            ]);
        }
    }

    /**
     * Run one removal, and never let it stop the others.
     */
    private function attempt(Application $application, string $artifact, callable $removal): void
    {
        try {
            $removal();
        } catch (Throwable $e) {
            Log::channel('server-ops')->warning('an application artefact could not be removed', [
                'feature' => 'application',
                'op' => 'deprovision_artifact',
                'artifact' => $artifact,
                'application' => $application->id,
                'detail' => $e->getMessage(),
            ]);
        }
    }
}
