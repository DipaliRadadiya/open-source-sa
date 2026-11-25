<?php

namespace App\Services\Server\Certificates;

use Illuminate\Support\Facades\Cache;

/**
 * Where a dry run's progress and verdict live.
 *
 * The cache, not a table or the `certificates` row: a dry run must change nothing, and it is
 * short-lived diagnostic output ("is this domain ready right now"), so a TTL fits.
 *
 * Codes are stored, never sentences. The worker has no locale, so translation happens when the
 * result is read, in the reader's locale.
 */
class CertificateDryRunStore
{
    /** Long enough for a slow ACME round trip plus reading the result; short enough to avoid a stale verdict. */
    private const TTL_MINUTES = 30;

    public function key(int $applicationId): string
    {
        return "application.{$applicationId}.certificate_dry_run";
    }

    /**
     * @return array<string, mixed>
     */
    public function start(int $applicationId): array
    {
        $state = [
            'status' => 'running',
            'stage' => 'reachability',
            'domains' => [],
            'reason' => null,
            'reference' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];

        Cache::put($this->key($applicationId), $state, now()->addMinutes(self::TTL_MINUTES));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function finish(int $applicationId, array $state): array
    {
        $state = array_merge(
            $this->get($applicationId) ?? $this->blank(),
            $state,
            ['finished_at' => now()->toIso8601String()],
        );

        Cache::put($this->key($applicationId), $state, now()->addMinutes(self::TTL_MINUTES));

        return $state;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $applicationId): ?array
    {
        $state = Cache::get($this->key($applicationId));

        return is_array($state) ? $state : null;
    }

    public function isRunning(int $applicationId): bool
    {
        return ($this->get($applicationId)['status'] ?? null) === 'running';
    }

    public function forget(int $applicationId): void
    {
        Cache::forget($this->key($applicationId));
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        return [
            'status' => 'running',
            'stage' => 'reachability',
            'domains' => [],
            'reason' => null,
            'reference' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
