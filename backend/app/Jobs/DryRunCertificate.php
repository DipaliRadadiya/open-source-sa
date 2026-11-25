<?php

namespace App\Jobs;

use App\Enums\DomainType;
use App\Jobs\Concerns\TracksActor;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Certificates\AcmeReachabilityCheck;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateDryRunStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rehearses an issuance and keeps nothing.
 *
 * Two stages, cheapest first. The panel's own reachability check (writes a token, fetches it
 * back; no ACME traffic) runs first. Only the names that pass go to `certbot --dry-run`, which
 * asks Let's Encrypt's staging server what the local check cannot: will the CA itself issue
 * (a CAA record naming another CA, a rate limit, an account problem).
 *
 * Queued like issuance: a staging authorisation outlasts a request.
 *
 * One attempt, no retries: a retry would turn one click into three authorisation attempts.
 */
class DryRunCertificate implements ShouldQueue
{
    use Queueable, TracksActor;

    public int $tries = 1;

    /** Beyond certbot's own ceiling, so the command decides when it has waited long enough. */
    public int $timeout = 600;

    public function __construct(
        public int $applicationId,
        public ?int $actorId = null,
    ) {}

    public function handle(
        AcmeReachabilityCheck $reachability,
        CertbotClient $certbot,
        CertificateDryRunStore $store,
        ActivityLogger $activityLogger,
    ): void {
        $application = Application::with('domains')->find($this->applicationId);

        if ($application === null) {
            // Deleted between the click and the worker; drop the state.
            $store->forget($this->applicationId);

            return;
        }

        $candidates = $application->domains
            ->sortBy(fn ($domain) => $domain->type === DomainType::Primary ? 0 : 1)
            ->values();

        $results = $reachability->checkAll($candidates);

        $passed = array_values(array_map(
            fn (array $result) => $result['domain'],
            array_filter($results, fn (array $result) => $result['ok']),
        ));

        if ($passed === []) {
            // Nothing worth asking the CA about: certbot would spend a real authorisation
            // failure to learn what the local check already showed.
            $this->finish($application, $store, $activityLogger, [
                'status' => 'failed',
                'stage' => 'reachability',
                'domains' => $results,
                // No summary reason: each name already carries its own failure sentence.
                'reason' => null,
            ]);

            return;
        }

        // Recorded before certbot runs, so a user polling mid-run sees which names got through.
        $store->finish($application->id, [
            'status' => 'running',
            'stage' => 'acme',
            'domains' => $results,
        ]);

        $certbot->ensureChallengeRoot();

        $result = $certbot->dryRun(
            $passed,
            (string) config('mail.from.address', ''),
            $application->id,
            (string) $application->slug,
        );

        $output = $result->output().$result->errorOutput();

        // Both halves required: certonly also exits 0 when it decides there is nothing to do,
        // so only certbot's "the dry run was successful" proves an authorisation completed.
        if (! $result->failed() && $certbot->confirmedDryRun($output)) {
            $this->finish($application, $store, $activityLogger, [
                'status' => 'passed',
                'stage' => 'acme',
                'domains' => $results,
                'reason' => null,
                'reference' => null,
            ]);

            return;
        }

        $this->finish($application, $store, $activityLogger, [
            'status' => 'failed',
            'stage' => 'acme',
            'domains' => $results,
            'reason' => $certbot->classify($output),
            'reference' => $result->reference,
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function finish(
        Application $application,
        CertificateDryRunStore $store,
        ActivityLogger $activityLogger,
        array $state,
    ): void {
        $store->finish($application->id, $state);

        $activityLogger->log('application.certificate_dry_run_finished', $application, [
            'domain' => $application->domain,
            'result' => $state['status'],
        ], actor: $this->actor());
    }

    /**
     * A crash must still leave a verdict the screen can read, or the state stays `running`
     * until the TTL expires.
     */
    public function failed(?Throwable $exception): void
    {
        $store = app(CertificateDryRunStore::class);

        if (! $store->isRunning($this->applicationId)) {
            return;
        }

        $store->finish($this->applicationId, [
            'status' => 'failed',
            'reason' => 'unknown',
        ]);
    }
}
