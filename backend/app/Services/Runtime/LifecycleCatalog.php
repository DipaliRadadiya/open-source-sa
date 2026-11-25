<?php

namespace App\Services\Runtime;

use App\Models\RuntimeLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which runtime versions are supported, security-only or dead, sourced upstream and never
 * fetched inside a request.
 *
 * - Node LTS is read from nodejs/Release/schedule.json, never inferred from an even major.
 * - PHP gets no `lts` flag: it has active support, then security-only.
 * - Reads come from the database only, so a firewalled panel never hangs on github. A miss
 *   returns null and the frontend shows no badge.
 * - Stored in a table, not the cache: `optimize:clear` on deploy would blank every badge until
 *   the next daily refresh.
 */
class LifecycleCatalog
{
    /**
     * Lifecycle facts for one version, or null when unknown.
     *
     * @return array{status: string, eol_date: string|null, lts_name?: string|null}|null
     */
    public function for(string $runtime, string $version): ?array
    {
        $major = $this->major($runtime, $version);

        return $this->all()[$runtime][$major] ?? null;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function all(): array
    {
        return RuntimeLifecycle::query()
            ->get()
            ->groupBy('runtime')
            ->map(fn ($rows, string $runtime) => $rows->mapWithKeys(fn (RuntimeLifecycle $row) => [
                $row->version => [
                    'status' => $row->status,
                    'eol_date' => $row->eol_date?->toDateString(),
                    // Keyed on the runtime, not on null: an odd Node major has no codename but
                    // still has the concept; PHP has no LTS at all.
                    ...($runtime === 'node' ? ['lts_name' => $row->lts_name] : []),
                ],
            ])->all())
            ->all();
    }

    /**
     * True when there is no data at all (no egress, or refresh not run yet), so the frontend
     * hides the badges instead of showing every version as unknown.
     */
    public function isStale(): bool
    {
        return ! RuntimeLifecycle::query()->exists();
    }

    /**
     * Fetch upstream and replace the stored data. Called by a scheduled command, never a request.
     *
     * @return array{node: int, php: int}
     */
    public function refresh(): array
    {
        $counts = [];

        foreach (['node' => fn () => $this->fetchNode(), 'php' => fn () => $this->fetchPhp()] as $runtime => $fetch) {
            try {
                $fresh = $fetch();
            } catch (Throwable $e) {
                // Keep what is stored: a network blip must not blank the badges.
                Log::warning('runtime lifecycle refresh failed', ['runtime' => $runtime, 'error' => $e->getMessage()]);
                $counts[$runtime] = RuntimeLifecycle::query()->where('runtime', $runtime)->count();

                continue;
            }

            foreach ($fresh as $version => $row) {
                RuntimeLifecycle::updateOrCreate(
                    ['runtime' => $runtime, 'version' => (string) $version],
                    [
                        'status' => $row['status'],
                        'eol_date' => $row['eol_date'],
                        'lts_name' => $row['lts_name'] ?? null,
                    ],
                );
            }

            $counts[$runtime] = count($fresh);
        }

        return ['node' => $counts['node'] ?? 0, 'php' => $counts['php'] ?? 0];
    }

    /**
     * Node's own release schedule, maintained by the project.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchNode(): array
    {
        $response = Http::timeout(10)->get((string) config('server.runtimes.lifecycle.node_url'));

        if (! $response->successful()) {
            return [];
        }

        $today = CarbonImmutable::now()->startOfDay();
        $out = [];

        foreach ($response->json() ?? [] as $major => $row) {
            $major = ltrim((string) $major, 'v');
            $end = $this->date($row['end'] ?? null);

            // `lts` is the date the line entered LTS; absent means it never will (odd lines).
            $ltsFrom = $this->date($row['lts'] ?? null);
            $maintenanceFrom = $this->date($row['maintenance'] ?? null);

            $out[$major] = [
                'status' => match (true) {
                    $end !== null && $today->greaterThan($end) => 'eol',
                    $maintenanceFrom !== null && $today->greaterThanOrEqualTo($maintenanceFrom) => 'maintenance',
                    $ltsFrom !== null && $today->greaterThanOrEqualTo($ltsFrom) => 'lts',
                    default => 'current',
                },
                'eol_date' => $end?->toDateString(),
                'lts_name' => $row['codename'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * PHP has no LTS — active support, then security-only, then end of life.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchPhp(): array
    {
        $response = Http::timeout(10)->get((string) config('server.runtimes.lifecycle.php_url'));

        if (! $response->successful()) {
            return [];
        }

        $today = CarbonImmutable::now()->startOfDay();
        $out = [];

        foreach ($response->json() ?? [] as $row) {
            $cycle = (string) ($row['cycle'] ?? '');

            if ($cycle === '') {
                continue;
            }

            $eol = $this->date($row['eol'] ?? null);
            $activeUntil = $this->date($row['support'] ?? null);

            $out[$cycle] = [
                'status' => match (true) {
                    $eol !== null && $today->greaterThan($eol) => 'eol',
                    $activeUntil !== null && $today->greaterThan($activeUntil) => 'security',
                    default => 'active',
                },
                'eol_date' => $eol?->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * Node lifecycle is per major (`22`); PHP's is per minor (`8.4`).
     */
    private function major(string $runtime, string $version): string
    {
        $parts = explode('.', $version);

        return $runtime === 'php'
            ? implode('.', array_slice($parts, 0, 2))
            : ($parts[0] ?? $version);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        // endoflife.date uses `true`/`false` instead of a date on some cycles.
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
