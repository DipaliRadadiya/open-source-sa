<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'Compatível com S3',
    ],

    'fields' => [
        'name' => 'Nome de exibição',
        'endpoint' => 'URL do endpoint',
        'region' => 'Região',
        'bucket' => 'Bucket',
        'prefix' => 'Prefixo de chave (opcional)',
        'access_key' => 'Chave de acesso',
        'secret_key' => 'Chave secreta',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => 'Um rótulo curto para distinguir os destinos na lista de integrações.',
        'endpoint' => 'Deixe o padrão para a AWS. Defina para MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Região onde o bucket está (necessária apenas para a AWS).',
        'prefix' => 'Prefixo de caminho opcional dentro do bucket (sem barra inicial).',
        'access_key' => 'Somente escrita — nunca devolvida pela API.',
    ],

    'status' => [
        'connected' => 'Conectado',
        'never_tested' => 'Ainda não testado',
    ],

    'test' => [
        'success' => 'Conexão estabelecida com sucesso.',
        'failure' => 'Não foi possível conectar ao destino.',
        'invalid_credentials' => 'O destino rejeitou as credenciais.',
        'unreachable' => 'Não foi possível alcançar o endpoint do destino.',
        'mismatch' => 'O destino gravou e leu de volta bytes diferentes.',
        'forbidden_host' => 'Esse endereço de endpoint não é permitido.',
        'invalid_endpoint' => 'Informe uma URL de endpoint https:// válida para o bucket.',
    ],

    'delete' => [
        'in_use' => 'Não é possível excluir :name — um ou mais destinos de backup ainda apontam para este destino. Remova-os ou redirecione-os primeiro.',
    ],
];
