<?php

return [

    'name' => env('BRANDING_NAME', 'ServerAvatar'),

    'logo' => env('BRANDING_LOGO', 'https://app.serveravatar.com/logo/SaLogoDark.png'),

    'logo_dark' => env('BRANDING_LOGO_DARK', 'https://app.serveravatar.com/logo/dark-logo.png'),

    'icon' => env('BRANDING_ICON', 'https://app.serveravatar.com/logo/logo-sm.png'),

    'icon_dark' => env('BRANDING_ICON_DARK', 'https://app.serveravatar.com/logo/dark-logo-sm.png'),

    'favicon' => env('BRANDING_FAVICON', 'https://app.serveravatar.com/logo/logo-sm.png'),

    'primary_color' => env('BRANDING_PRIMARY_COLOR', '#076aff'),

    // The vendor's hosted control plane, which a self-hosted panel can be
    // connected to. Named here rather than in the code so a reseller's
    // installation shows their own product throughout.
    'central_name' => env('BRANDING_CENTRAL_NAME', env('BRANDING_NAME', 'ServerAvatar').' Central'),

];
