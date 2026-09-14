<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'Compatível com S3',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
    ],

    'fields' => [
        'name' => 'Nome de exibição',
        'endpoint' => 'URL do endpoint',
        'region' => 'Região',
        'bucket' => 'Bucket',
        'prefix' => 'Prefixo de chave (opcional)',
        'access_key' => 'Chave de acesso',
        'secret_key' => 'Chave secreta',
        'host' => 'Host',
        'port' => 'Porta',
        'username' => 'Usuário',
        'password' => 'Senha',
        'root' => 'Diretório remoto',
        'ssl' => 'Usar TLS (FTPS)',
        'passive' => 'Modo passivo',
        'private_key' => 'Chave privada',
        'passphrase' => 'Frase da chave',
        'host_fingerprint' => 'Impressão digital da chave do host',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
        'host' => 'backup.exemplo.com',
        'root' => 'backups/',
    ],

    'help' => [
        'name' => 'Um rótulo curto para distinguir os destinos na lista de integrações.',
        'endpoint' => 'Deixe o padrão para a AWS. Defina para MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Região onde o bucket está (necessária apenas para a AWS).',
        'prefix' => 'Prefixo de caminho opcional dentro do bucket (sem barra inicial).',
        'access_key' => 'Somente escrita — nunca devolvida pela API.',
        'host' => 'Nome do host ou endereço IP do servidor que guardará os backups.',
        'port' => 'Deixe vazio para usar o padrão.',
        'root' => 'Diretório no servidor onde gravar. Deixe vazio para usar onde o login chegar.',
        'ssl' => 'Fortemente recomendado. Sem ele, a senha e todo o backup trafegam em texto claro.',
        'passive' => 'Mantenha ligado, a menos que o servidor exija o contrário.',
        'private_key' => 'Cole a chave privada inteira. Usada no lugar de uma senha.',
        'passphrase' => 'Só é necessária se a própria chave privada estiver criptografada.',
        'host_fingerprint' => 'Registrada na primeira conexão do painel e exigida depois. Compare com a chave no servidor para ter certeza.',
        'plain_ftp_warning' => 'O TLS está desligado. A senha e todos os backups serão enviados sem criptografia.',
    ],

    'status' => [
        'connected' => 'Conectado',
        'never_tested' => 'Ainda não testado',
        'failed' => 'Último teste falhou',
    ],

    'test' => [
        'success' => 'Conexão estabelecida com sucesso.',
        'failure' => 'Não foi possível conectar ao destino.',
        'invalid_credentials' => 'O destino rejeitou as credenciais.',
        'unreachable' => 'Não foi possível alcançar o endpoint do destino.',
        'mismatch' => 'O destino gravou e leu de volta bytes diferentes.',
        'forbidden_host' => 'Esse endereço de endpoint não é permitido.',
        'invalid_endpoint' => 'Informe uma URL de endpoint https:// válida para o bucket.',
        'invalid_host' => 'Informe um nome de host ou endereço IP válido.',
        'host_key_mismatch' => 'O servidor apresentou uma chave de host diferente da registrada. A conexão foi interrompida.',
        'invalid_private_key' => 'Não foi possível ler a chave privada. Verifique se ela foi colada por inteiro.',
        'root_missing' => 'A pasta de destino não existe no servidor. Crie-a ou corrija o caminho da pasta.',
    ],

    'delete' => [
        'in_use' => 'Não é possível excluir :name — ainda é usado por :applications. Remova ou redirecione esses destinos de backup primeiro.',
        'and_more' => 'mais :count',
    ],

    'validation' => [
        'sftp_auth_required' => 'Informe uma senha ou uma chave privada.',
    ],
];
