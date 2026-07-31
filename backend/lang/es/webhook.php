<?php

return [

    'instructions' => [
        'github' => 'En su repositorio, abra Settings → Webhooks → Add webhook. Pegue la URL de abajo, ponga Content type en application/json, pegue el secreto en Secret y seleccione «Just the push event».',
        'gitlab' => 'En su proyecto, abra Settings → Webhooks → Add new webhook. Pegue la URL de abajo y seleccione el disparador «Push events». Después, o bien pulse «Generate signing token» en GitLab y pegue ese token aquí (recomendado), o bien pegue el secreto de este panel en el campo «Secret token» de GitLab.',
        'bitbucket' => 'En su repositorio, abra Repository settings → Webhooks → Add webhook. Pegue la URL de abajo, pegue el secreto en Secret y seleccione el disparador «Repository push».',
    ],

];
