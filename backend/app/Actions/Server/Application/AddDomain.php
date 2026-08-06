<?php

namespace App\Actions\Server\Application;

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\DnsVerifier;

/**
 * Attach another name to an application and rewrite its vhost.
 *
 * The DNS check happens here rather than when a certificate is requested,
 * because by then it is too late to be useful: Let's Encrypt allows five
 * authorisation failures per hostname per hour, and the user has no way to
 * know why the fifth one stopped working.
 */
class AddDomain
{
    public function __construct(
        private DnsVerifier $dns,
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Application $application, array $data): ApplicationDomain
    {
        $domain = $application->domains()->create([
            'domain' => strtolower(trim((string) $data['domain'])),
            'type' => $data['type'] ?? DomainType::Alias->value,
            'redirect_to' => $data['redirect_to'] ?? null,
            'redirect_status' => $data['redirect_status'] ?? 301,
            // Never set before, which meant the flag the certificate actions
            // filter on was always false — so the guard against spending the
            // shared nip.io rate limit had never once fired.
            'is_test' => ApplicationDomain::looksTemporary((string) $data['domain']),
        ]);

        $this->dns->verify($domain);

        // A redirect is served from its own server block, so the config has to
        // be rewritten either way — an alias joins the existing block, a
        // redirect adds one.
        $this->vhost->execute($application);

        $this->activityLogger->log('application.domain_added', $application, [
            'domain' => $domain->domain,
            'type' => $domain->type->value,
        ]);

        return $domain->refresh();
    }
}
