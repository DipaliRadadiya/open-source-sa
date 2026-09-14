<?php

namespace App\Services\Server\Certificates;

use Illuminate\Support\Facades\Cache;

/**
 * Where a dry run's progress and verdict live.
 *
 * The cache, not a table, and not the `certificates` row. A dry run exists
 * precisely to change nothing, so giving it a column beside the real
 * certificate would be the one way to make it change something — a status the
 * SSL card reads, an `updated_at` that moves, a row created for a site that
 * has no certificate and did not ask for one.
 *
 * It is also diagnostic output with a short useful life: it answers "is this
 * domain ready right now", and an answer from last Tuesday is worse than no
 * answer, because it will be believed. A TTL expresses that; a migration would
 * have to grow a pruning job to say the same thing.
 *
 * Codes are stored, never sentences. The worker has no locale — it would write
 * whatever `app.locale` happens to be — so the translation is done when the
 * result is read, in the locale of whoever is reading it.
 */
class CertificateDryRunStore
{
    /**
     * Long enough to survive a slow ACME round trip plus the user reading the
     * result; short enough that a stale verdict cannot be mistaken for a fresh
     * one after DNS has changed underneath it.
     */
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
