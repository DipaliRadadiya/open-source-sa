<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The newest published release, as reported by the release host.
 *
 * Everything here fails soft: "could not check" is a legitimate answer, and must not turn an
 * informational widget into a 500.
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

        // Cache the unavailable answer too, or a box with no network waits out the timeout on
        // every request.
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
                // GitHub requires one; kept product-neutral because it lands in third-party logs.
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
            // Tags look like `v1.2.3`; the panel uses bare versions.
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
     * Whether the published release is an update for what is installed.
     *
     * A fresh install is a shallow clone with no tags, so its version comes from the VERSION file
     * and can lag the code. When the version is only the file's word, code committed after the
     * release was published is not behind it.
     *
     * @param  array{version: ?string, source?: string, committed_at?: ?string}  $installed
     * @param  array{version: ?string, published_at?: ?string}  $available
     */
    public function isUpdate(array $installed, array $available): bool
    {
        if (! $this->isNewer($installed['version'], $available['version'])) {
            return false;
        }

        // Newer by name, but the code here may already contain that release (local tags not
        // fetched); only asked when the caller supplies the installed commit.
        $commit = $installed['commit_hash'] ?? null;

        if (is_string($commit) && app(InstalledPanelInfo::class)->alreadyContains((string) $available['version'], $commit) === true) {
            return false;
        }

        $fromFile = in_array($installed['source'] ?? null, ['file', 'unknown'], true);
        $committed = strtotime((string) ($installed['committed_at'] ?? ''));
        $published = strtotime((string) ($available['published_at'] ?? ''));

        return ! ($fromFile && $committed !== false && $published !== false && $committed >= $published);
    }

    /**
     * Whether `$available` is newer than `$installed`.
     *
     * Returns false whenever either side is unknown: no prompt beats a prompt on a guess.
     */
    public function isNewer(?string $installed, ?string $available): bool
    {
        if ($installed === null || $available === null) {
            return false;
        }

        return version_compare($available, $installed, '>');
    }
}
