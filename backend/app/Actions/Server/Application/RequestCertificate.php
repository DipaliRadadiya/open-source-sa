<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Panel\QueueWorker;
use App\Services\Server\Certificates\AcmeReachabilityCheck;
use App\Services\Server\Certificates\CertificateFiles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Records the intent to have a certificate and hands the work to a queue.
 *
 * Two decisions live here, not in the job, so the user gets an immediate answer:
 *
 * **The pre-check runs first.** A real dry run of the Let's Encrypt challenge, not a DNS
 * lookup. Let's Encrypt allows five failed authorisations per hostname per hour, so a
 * doomed attempt would lock the user out of their own fix.
 *
 * **Names are taken one at a time.** If only some attached domains are ready (apex resolves,
 * `www` not yet), the certificate is issued for those; `missing_domains` lists the rest.
 */
class RequestCertificate
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private AcmeReachabilityCheck $reachability,
        private CertificateFiles $files,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(Application $application, CertificateType $type, bool $force = false): Certificate
    {
        $domains = $type === CertificateType::SelfSigned
            ? $this->selfSignedNames($application)
            : $this->reachableNames($application, $force);

        if ($domains === []) {
            throw ValidationException::withMessages([
                'domain' => [__('errors/certificate.no_certifiable_domains')],
            ]);
        }

        // Captured before the row is overwritten. certbot names a lineage after the first domain
        // (`--cert-name`), so a changed primary domain creates a second lineage and the old one
        // keeps renewing. The job removes it once the replacement works.
        $existing = Certificate::where('application_id', $application->id)->first();

        $previousCertName = $existing?->lineageName();
        $previousUrl = $application->fresh(['certificate'])->url();

        // Same for an uploaded or self-signed pair: the job removes it once the replacement serves.
        $previousFiles = $existing !== null && $existing->type !== CertificateType::LetsEncrypt
            ? array_values(array_filter([
                $existing->certificate_path,
                $existing->private_key_path,
                $existing->servesSeparateChain() ? $existing->chain_path : null,
            ]))
            : [];

        // A self-signed pair goes into /etc/ssl. Refused here, before anything changes, when a
        // file already belongs to something else; the job checks again before writing.
        if ($type === CertificateType::SelfSigned
            && ($taken = $this->files->conflict($application->slug, $previousFiles, $application->domainNames())) !== null) {
            throw ValidationException::withMessages([
                'type' => [__('certificate.file_taken', ['path' => $taken])],
            ]);
        }

        $certificate = Certificate::updateOrCreate(
            ['application_id' => $application->id],
            [
                'type' => $type,
                'status' => CertificateStatus::Pending,
                'domains' => $domains,
                'auto_renew' => $type->renewable(),
                'reason' => null,
                'reference' => null,
                // `force_https` is deliberately not reset: a reissue must not change site behaviour.
            ],
        );

        $this->activityLogger->log('application.certificate_requested', $application, [
            'domain' => $application->domain,
            'type' => $type->value,
        ]);

        IssueCertificate::dispatch($certificate->id, Auth::id(), $previousCertName, $previousFiles, $previousUrl)
            ->onQueue(app(QueueWorker::class)->priorityQueue());

        return $certificate->refresh();
    }

    /**
     * The names that pass the dry run, primary first.
     *
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    private function reachableNames(Application $application, bool $force): array
    {
        // Every name the site has, primary first. None is excluded here: the dry run below
        // decides whether Let's Encrypt can issue for it.
        $candidates = $application->domains
            ->sortBy(fn ($domain) => $domain->type === DomainType::Primary ? 0 : 1)
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        // Escape hatch for a server behind NAT whose public address does not answer to itself:
        // the dry run fails there although the real challenge, arriving from outside, would pass.
        if ($force) {
            return $candidates->pluck('domain')->all();
        }

        $results = $this->reachability->checkAll($candidates);

        $passed = array_values(array_map(
            fn (array $result) => $result['domain'],
            array_filter($results, fn (array $result) => $result['ok']),
        ));

        if ($passed === []) {
            // Nothing to issue: say why for each name (DNS, firewall, rewrite rules).
            throw ValidationException::withMessages([
                'domain' => array_map(
                    fn (array $result) => $this->reachability->describe($result),
                    $results,
                ),
            ]);
        }

        return $passed;
    }

    /**
     * Self-signed exists for names Let's Encrypt cannot validate (internal, staging), so the
     * dry run is skipped.
     *
     * @return array<int, string>
     */
    private function selfSignedNames(Application $application): array
    {
        $names = $application->certifiableDomains();

        return $names !== [] ? $names : $application->serverNames();
    }
}
