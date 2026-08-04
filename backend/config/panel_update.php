<?php

return [
    /*
     * Where the panel looks for newer releases. The default is the public
     * GitHub Releases API for the OSS repository — no token, no auth, so a
     * self-hosted panel can check for updates out of the box.
     */
    'releases_url' => env(
        'PANEL_RELEASES_URL',
        'https://api.github.com/repos/DipaliRadadiya/open-source-sa/releases/latest',
    ),

    /*
     * How long a successful availability check is cached. GitHub's unauthenticated
     * rate limit is per-IP and shared with everything else on the box, so the
     * dashboard must not hit the API on every page load.
     */
    'cache_ttl_minutes' => (int) env('PANEL_UPDATE_CACHE_TTL', 60),

    /*
     * Outbound call budget. A slow or hanging release host must never hold a
     * php-fpm worker open — the check is a nicety, the panel works without it.
     */
    'timeout_seconds' => (int) env('PANEL_UPDATE_TIMEOUT', 8),

    /*
     * Preflight thresholds for an in-place update. The frontend build (npm ci
     * + next build) is by far the heaviest step: install.sh calls it "the slow
     * part" and it is what fails first on a small VPS.
     */
    'preflight' => [
        'min_free_disk_mb' => (int) env('PANEL_UPDATE_MIN_DISK_MB', 2048),
        'min_free_memory_mb' => (int) env('PANEL_UPDATE_MIN_MEMORY_MB', 768),
    ],
];
