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
 * Two stages, in the order that costs least. The panel's own reachability
 * check runs first: it writes a token, fetches it back, and is free — no ACME
 * traffic, so it can be run as often as a user needs while they fix DNS. Only
 * the names that survive it are handed to `certbot --dry-run`, which asks
 * Let's Encrypt's staging server the question the local check cannot: will the
 * CA itself issue for this name. A CAA record naming a different CA, a
 * rate limit already in force, an account problem — all pass the local check
 * and fail the real one, and finding that out here costs nothing.
 *
 * Queued for the same reason issuance is: a staging authorisation is a round
 * trip out to the CA and back to this box, and it outlasts a request.
 *
 * One attempt, no retries. The dry run spends the *staging* budget rather than
 * the production one, but a job that retried itself would still turn one click
 * into three authorisation attempts, and the whole point of this feature is to
 * stop a user burning attempts on a domain that is not ready.
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
            // Deleted between the click and the worker. Drop the state rather
            // than leaving a result pinned to an application that is gone.
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
            // Nothing worth asking the CA about. Stopping here is the point of
            // running this stage first — certbot would spend a real
            // authorisation failure to learn what a local HTTP request just
            // established for free.
            $this->finish($application, $store, $activityLogger, [
                'status' => 'failed',
                'stage' => 'reachability',
                'domains' => $results,
                'reason' => 'no_certifiable_domains',
            ]);

            return;
        }

        // Recorded before certbot runs, so a user polling mid-run sees which
        // names got through and that the slow half has started, rather than an
        // unexplained thirty seconds of spinner.
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
        );

        $output = $result->output().$result->errorOutput();

        // Both halves required. A non-zero exit is a failure; a zero exit is
        // not yet a success, because certonly also exits 0 when it decides
        // there is nothing to do. Only certbot's own "the dry run was
        // successful" says an authorisation actually completed.
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
     * A crash still has to leave a verdict the screen can read. Without this
     * the state sits on `running` until the TTL expires and the only honest
     * thing the dialog could show is a spinner that never stops.
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
