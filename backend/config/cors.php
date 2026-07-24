<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | No wildcard: explicit origins only. Defaults cover common local dev
    | frontend ports; add the real frontend domain via FRONTEND_URL in .env
    | once it's deployed.
    */
    'allowed_origins' => array_filter([
        'http://localhost:3000',
        'http://localhost:5173',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5173',
        env('FRONTEND_URL'),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // true: required for Sanctum cookie/session auth (statefulApi()) to work
    // cross-port/subdomain in local dev. Safe because allowed_origins above
    // is an explicit list, never a wildcard.
    'supports_credentials' => true,

];
