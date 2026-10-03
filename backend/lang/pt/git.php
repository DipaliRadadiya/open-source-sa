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
        'github' => 'Um token de acesso pessoal com o escopo "repo" (também cobre o webhook do deploy ao fazer push).',
        'gitlab' => 'Um token de acesso pessoal com os escopos "read_repository" e "read_api". Para deploy ao fazer push, use "api" em vez de "read_api": o GitLab só permite que um token com "api" adicione e remova webhooks.',
        'bitbucket' => 'Um token de acesso com escopo (workspace, projeto ou repositório). Um token limitado a um repositório listará apenas esse repositório. Para deploy ao fazer push também precisa de permissão de leitura, escrita e eliminação de webhooks; caso contrário, desativá-lo não consegue remover o webhook.',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'Essa conta não consegue aceder a este repositório. Verifique se o token continua válido e tem acesso.',
        'branch_missing' => 'O ramo :branch não existe neste repositório.',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'Apenas para GitLab auto-hospedado — o URL base da sua instância, por exemplo https://git.example.com. Deixe vazio para gitlab.com.'],
        'bitbucket' => ['workspace' => 'O ID do espaço de trabalho no seu URL do Bitbucket: bitbucket.org/<workspace>/<repositório>. Não é o seu nome de exibição.'],
    ],
];
