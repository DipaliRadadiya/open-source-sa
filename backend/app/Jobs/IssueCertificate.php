<?php

namespace App\Jobs;

use App\Actions\Server\Application\ApplyVhost;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Panel\QueueWorker;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateExpiry;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\Certificates\CertificateLock;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Obtains a certificate and puts it in front of the site.
 *
 * Queued because ACME routinely outlasts the request; inline it would time out at the proxy while
 * certbot carried on and succeeded.
 *
 * One attempt, no retries: Let's Encrypt allows five failed authorisations per hostname per hour,
 * which an automatic retry would spend in seconds.
 *
 * It takes turns with deleting the site ({@see CertificateLock}), from the check that the site
 * exists to the certificate being in front of it. Finding the lock held, it dispatches a fresh copy
 * a little later instead of waiting inside the worker; that is not a retry of a failed certbot.
 */
class IssueCertificate implements ShouldQueue
{
    use Queueable, TracksActor;

    public int $tries = 1;

    /** Beyond certbot's own ceiling, so the command decides when it has waited long enough. */
    public int $timeout = 600;

    /**
     * How many times this certificate was put back because the site's certificate lock was held.
     * A plain property, not a constructor argument: older queued jobs unserialize to 0.
     */
    public int $requeues = 0;

    /**
     * @param  string|null  $previousCertName  the certbot lineage this replaces
     * @param  array<int, string>  $previousFiles  the uploaded or self-signed pair this replaces
     */
    public function __construct(
        public int $certificateId,
        public ?int $actorId = null,
        public ?string $previousCertName = null,
        public array $previousFiles = [],
        public ?string $previousUrl = null,
    ) {}

    public function handle(
        CertbotClient $certbot,
        CertificateFiles $files,
        ApplyVhost $vhost,
        WebServerManager $webServers,
        ActivityLogger $activityLogger,
        ?InstallerManager $installers = null,
        ?CertificateLock $locks = null,
    ): void {
        $installers ??= app(InstallerManager::class);
        $locks ??= app(CertificateLock::class);
        $certificate = Certificate::with('application.domains')->find($this->certificateId);

        if ($certificate === null || $certificate->application === null) {
            // The application was deleted between the request and the worker.
            return;
        }

        $name = $locks->name($certificate->application);
        $lock = $locks->forIssue($name);

        if ($lock === null) {
            // A delete of this site, or another issuance under this name, is running: back on the
            // queue, still Pending.
            $this->requeue($certificate, $name);

            return;
        }

        try {
            // Asked again under the lock: a delete that held it a moment ago may have removed the site.
            $certificate = Certificate::with('application.domains')->find($this->certificateId);

            if ($certificate === null || $certificate->application === null) {
                return;
            }

            $this->issue($certificate, $name, $certbot, $files, $vhost, $webServers, $activityLogger, $installers);
        } finally {
            $lock->release();
        }
    }

    private function issue(
        Certificate $certificate,
        string $name,
        CertbotClient $certbot,
        CertificateFiles $files,
        ApplyVhost $vhost,
        WebServerManager $webServers,
        ActivityLogger $activityLogger,
        InstallerManager $installers,
    ): void {
        // Requests snapshot the URL before Active becomes Pending; older queued jobs have none.
        $previousUrl = $this->previousUrl ?? $certificate->application->fresh(['certificate'])->url();

        $certificate->update([
            'status' => CertificateStatus::Issuing,
            'reason' => null,
            'reference' => null,
        ]);

        $domains = $certificate->domains;

        if ($domains === []) {
            $this->fail($certificate, 'no_certifiable_domains');

            return;
        }

        $paths = $certificate->type === CertificateType::SelfSigned
            ? $this->selfSign($certificate, $files, $domains)
            : $this->requestFromLetsEncrypt($certificate, $certbot, $domains);

        if ($paths === null) {
            return;
        }

        // The site went while certbot worked (a path that skips the lock, or a lapsed lock).
        // What was just made is nobody's: removed, unless a site now answers to the same name.
        $application = $certificate->application->fresh(['domains', 'certificate', 'systemUser']);

        if ($application === null || ! Certificate::whereKey($certificate->id)->exists()) {
            $this->discard($certificate, $name, $paths, $certbot, $files);

            return;
        }
        $targetUrl = $certificate->covers((string) $application->domain)
            ? 'https://'.$application->domain
            : 'http://'.$application->domain;

        try {
            // Reconcile before advertising the certificate as active: if the application's own
            // config cannot change, the HTTP site stays coherent and the job fails visibly.
            $installers->syncUrl($application, $targetUrl);

            $certificate->update([
                'status' => CertificateStatus::Active,
                'certificate_path' => $paths['certificate'],
                'private_key_path' => $paths['private_key'],
                'chain_path' => $paths['chain'] ?? null,
                // An uploaded key belongs to nothing once another certificate serves the site.
                'uploaded_private_key' => null,
                'issued_at' => now(),
                // Read off the file: Let's Encrypt lifetimes change, so 90 days must not be assumed.
                'expires_at' => $files->expiresAt($paths['certificate']),
                // What was served before belonged to the replaced certificate; asked again after reload.
                'served_expires_at' => null,
                'served_checked_at' => null,
            ]);

            // Only now does the vhost gain its TLS directives: pointing it at missing files fails the
            // config test and takes the site down.
            $vhost->execute($application->fresh(['domains', 'certificate']));
            app(CertificateExpiry::class)->recheckServed($certificate->fresh());
        } catch (Throwable $exception) {
            try {
                $installers->syncUrl($application, $previousUrl);
            } catch (Throwable) {
                // Preserve the original failure reference.
            }

            $certificate->update([
                'status' => CertificateStatus::Failed,
                'reason' => 'unknown',
            ]);

            throw $exception;
        }

        if ($certificate->type === CertificateType::LetsEncrypt) {
            $certbot->ensureRenewalHook(implode(' ', $webServers->driver()->reloadCommandForHook()));
        }

        // What this certificate replaced is removed only now, once the replacement is serving. A
        // lineage left behind renews forever (rate limit, emails about an unused name). Same name and
        // still Let's Encrypt means certbot reused it, and it is in use.
        if ($this->previousCertName !== null
            && ($certificate->type !== CertificateType::LetsEncrypt || $this->previousCertName !== $certificate->application->slug)) {
            $certbot->revoke($this->previousCertName, $certificate->application_id);
        }

        // An uploaded pair (the private key especially) must not outlive its certificate. A
        // self-signed reissue for the same name writes the same paths, which are kept.
        $staleFiles = array_values(array_diff($this->previousFiles, [$paths['certificate'], $paths['private_key']]));

        // v7's chain file beside a self-signed pair belonged to the replaced certificate.
        if ($certificate->type === CertificateType::SelfSigned) {
            try {
                $files->removeV7Bundle((string) $certificate->application->slug, $certificate->application_id);
            } catch (Throwable) {
                // Same as below: never fail a certificate that is serving.
            }
        }

        if ($staleFiles !== []) {
            try {
                $files->remove($staleFiles, (string) $certificate->application->slug, $certificate->application_id);
            } catch (Throwable) {
                // A path outside the certificate directory is refused and left alone; tidying up
                // must not fail a job whose new certificate is already serving.
            }
        }

        $activityLogger->log('application.certificate_issued', $certificate->application, [
            'domain' => $certificate->application->domain,
            'type' => $certificate->type->value,
        ], actor: $this->actor());
    }

    /**
     * @param  array<int, string>  $domains
     * @return array{certificate: string, private_key: string, chain?: string}|null
     */
    private function requestFromLetsEncrypt(Certificate $certificate, CertbotClient $certbot, array $domains): ?array
    {
        $certbot->ensureChallengeRoot();

        // Named after the site, as v7 does: a domain-named lineage would change with the primary
        // domain, and a server moved from v7 already has certificates under the site's name.
        $certName = (string) $certificate->application->slug;

        $result = $certbot->issue(
            $domains,
            (string) config('mail.from.address', ''),
            $certificate->application_id,
            $certName,
        );

        if ($result->failed()) {
            $this->fail(
                $certificate,
                $certbot->classify($result->output().$result->errorOutput()),
                $result->reference,
            );

            return null;
        }

        return $certbot->paths($certName);
    }

    /**
     * @param  array<int, string>  $domains
     * @return array{certificate: string, private_key: string}|null
     */
    private function selfSign(Certificate $certificate, CertificateFiles $files, array $domains): ?array
    {
        $name = (string) $certificate->application->slug;

        // Asked again: the queue may have held the job while something else took the name in /etc/ssl.
        if ($files->conflict($name, $this->previousFiles, $certificate->application->domainNames()) !== null) {
            $this->fail($certificate, 'file_taken');

            return null;
        }

        $result = $files->selfSign($domains, $name, $certificate->application_id);

        if ($result->failed()) {
            $this->fail($certificate, 'self_sign_failed', $result->reference);

            return null;
        }

        return $files->paths($name);
    }

    private function fail(Certificate $certificate, string $reason, ?string $reference = null): void
    {
        $certificate->update([
            'status' => CertificateStatus::Failed,
            'reason' => $reason,
            'reference' => $reference,
        ]);

        app(ActivityLogger::class)->log('application.certificate_failed', $certificate->application, [
            'domain' => $certificate->application->domain,
            'reason' => $reason,
        ], actor: $this->actor());
    }

    /**
     * Put this certificate back on the queue as a fresh job while the site's lock is held
     * (`$tries = 1` stays true of every copy). Bounded: past `max_requeues` the lock has outlived
     * even a dead holder's ttl, so the certificate is failed rather than left Pending.
     */
    private function requeue(Certificate $certificate, string $name): void
    {
        $max = max(0, (int) config('server.certificates.lock.max_requeues', 30));
        $delay = max(1, (int) config('server.certificates.lock.requeue_delay', 30));

        if ($this->requeues >= $max) {
            Log::channel('server-ops')->warning('certificate issue gave up waiting for the site lock', [
                'feature' => 'certificate',
                'op' => 'issue_lock_exhausted',
                'application' => $certificate->application_id,
                'lineage' => $name,
                'requeues' => $this->requeues,
            ]);

            $this->fail($certificate, 'unknown');

            return;
        }

        $next = new self($this->certificateId, $this->actorId, $this->previousCertName, $this->previousFiles, $this->previousUrl);
        $next->requeues = $this->requeues + 1;

        dispatch($next)
            ->onQueue($this->queue ?? app(QueueWorker::class)->priorityQueue())
            ->delay(now()->addSeconds($delay));

        Log::channel('server-ops')->info('certificate issue requeued: the site lock is held', [
            'feature' => 'certificate',
            'op' => 'issue_requeued',
            'application' => $certificate->application_id,
            'lineage' => $name,
            'requeues' => $next->requeues,
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * Remove what this job just created for a site that no longer exists, unless something live
     * claims the name: a site that has since taken the same slug, or another site's imported
     * lineage of that name, owns it. Then it is left, and logged.
     *
     * Never throws: the work is over, and a failed job would only repeat the crash.
     *
     * @param  array{certificate: string, private_key: string, chain?: string}  $paths
     */
    private function discard(Certificate $certificate, string $name, array $paths, CertbotClient $certbot, CertificateFiles $files): void
    {
        $reference = (string) Str::uuid();
        $context = [
            'feature' => 'certificate',
            'application' => $certificate->application_id,
            'lineage' => $name,
            'type' => $certificate->type->value,
            'reference' => $reference,
        ];

        // The name certbot and the self-signed writer were given is the slug; the lock's fallback
        // name for a blank slug names nothing on disk.
        if ($name !== (string) $certificate->application?->slug || $this->owned($certificate, $name, $paths)) {
            Log::channel('server-ops')->warning('certificate issued for a deleted site left to the site that now owns its name', $context + ['op' => 'certificate_left_for_owner']);

            return;
        }

        try {
            $result = $certificate->type === CertificateType::LetsEncrypt
                ? $certbot->revoke($name, $certificate->application_id)
                : $files->remove([$paths['certificate'], $paths['private_key']], $name, $certificate->application_id);
            $ok = $result->ok;
        } catch (Throwable) {
            $ok = false;
        }

        Log::channel('server-ops')->{$ok ? 'warning' : 'error'}('certificate issued for a deleted site was removed', $context + [
            'op' => 'certificate_discarded',
            'removed' => $ok,
        ]);
    }

    /**
     * Does a live site claim this name? By slug (what lineages and self-signed files are named
     * after) and, for Let's Encrypt, by any certificate whose lineage has this name (imported).
     *
     * @param  array{certificate: string, private_key: string, chain?: string}  $paths
     */
    private function owned(Certificate $certificate, string $name, array $paths): bool
    {
        if (Application::query()->where('slug', $name)->exists()) {
            return true;
        }

        if ($certificate->type !== CertificateType::LetsEncrypt) {
            return Certificate::query()->whereKeyNot($certificate->id)
                ->whereIn('certificate_path', [$paths['certificate'], $paths['private_key']])->exists();
        }

        return Certificate::query()->whereKeyNot($certificate->id)
            ->where('type', CertificateType::LetsEncrypt)
            ->with('application')
            ->get()
            ->contains(fn (Certificate $other): bool => $other->application !== null && $other->lineageName() === $name);
    }

    /**
     * A crash must still leave a readable row, or the certificate sits on `issuing` forever.
     */
    public function failed(?Throwable $exception): void
    {
        $certificate = Certificate::find($this->certificateId);

        if ($certificate === null || $certificate->status === CertificateStatus::Active) {
            return;
        }

        $certificate->update([
            'status' => CertificateStatus::Failed,
            'reason' => 'unknown',
        ]);
    }
}
