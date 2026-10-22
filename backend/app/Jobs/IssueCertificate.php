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
 * Queued because ACME is not fast: two DNS lookups, an HTTP round trip back to
 * this box and a retry, which routinely outlasts the request. Inline it would
 * time out at the proxy while certbot carried on and succeeded — telling the
 * user it failed about work that worked.
 *
 * One attempt, no retries, and this one matters more than usual. Let's Encrypt
 * counts five failed authorisations per hostname per hour; a job that retried
 * on its own would spend that budget in seconds and lock the user out of the
 * thing they are trying to fix.
 *
 * It takes turns with deleting the site ({@see CertificateLock}), from the
 * check that the site still exists to the certificate being in front of it.
 * Found waiting, it does not wait inside the worker — that spends this job's
 * own timeout — but dispatches a fresh copy of itself a little later, which
 * is still one attempt and never a retry of a certbot that failed.
 */
class IssueCertificate implements ShouldQueue
{
    use Queueable, TracksActor;

    public int $tries = 1;

    /** Beyond certbot's own ceiling, so the command decides when it has waited long enough. */
    public int $timeout = 600;

    /**
     * How many times this certificate has already been put back because the
     * site's certificate lock was held. A plain property, not a constructor
     * argument: a job queued before this existed unserializes to 0.
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
            // A delete of this site — or another issuance under this name — is
            // running. Back on the queue, untouched: still Pending.
            $this->requeue($certificate, $name);

            return;
        }

        try {
            // Asked again now that nothing can delete the site underneath:
            // a delete that held the lock until a moment ago has removed it.
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
        // Requests snapshot before changing Active to Pending. Older queued
        // jobs have no snapshot; read their current scheme before Issuing.
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

        // The site went while certbot was working — by a path that does not
        // take the lock (a half-made clone or staging copy being discarded),
        // or after a holder died and its lock lapsed. What was just made is
        // nobody's: removed, unless a site now answers to the same name.
        $application = $certificate->application->fresh(['domains', 'certificate', 'systemUser']);

        if ($application === null || ! Certificate::whereKey($certificate->id)->exists()) {
            $this->discard($certificate, $name, $paths, $certbot, $files);

            return;
        }
        $targetUrl = $certificate->covers((string) $application->domain)
            ? 'https://'.$application->domain
            : 'http://'.$application->domain;

        try {
            // Reconcile before advertising the certificate as active. If an
            // application's own config cannot be changed, its existing HTTP
            // site stays coherent and the certificate job fails visibly.
            $installers->syncUrl($application, $targetUrl);

            $certificate->update([
                'status' => CertificateStatus::Active,
                'certificate_path' => $paths['certificate'],
                'private_key_path' => $paths['private_key'],
                'chain_path' => $paths['chain'] ?? null,
                // An uploaded key has nothing left to belong to once another
                // certificate is serving the site.
                'uploaded_private_key' => null,
                'issued_at' => now(),
                // Read off the file rather than assumed from the lifetime. Let's
                // Encrypt has started issuing shorter-lived certificates, so a
                // hardcoded 90 days would quietly become wrong.
                'expires_at' => $files->expiresAt($paths['certificate']),
                // What was served before belongs to the certificate this one
                // replaces (FS-C47); asked again once the vhost has reloaded.
                'served_expires_at' => null,
                'served_checked_at' => null,
            ]);

            // Only now does the vhost gain its TLS directives — pointing a
            // server block at files that are not there fails the config test
            // and takes the site down over a certificate it never had.
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

        // What this certificate replaced, removed only now: the site is
        // already serving the replacement, so nothing is left without a
        // certificate at any point. A lineage left behind renews itself
        // forever — spending rate limit and mailing the user about a name
        // nothing uses — whether it was renamed by a primary-domain change or
        // replaced by a self-signed certificate. Same lineage name and still
        // Let's Encrypt means certbot reused it, and it is the one in use.
        if ($this->previousCertName !== null
            && ($certificate->type !== CertificateType::LetsEncrypt || $this->previousCertName !== $certificate->application->slug)) {
            $certbot->revoke($this->previousCertName, $certificate->application_id);
        }

        // An uploaded pair — the private key especially — should not outlive
        // the certificate it belonged to. A self-signed reissue for the same
        // name writes to the same paths, so those are not touched.
        $staleFiles = array_values(array_diff($this->previousFiles, [$paths['certificate'], $paths['private_key']]));

        // A self-signed pair written over a certificate v7 left here: v7's
        // chain file beside it belonged to the certificate that was replaced.
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
                // A path outside the certificate directory is refused and left
                // alone. The new certificate is already serving; tidying up the
                // old one must not turn that into a failed job.
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

        // Named after the site, as v7 names it (v7 layout B3): a lineage named
        // after a domain changed name with the primary domain, and a server
        // moved from v7 already has its certificates under the site's name.
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

        // Asked again here, not only when it was requested: the queue may have
        // held the job while something else took the name in /etc/ssl.
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
     * Put this certificate back on the queue while the site's lock is held,
     * as a fresh job: `$tries = 1` stays true of every copy. Bounded — past
     * `max_requeues` the lock has outlived even a dead holder's ttl, and the
     * certificate is failed rather than left on Pending.
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
     * Remove what this job just created for a site that no longer exists —
     * but only if nothing live claims the name. A site that has since taken
     * the same slug owns the lineage now (its own request reuses it, its own
     * delete removes it); another site's imported lineage of that name is
     * that site's. Either way it is left, and said so.
     *
     * Never throws: the work is over, and a failed job here would only repeat
     * the crash this replaces.
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

        // What certbot and the self-signed writer were given — the slug. The
        // lock's fallback name for a blank slug names nothing on disk.
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
     * Does a live site claim this name? By slug, which is what lineages and
     * self-signed files are named after; and, for Let's Encrypt, by any
     * certificate whose lineage on disk has this name (an imported one).
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
     * A crash still has to leave a row the screen can read. Without this the
     * certificate sits on `issuing` forever and the only honest thing the UI
     * could show is a spinner that never stops.
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
