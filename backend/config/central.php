<?php

return [

    /*
     * Central's own address (RC-15, 2026-10-20). install.sh writes it when
     * Central installs the server. Empty: the panel never calls Central and
     * keeps its own branding (config/branding.php).
     */
    'url' => rtrim((string) env('CENTRAL_URL', ''), '/'),

    // How long Central's public branding is kept before it is asked again,
    // and how long a failed fetch is remembered so a Central that is down is
    // not asked on every page load.
    'branding_cache_seconds' => (int) env('CENTRAL_BRANDING_CACHE_SECONDS', 3600),
    'branding_retry_seconds' => (int) env('CENTRAL_BRANDING_RETRY_SECONDS', 300),

];
