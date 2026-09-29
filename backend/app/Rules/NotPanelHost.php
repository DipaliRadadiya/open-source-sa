<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses the hostnames the panel itself is served on.
 *
 * Nothing stopped a site taking them (found in code review 2026-09-29), and
 * both halves of what followed are bad. The site's vhost and the panel's claim
 * the same server_name, so the web server picks one — possibly the site, and
 * the panel is gone from its own address. And install.sh issues the panel's
 * certificate with certbot's default lineage name, the first `-d`, which is
 * PANEL_HOST: a site whose primary is that name has its certificate issued
 * into the panel's lineage, and deleting the site's certificate deletes the
 * panel's.
 *
 * The hosts come from APP_URL and FRONTEND_URL, which install.sh writes as
 * API_HOST and PANEL_HOST.
 */
class NotPanelHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && in_array(strtolower(trim($value)), self::hosts(), true)) {
            $fail('errors/application.domain_is_panel')->translate();
        }
    }

    /**
     * @return array<int, string>
     */
    public static function hosts(): array
    {
        return collect([config('app.url'), config('server.storage.panel_url')])
            ->map(fn ($url) => is_string($url) ? parse_url($url, PHP_URL_HOST) : null)
            ->filter(fn ($host) => is_string($host) && $host !== '')
            ->map(fn (string $host) => strtolower($host))
            ->unique()
            ->values()
            ->all();
    }
}
