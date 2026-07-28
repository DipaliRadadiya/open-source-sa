<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'fields' => [
        'token' => 'Token de acceso',
        'host' => 'URL autoalojada',
        'workspace' => 'Espacio de trabajo',
    ],

    'token_help' => [
        'github' => 'Un token de acceso personal con el ámbito «repo».',
        'gitlab' => 'Un token de acceso personal con los ámbitos «read_repository» y «read_api». Deja la URL vacía para gitlab.com.',
        'bitbucket' => 'Un token de acceso con ámbito (espacio de trabajo, proyecto o repositorio). Un token limitado a un repositorio solo mostrará ese repositorio.',
    ],
];
