<?php

return [
    'path' => resource_path('recipes'),
    'hook_namespaces' => ['App\\Services\\Recipes\\Hooks\\'],
    // Frozen compatibility map for the 19 legacy one-click apps. New recipes
    // take their default images directly from their metadata, with no config entry.
    'image_overrides' => [
        'matomo' => ['app' => env('DOCKER_APP_MATOMO_IMAGE'), 'db' => env('DOCKER_APP_MATOMO_DB_IMAGE')],
        'grafana' => ['app' => env('DOCKER_APP_GRAFANA_IMAGE')],
        'bookstack' => ['app' => env('DOCKER_APP_BOOKSTACK_IMAGE'), 'db' => env('DOCKER_APP_BOOKSTACK_DB_IMAGE')],
        'wordpress_container' => ['app' => env('DOCKER_APP_WORDPRESS_IMAGE'), 'db' => env('DOCKER_APP_WORDPRESS_DB_IMAGE')],
        'mattermost' => ['app' => env('DOCKER_APP_MATTERMOST_IMAGE'), 'db' => env('DOCKER_APP_MATTERMOST_DB_IMAGE')],
        'chatwoot' => ['app' => env('DOCKER_APP_CHATWOOT_IMAGE'), 'db' => env('DOCKER_APP_CHATWOOT_DB_IMAGE'), 'redis' => env('DOCKER_APP_CHATWOOT_REDIS_IMAGE')],
        'excalidraw' => ['app' => env('DOCKER_APP_EXCALIDRAW_IMAGE')],
        'metabase' => ['app' => env('DOCKER_APP_METABASE_IMAGE'), 'db' => env('DOCKER_APP_METABASE_DB_IMAGE')],
        'nocodb' => ['app' => env('DOCKER_APP_NOCODB_IMAGE'), 'db' => env('DOCKER_APP_NOCODB_DB_IMAGE')],
        'wikijs' => ['app' => env('DOCKER_APP_WIKIJS_IMAGE'), 'db' => env('DOCKER_APP_WIKIJS_DB_IMAGE')],
        'forgejo' => ['app' => env('DOCKER_APP_FORGEJO_IMAGE')],
        'freshrss' => ['app' => env('DOCKER_APP_FRESHRSS_IMAGE')],
        'gitea' => ['app' => env('DOCKER_APP_GITEA_IMAGE')],
        'glance' => ['app' => env('DOCKER_APP_GLANCE_IMAGE')],
        'homepage' => ['app' => env('DOCKER_APP_HOMEPAGE_IMAGE')],
        'ittools' => ['app' => env('DOCKER_APP_ITTOOLS_IMAGE')],
        'stirlingpdf' => ['app' => env('DOCKER_APP_STIRLINGPDF_IMAGE')],
        'vaultwarden' => ['app' => env('DOCKER_APP_VAULTWARDEN_IMAGE')],
        'ghost' => ['app' => env('DOCKER_APP_GHOST_IMAGE'), 'db' => env('DOCKER_APP_GHOST_DB_IMAGE')],
    ],
    'locales' => ['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'],
];
