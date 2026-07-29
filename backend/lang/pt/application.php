<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Criador de blogs e sites'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Faça a gestão das suas bases de dados no navegador'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Sincronização e partilha de ficheiros privados'],
        'git' => ['title' => 'De um repositório Git', 'tagline' => 'Implante seu próprio código do GitHub, GitLab ou Bitbucket'],
        'php' => ['title' => 'Site PHP vazio', 'tagline' => 'Um site vazio — envie seus próprios arquivos'],
        'static' => ['title' => 'Site estático', 'tagline' => 'HTML, CSS e JavaScript simples'],
    ],

    'status' => [
        'pending' => 'Ainda não implantado',
        'provisioning' => 'Configurando…',
        'active' => 'Em execução',
        'failed' => 'Falha na configuração',
    ],

    'unavailable' => [
        'php' => 'Este servidor não tem PHP instalado.',
        'node' => 'Este servidor não tem Node.js instalado.',
    ],

    'git_source' => [
        'account' => 'De uma conta conectada',
        'public_url' => 'Colar a URL de um repositório público',
    ],

    'fields' => [
        'name' => 'Nome',
        'domain' => 'Domínio',
        'system_user_id' => 'Usuário do sistema',
        'php_version' => 'Versão do PHP',
        'node_version' => 'Versão do Node.js',
        'app_port' => 'Porta do aplicativo',
        'web_root' => 'Raiz web',
        'build_command' => 'Comando de build',
        'start_command' => 'Comando de início',
        'git_source' => 'Origem',
        'git_account_id' => 'Conta do Git',
        'repository' => 'Repositório',
        'repository_url' => 'URL do repositório',
        'branch' => 'Branch',
        'site_title' => 'Título do site',
        'admin_user' => 'Usuário administrador',
        'admin_email' => 'E-mail do administrador',
        'admin_password' => 'Senha do administrador',
        'site_language' => 'Idioma do site',
        'table_prefix' => 'Prefixo das tabelas',
    ],

    'help' => [
        'repository_url' => 'Um repositório público — sem necessidade de conta. Deve ser um endereço https://.',
        'build_command' => 'Executado após baixar o código, ex.: composer install --no-dev',
    ],

    'steps' => [
        'create_database' => 'Criando o banco de dados',
        'download' => 'Baixando a aplicação',
        'extract' => 'Descompactando os arquivos',
        'configure' => 'Gravando a configuração',
        'install_cli' => 'Instalando a ferramenta de instalação',
        'install_app' => 'Executando o instalador',
        'clone' => 'Clonando o repositório',
        'fetch' => 'Baixando o código mais recente',
        'checkout' => 'Mudando para o branch',
        'build' => 'Executando o comando de build',
        'write_credential' => 'Preparando o acesso ao git',
        'create_directory' => 'Criando o diretório',
        'set_ownership' => 'Definindo o proprietário',
        'placeholder' => 'Adicionando uma página provisória',
        'write_config' => 'Gravando a configuração do site',
        'test_config' => 'Testando a configuração',
        'reload' => 'Recarregando o servidor web',
        'worker' => 'O processo em segundo plano parou',
    ],
];
