<?php

namespace App\Jobs;

use App\Actions\Server\Application\ApplyVhost;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Jobs\Concerns\TracksActor;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
 */
class IssueCertificate implements ShouldQueue
{
    use Queueable, TracksActor;

    public int $tries = 1;

    /** Beyond certbot's own ceiling, so the command decides when it has waited long enough. */
    public int $timeout = 600;

    /**
     * @param  string|null  $previousCertName  the certbot lineage this replaces,
     *                                         when reissuing changed its name
     */
    public function __construct(
        public int $certificateId,
        public ?int $actorId = null,
        public ?string $previousCertName = null,
    ) {}

    public function handle(
        CertbotClient $certbot,
        CertificateFiles $files,
        ApplyVhost $vhost,
        WebServerManager $webServers,
        ActivityLogger $activityLogger,
    ): void {
        $certificate = Certificate::with('application.domains')->find($this->certificateId);

        if ($certificate === null || $certificate->application === null) {
            // The application was deleted between the request and the worker.
            return;
        }

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

        $certificate->update([
            'status' => CertificateStatus::Active,
            'certificate_path' => $paths['certificate'],
            'private_key_path' => $paths['private_key'],
            'chain_path' => $paths['chain'] ?? null,
            'issued_at' => now(),
            // Read off the file rather than assumed from the lifetime. Let's
            // Encrypt has started issuing shorter-lived certificates, so a
            // hardcoded 90 days would quietly become wrong.
            'expires_at' => $files->expiresAt($paths['certificate']),
        ]);

        // Only now does the vhost gain its TLS directives — pointing a server
        // block at files that are not there fails the config test and takes the
        // site down over a certificate it never had.
        $vhost->execute($certificate->application->fresh(['domains', 'certificate']));

        if ($certificate->type === CertificateType::LetsEncrypt) {
            $certbot->ensureRenewalHook(implode(' ', $webServers->driver()->reloadCommandForHook()));

            // The lineage this one replaced, when reissuing renamed it —
            // changing the primary domain does that, because certbot names a
            // lineage after the first domain. Removed only now: the site is
            // already serving the replacement, so nothing is left without a
            // certificate at any point. Left behind, the old lineage renews
            // itself forever for a name nothing answers to.
            if ($this->previousCertName !== null && $this->previousCertName !== $domains[0]) {
                $certbot->revoke($this->previousCertName, $certificate->application_id);
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

        $result = $certbot->issue(
            $domains,
            (string) config('mail.from.address', ''),
            $certificate->application_id,
        );

        if ($result->failed()) {
            $this->fail(
                $certificate,
                $certbot->classify($result->output().$result->errorOutput()),
                $result->reference,
            );

            return null;
        }

        return $certbot->paths($domains[0]);
    }

    /**
     * @param  array<int, string>  $domains
     * @return array{certificate: string, private_key: string}|null
     */
    private function selfSign(Certificate $certificate, CertificateFiles $files, array $domains): ?array
    {
        $result = $files->selfSign($domains, $certificate->application_id);

        if ($result->failed()) {
            $this->fail($certificate, 'self_sign_failed', $result->reference);

            return null;
        }

        return $files->paths($domains[0]);
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
