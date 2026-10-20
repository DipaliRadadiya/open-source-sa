<?php

namespace App\Services\Central;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The branding Central publishes, read from its public site settings
 * (`GET {central}/api/v1/site-settings`, no login), for the panel to show
 * (2026-10-20, operator's call).
 *
 * Central is the one place a reseller sets their name, logo and colour; the
 * panel used to carry its own copy in .env. Every value is checked before it is
 * used — a logo must be an http(s) URL, a colour a hex code — and anything
 * missing or unusable falls back to the panel's own (config/branding.php).
 * A Central that cannot be reached never breaks the panel: the last good answer
 * is kept for an hour, a failure is remembered for five minutes, and the
 * panel's own branding stands in meanwhile.
 */
class CentralBranding
{
    private const CACHE_KEY = 'central.branding';

    /**
     * Central's values, mapped to the panel's branding keys, or null when there
     * is no Central or it did not answer.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array
    {
        $url = (string) config('central.url');

        if ($url === '') {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached['branding'];
        }

        $branding = $this->ask($url);

        Cache::put(
            self::CACHE_KEY,
            ['branding' => $branding],
            $branding === null ? (int) config('central.branding_retry_seconds', 300) : (int) config('central.branding_cache_seconds', 3600),
        );

        return $branding;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed>|null */
    private function ask(string $url): ?array
    {
        try {
            $response = Http::acceptJson()->timeout(5)->connectTimeout(3)->get($url.'/api/v1/site-settings');
        } catch (Throwable $e) {
            Log::warning('central branding unreachable', ['detail' => $e->getMessage()]);

            return null;
        }

        // A Laravel resource: the settings sit under `data`.
        $settings = $response->successful() ? $response->json('data') : null;

        if (! is_array($settings)) {
            return null;
        }

        return array_filter([
            'name' => $this->text($settings['name'] ?? null, 80),
            'tag_line' => $this->text($settings['tag_line'] ?? null, 200),
            'logo' => $this->link($settings['logo_url'] ?? null),
            'icon' => $this->link($settings['icon_url'] ?? null),
            'favicon' => $this->link($settings['favicon_url'] ?? null),
            'primary_color' => $this->colour($settings['color_code'] ?? null),
            'links' => array_filter([
                'privacy_policy' => $this->link($settings['privacy_policy_link'] ?? null),
                'terms' => $this->link($settings['terms_link'] ?? null),
                'refund_policy' => $this->link($settings['refund_policy_link'] ?? null),
                'gdpr' => $this->link($settings['gdpr_link'] ?? null),
            ]) ?: null,
            'display_message' => $this->text($settings['display_message'] ?? null, 500),
        ], fn ($value) => $value !== null);
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    /** http(s) only: a `javascript:` logo would otherwise reach an <img> or <a>. */
    private function link(mixed $value): ?string
    {
        return is_string($value) && preg_match('#\Ahttps?://[^\s"\'<>]+\z#i', $value) === 1 && strlen($value) <= 2048 ? $value : null;
    }

    /** Central stores it without the `#` (`35b87a`); the panel sends `#35b87a`. */
    private function colour(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A#?([0-9a-f]{3}|[0-9a-f]{6})\z/i', trim($value), $m) !== 1) {
            return null;
        }

        return '#'.strtolower($m[1]);
    }
}
