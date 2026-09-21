<?php

return [

    /*
     * `?:` rather than only an `env()` default, because the two differ for the
     * case that reaches users: `BRANDING_NAME=` with nothing after it is a
     * *set* variable, so env()'s default never applies and the name resolves
     * to an empty string. That is not hypothetical — every consumer then has
     * to invent its own fallback, and the one in GoogleDriveWorkspace put this
     * product's name inside `app/`, where WhiteLabelTest forbids it, and from
     * there onto a folder created in a customer's personal Google Drive.
     *
     * Resolved once, here. This is the file a deployment is meant to override
     * and the only one exempt from the white-label check, so it is the correct
     * — and the only correct — place for the literal to live.
     */
    'name' => trim((string) env('BRANDING_NAME')) ?: 'ServerAvatar',

    'logo' => env('BRANDING_LOGO', 'https://app.serveravatar.com/logo/SaLogoDark.png'),

    'logo_dark' => env('BRANDING_LOGO_DARK', 'https://app.serveravatar.com/logo/dark-logo.png'),

    'icon' => env('BRANDING_ICON', 'https://app.serveravatar.com/logo/logo-sm.png'),

    'icon_dark' => env('BRANDING_ICON_DARK', 'https://app.serveravatar.com/logo/dark-logo-sm.png'),

    'favicon' => env('BRANDING_FAVICON', 'https://app.serveravatar.com/logo/logo-sm.png'),

    'primary_color' => env('BRANDING_PRIMARY_COLOR', '#076aff'),

];
