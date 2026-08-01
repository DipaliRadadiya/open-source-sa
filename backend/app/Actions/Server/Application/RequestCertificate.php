<?php

namespace App\Actions\Server\Application;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Jobs\IssueCertificate;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Certificates\AcmeReachabilityCheck;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Records the intent to have a certificate and hands the work to a queue.
 *
 * Two decisions live here rather than in the job, because both are worth more
 * as an immediate answer than as something the user has to poll for.
 *
 * **The pre-check runs first.** Not a DNS lookup — a real dry run of the
 * challenge Let's Encrypt is about to perform. DNS pointing here says nothing
 * about whether the token will be served, and five failed authorisations per
 * hostname per hour means a doomed attempt locks the user out of their own fix.
 *
 * **Names are taken one at a time.** If three domains are attached and two are
 * ready, the certificate is issued for those two rather than refusing all
 * three. The common case is a site whose apex resolves and whose `www` record
 * has not propagated yet; blocking the whole thing over the slower of two DNS
 * records helps nobody, and `missing_domains` already tells the user what is
 * left to add.
 */
class RequestCertificate
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private AcmeReachabilityCheck $reachability,
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

        $certificate = Certificate::updateOrCreate(
            ['application_id' => $application->id],
            [
                'type' => $type,
                'status' => CertificateStatus::Pending,
                'domains' => $domains,
                'auto_renew' => $type->renewable(),
                'reason' => null,
                'reference' => null,
                // `force_https` is deliberately not reset: turning it off
                // because a reissue is in flight would silently change how the
                // site behaves for a reason the user never asked for.
            ],
        );

        $this->activityLogger->log('application.certificate_requested', $application, [
            'domain' => $application->domain,
            'type' => $type->value,
        ]);

        IssueCertificate::dispatch($certificate->id, Auth::id());

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
        $candidates = $application->domains
            ->filter(fn ($domain) => ! $domain->is_test)
            ->sortBy(fn ($domain) => $domain->type === DomainType::Primary ? 0 : 1)
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        // An escape hatch for one real case: a box behind NAT whose public
        // address does not answer to itself. The dry run fails there while the
        // real challenge, which arrives from outside, would succeed. Refusing
        // outright would make the feature unusable on those servers, so the
        // user is allowed to override — Let's Encrypt's own limit is still
        // there to stop them doing it repeatedly.
        if ($force) {
            return $candidates->pluck('domain')->all();
        }

        $results = $this->reachability->checkAll($candidates);

        $passed = array_values(array_map(
            fn (array $result) => $result['domain'],
            array_filter($results, fn (array $result) => $result['ok']),
        ));

        if ($passed === []) {
            // Nothing to issue, so say precisely why for each name. A single
            // "SSL failed" here would leave the user guessing between DNS, a
            // firewall and their own rewrite rules.
            throw ValidationException::withMessages([
                'domain' => array_map(
                    fn (array $result) => __('errors/certificate.precheck.'.$result['reason'], [
                        'domain' => $result['domain'],
                        'ip' => $result['resolved_ip'] ?? '—',
                    ]),
                    $results,
                ),
            ]);
        }

        return $passed;
    }

    /**
     * Self-signed is the one case where an unresolvable name is the point — an
     * internal or staging hostname Let's Encrypt could never validate. Running
     * the dry run here would refuse the only situation it exists for.
     *
     * @return array<int, string>
     */
    private function selfSignedNames(Application $application): array
    {
        $names = $application->certifiableDomains();

        return $names !== [] ? $names : $application->serverNames();
    }
}
