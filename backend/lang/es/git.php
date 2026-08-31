<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'status' => [
        'valid' => 'Conectado',
        'invalid' => 'Token no válido',
        'unknown' => 'No se pudo comprobar',
    ],

    'fields' => [
        'token' => 'Token de acceso',
        'host' => 'URL autoalojada',
        'workspace' => 'Espacio de trabajo',
    ],

    'token_help' => [
        'github' => 'Un token de acceso personal con el ámbito «repo».',
        'gitlab' => 'Un token de acceso personal con los ámbitos «read_repository» y «read_api».',
        'bitbucket' => 'Un token de acceso con ámbito (espacio de trabajo, proyecto o repositorio). Un token limitado a un repositorio solo mostrará ese repositorio.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'Esa cuenta no puede acceder a este repositorio. Compruebe que el token sigue siendo válido y tiene acceso.',
        'branch_missing' => 'La rama :branch no existe en este repositorio.',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'Solo para GitLab autoalojado: la URL base de su instancia, por ejemplo https://git.example.com. Déjelo vacío para gitlab.com.'],
        'bitbucket' => ['workspace' => 'El ID del espacio de trabajo de su URL de Bitbucket: bitbucket.org/<workspace>/<repositorio>. No es su nombre visible.'],
    ],
];
