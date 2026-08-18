<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Application\RemoveCertificate;
use App\Actions\Server\Backup\DeleteBackup;
use App\Enums\CertificateType;
use App\Models\Application;
use App\Models\Backup;
use App\Models\Worker;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Php\PoolManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything the panel wrote for an application that is *not* its vhost and not
 * its files: the PHP-FPM pool, worker units, the fail2ban jail, and the
 * certificate's renewal.
 *
 * Collected here because they share one failure mode and it is not obvious.
 * Each of these lives outside the application's own directory, so removing the
 * site leaves them behind — and each then points at something that no longer
 * exists. The endings differ in severity but not in kind:
 *
 *  - a pool naming a deleted Linux user stops php-fpm initialising **for the
 *    whole server**, so every later site creation fails and gets blamed for it;
 *  - a worker unit stays enabled and restarts on boot, holding a deleted site's
 *    files open;
 *  - a fail2ban jail points at a log path that is gone, and complains on every
 *    reload;
 *  - a certbot renewal keeps running forever, spending rate limit and
 *    eventually emailing the user about a site they removed.
 *
 * That last one is quoted almost verbatim from {@see RemoveCertificate},
 * which has always known about the problem — it was simply never called from
 * the path where sites actually go away.
 *
 * Backups are the exception, and are handled on `$removeData` rather than
 * unconditionally. They are not leftovers to tidy: they are the copy that
 * exists so a mistaken deletion is survivable, and deleting the site is the
 * mistake somebody would most want to undo. Left alone they are still an
 * orphan — the rows cascade away while multi-gigabyte archives stay in
 * somebody's bucket — so the choice is which cost to take, and the panel takes
 * the one that can be reversed.
 *
 * **Every removal is independent.** They run in sequence but a failure in one
 * must not skip the rest: a fail2ban reload that fails is a nuisance, and
 * letting it prevent the certificate revoke would trade a small problem for a
 * long-lived one. Failures are logged with the artefact they belong to, so a
 * server that ends up with a leftover can be found rather than guessed at.
 */
class ApplicationArtifacts
{
    /**
     * `WorkerSupervisor` is deliberately absent, and resolved at call time
     * below instead.
     *
     * It depends on {@see ApplicationProvisioner}, which depends on this class
     * — so injecting it here closes a cycle the container resolves by
     * recursing until the process dies, with no error to say why. Taking it
     * lazily breaks the loop: the second `ApplicationArtifacts` the container
     * builds on the way round needs nothing further.
     */
    public function __construct(
        private PoolManager $pools,
        private ApplicationFail2banManager $fail2ban,
        private CertbotClient $certbot,
    ) {}

    /**
     * @param  bool  $removeData  the caller asked for the site's data to go too
     */
    public function remove(Application $application, bool $removeData = false): void
    {
        // Backups are the one artefact whose removal is a decision rather than
        // tidying up. `backups.application_id` cascades, so deleting a site
        // drops every row while the archives stay in the bucket — unfindable,
        // undeletable through the panel, and billed every month. The same
        // orphan `DeleteBackup` exists to prevent, arriving by a different
        // door.
        //
        // But they exist precisely so that a mistaken deletion is survivable,
        // and deleting the site is the mistake somebody would most want to
        // undo. So they follow `remove_files`: a plain delete takes the site
        // off the panel and leaves the archives recoverable, and only a caller
        // who already said "destroy this site's data" destroys them.
        //
        // First, while the rows still exist — the controller deprovisions
        // before it deletes the record.
        if ($removeData) {
            $this->attempt($application, 'backups', function () use ($application) {
                $deleteBackup = app(DeleteBackup::class);

                foreach (Backup::where('application_id', $application->id)->get() as $backup) {
                    $deleteBackup->execute($backup);
                }
            });
        }

        $this->attempt($application, 'php_pool', function () use ($application) {
            if ($application->serving_profile === 'php' && $this->pools->supported()) {
                $this->pools->remove($application);
            }
        });

        $this->attempt($application, 'workers', function () use ($application) {
            // `workers.application_id` is cascadeOnDelete, so the rows go with
            // the application and take the only record of these units with
            // them. WorkerController::destroy has always removed the unit
            // before the row for exactly this reason; deleting the application
            // skipped it.
            $workers = app(WorkerSupervisor::class);

            // Queried rather than read off a relation: `Application` has none,
            // and adding one here would be a second way to reach the same rows
            // for the sake of one loop.
            foreach (Worker::where('application_id', $application->id)->get() as $worker) {
                $workers->remove($worker);
            }
        });

        $this->attempt($application, 'fail2ban', function () use ($application) {
            $this->fail2ban->disableForApp($application);
        });

        $this->attempt($application, 'certificate', function () use ($application) {
            $certificate = $application->certificate;

            // Only Let's Encrypt has anything to stop. A self-signed or
            // uploaded certificate is a pair of files under the site, and the
            // site is being removed.
            if ($certificate === null || $certificate->type !== CertificateType::LetsEncrypt) {
                return;
            }

            $domains = $certificate->domains ?? [];

            if ($domains === []) {
                return;
            }

            // The lineage is named after the first domain — see
            // CertbotClient::issue()'s `--cert-name`.
            $this->certbot->revoke($domains[0], $application->id);
        });
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
