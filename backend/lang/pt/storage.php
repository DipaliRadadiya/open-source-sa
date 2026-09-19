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
        'google_drive' => 'Google Drive',
        'google_drive_oauth' => 'Google Drive (sua própria conta)',
        'webdav' => 'WebDAV',
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
        'service_account_json' => 'Chave da conta de serviço (JSON)',
        'folder_id' => 'ID da pasta do Drive compartilhado',
        'drive_name' => 'Drive compartilhado',
        'client_email' => 'Endereço da conta de serviço',
        'base_uri' => 'URL do servidor',
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
        'endpoint' => 'Deixe o padrão para a AWS. Defina para Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces ou qualquer serviço compatível com S3.',
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
        'service_account_json' => 'Cole o arquivo JSON completo da conta de serviço.',
        'folder_id' => 'A parte da URL da pasta depois de /folders/ — não o link inteiro.',
        'drive_shared_only' => 'Só funciona um Drive compartilhado do Google Workspace. Uma conta de serviço não tem armazenamento próprio, então envios para um Drive pessoal são recusados mesmo com a conta vazia.',
        'drive_share_with' => 'Compartilhe a pasta do Drive compartilhado com o endereço da conta de serviço antes de testar.',
        'base_uri' => 'A URL WebDAV completa, incluindo a pasta — por exemplo https://cloud.exemplo.com/remote.php/dav/files/voce/',
        'pcloud_warning' => 'A pCloud informa que seu WebDAV é destinado a arquivos pequenos e pode ser interrompido, e ele para de funcionar quando a verificação em duas etapas está ativa. Ambos importam para backups: teste o destino e mantenha um segundo em outro lugar.',
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
        'drive_personal' => 'Essa pasta está em um Drive pessoal. Uma conta de serviço não tem armazenamento lá, então os backups seriam recusados — use uma pasta de um Drive compartilhado.',
        'drive_not_shared' => 'A pasta existe, mas esta conta de serviço não recebeu acesso a ela.',
        'drive_folder_missing' => 'Nenhuma pasta encontrada com esse ID.',
        'drive_not_a_folder' => 'Esse ID aponta para um arquivo, não uma pasta.',
        'drive_bad_key' => 'Não foi possível ler a chave da conta de serviço. Cole o arquivo JSON inteiro.',
        'drive_quota' => 'O Google recusou o envio por falta de cota de armazenamento, que é o que acontece em um Drive pessoal.',
        'drive_incomplete' => 'Adicione a chave da conta de serviço e o ID da pasta antes de testar.',
        'dav_full' => 'O servidor recusou o envio por falta de espaço.',
        'dav_reset' => 'O servidor fechou a conexão sem responder. Se for pCloud, a verificação em duas etapas causa isso.',
    ],

    'delete' => [
        'in_use' => 'Não é possível excluir :name — ainda é usado por :applications. Remova ou redirecione esses destinos de backup primeiro.',
        'and_more' => 'mais :count',
    ],

    'validation' => [
        'sftp_auth_required' => 'Informe uma senha ou uma chave privada.',
    ],

    'oauth' => [
        'not_connected' => 'Ainda não conectado. Clique em Conectar para autorizar o acesso à sua conta Google.',
        'revoked' => 'O Google revogou este acesso. Normalmente o aplicativo OAuth ficou em "Testing" — o Google expira esses tokens em cerca de uma semana — ou o acesso foi removido em myaccount.google.com. Conecte novamente.',
        'user_quota' => 'Seu Google Drive está cheio. Libere espaço ou faça backup em outro destino.',
        'folder_missing' => 'A pasta de backups sumiu do seu Drive. Conecte novamente e o painel criará outra.',
        'denied' => 'O acesso foi recusado na tela do Google. Nada foi alterado.',
        'code_expired' => 'Essa aprovação já foi usada, ou expirou. Clique em Conectar para começar de novo.',
        'bad_client' => 'O Google não reconhece esse ID de cliente. Verifique se foi copiado inteiro, incluindo o final .apps.googleusercontent.com.',
        'wrong_client_type' => 'Esse cliente é do tipo errado. No Google Cloud Console crie um cliente OAuth do tipo "Web application" e cole o ID e o segredo dele.',
        'redirect_mismatch' => 'O Google recusou o endereço de redirecionamento. Copie o endereço mostrado abaixo do botão Conectar para o seu cliente OAuth, em "Authorized redirect URIs", exatamente como aparece.',
        'panel_url_missing' => 'Este painel não conhece o próprio endereço web, então não consegue dizer ao Google para onde devolvê-lo. Defina FRONTEND_URL na configuração do painel.',
        'state_invalid' => 'Esse login não veio deste painel. Clique em Conectar e aprove de novo.',
        'state_expired' => 'Esse link de login já foi usado, ou ficou tempo demais. Clique em Conectar para começar de novo.',
        'destination_missing' => 'Este destino foi removido enquanto você aprovava. Nada foi salvo.',
        'start_failed' => 'Não foi possível iniciar o login do Google. Tente de novo em instantes.',
        'poll_failed' => 'Não foi possível concluir o login do Google. Tente de novo em instantes.',
        'token_failed' => 'Não foi possível concluir o login do Google. Tente de novo em instantes.',
        'no_refresh_token' => 'O Google autorizou o acesso mas não enviou um token duradouro, o que acontece quando esta conta já havia autorizado o aplicativo. Remova-o em myaccount.google.com, em Acesso de terceiros, e conecte novamente.',
        'folder_failed' => 'Conectado, mas não foi possível criar a pasta de backups no seu Drive. Verifique o espaço e conecte novamente.',
        'wrong_provider' => 'Este destino não usa login do Google.',
    ],
];
