<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'status' => [
        'valid' => 'Conectado',
        'invalid' => 'Token inválido',
        'unknown' => 'Não foi possível verificar',
    ],

    'fields' => [
        'token' => 'Token de acesso',
        'host' => 'URL auto-hospedada',
        'workspace' => 'Workspace',
    ],

    'token_help' => [
        'github' => 'Um token de acesso pessoal com o escopo "repo".',
        'gitlab' => 'Um token de acesso pessoal com os escopos "read_repository" e "read_api". Deixe a URL vazia para gitlab.com.',
        'bitbucket' => 'Um token de acesso com escopo (workspace, projeto ou repositório). Um token limitado a um repositório listará apenas esse repositório.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'Essa conta não consegue aceder a este repositório. Verifique se o token continua válido e tem acesso.',
        'branch_missing' => 'O ramo :branch não existe neste repositório.',
    ],
];
