<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The newest published release, as reported by the release host.
 *
 * Everything here fails soft. A panel with no outbound network, a rate-limited
 * IP or a release host having a bad day must still render its admin screen —
 * "could not check" is a legitimate answer and is reported as such, rather than
 * throwing and turning an informational widget into a 500.
 */
class AvailableRelease
{
    private const CACHE_KEY = 'panel_update.latest_release';

    /**
     * @return array{
     *     version: ?string,
     *     published_at: ?string,
     *     notes: ?string,
     *     url: ?string,
     *     checked: bool,
     * }
     */
    public function latest(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $ttl = now()->addMinutes((int) config('panel_update.cache_ttl_minutes', 60));

        // Cache the *unavailable* answer too. Without this a box with no
        // outbound network re-attempts (and waits out the timeout) on every
        // single request to the admin dashboard.
        return Cache::remember(self::CACHE_KEY, $ttl, fn (): array => $this->fetch());
    }

    /**
     * @return array{
     *     version: ?string,
     *     published_at: ?string,
     *     notes: ?string,
     *     url: ?string,
     *     checked: bool,
     * }
     */
    private function fetch(): array
    {
        $unavailable = [
            'version' => null,
            'published_at' => null,
            'notes' => null,
            'url' => null,
            'checked' => false,
        ];

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                // GitHub rejects unauthenticated API calls without one. Kept
                // product-neutral deliberately: this string is written to a
                // third party's logs, and the panel is white-labelled.
                'User-Agent' => 'panel-update-check',
            ])
                ->timeout((int) config('panel_update.timeout_seconds', 8))
                ->connectTimeout(5)
                ->get((string) config('panel_update.releases_url'));
        } catch (Throwable $e) {
            Log::info('Panel update check could not reach the release host.', [
                'feature' => 'panel_update',
                'detail' => $e->getMessage(),
            ]);

            return $unavailable;
        }

        if (! $response->successful()) {
            Log::info('Panel update check got a non-success response.', [
                'feature' => 'panel_update',
                'status' => $response->status(),
            ]);

            return $unavailable;
        }

        $tag = $response->json('tag_name');

        if (! is_string($tag) || $tag === '') {
            return $unavailable;
        }

        return [
            // Releases are conventionally tagged `v1.2.3`; the panel compares
            // and displays bare versions.
            'version' => ltrim($tag, 'vV'),
            'published_at' => is_string($response->json('published_at'))
                ? $response->json('published_at')
                : null,
            'notes' => is_string($response->json('body')) ? $response->json('body') : null,
            'url' => is_string($response->json('html_url')) ? $response->json('html_url') : null,
            'checked' => true,
        ];
    }

    /**
     * Whether `$available` is newer than `$installed`.
     *
     * Returns false whenever either side is unknown. An update prompt shown on
     * a guess is worse than no prompt: it invites the user to run a mutating,
     * downtime-incurring operation for no reason.
     */
    public function isNewer(?string $installed, ?string $available): bool
    {
        if ($installed === null || $available === null) {
            return false;
        }

        return version_compare($available, $installed, '>');
    }
}
