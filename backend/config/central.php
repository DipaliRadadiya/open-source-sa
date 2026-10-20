<?php

return [

    /*
     * Central's own address (RC-15, 2026-10-20). install.sh writes it when
     * Central installs the server; `CENTRAL_URL=` (set, empty) turns Central
     * off and the panel keeps its own branding (config/branding.php).
     *
     * The default is the new Central (operator, 2026-10-20). v7's
     * api.serveravatar.com has no /api/v1/site-settings (404). Change it here
     * when Central moves to its production address.
     */
    'url' => rtrim((string) env('CENTRAL_URL', 'https://sa-central-api.23-172-120-38.nip.io'), '/'),

    // How long Central's public branding is kept before it is asked again,
    // and how long a failed fetch is remembered so a Central that is down is
    // not asked on every page load.
    'branding_cache_seconds' => (int) env('CENTRAL_BRANDING_CACHE_SECONDS', 3600),
    'branding_retry_seconds' => (int) env('CENTRAL_BRANDING_RETRY_SECONDS', 300),

];
