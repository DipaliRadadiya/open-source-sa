<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'Não há pacote para :version nas fontes de pacotes deste servidor.',
        'apt_lock' => 'Outra operação de pacotes já está em execução. Tente novamente em instantes.',
        'network' => 'Não foi possível alcançar o repositório de pacotes. Verifique o acesso à rede do servidor.',
        'no_space' => 'O servidor ficou sem espaço em disco.',
        'worker' => 'A instalação parou inesperadamente. Pode ter excedido o tempo — tente novamente.',
        'unknown' => 'A instalação falhou. Informe a referência abaixo ao suporte.',
        'dpkg_broken' => 'A base de pacotes deste servidor precisa ser reparada antes que algo mais possa ser instalado.',
        'port_in_use_by_mysql' => 'O MySQL já está instalado e ocupa esta porta. Remova-o primeiro ou continue a usá-lo.',
        'port_in_use_by_mariadb' => 'O MariaDB já está instalado e ocupa esta porta. Remova-o primeiro ou continue a usá-lo.',
        'root_unreachable' => 'Está instalado, mas o painel não conseguiu entrar. O acesso de administrador foi alterado em relação ao padrão, então o painel precisa desses dados para continuar.',
        'cluster_missing' => 'O PostgreSQL está instalado, mas não existe nenhum cluster neste servidor. Ele pode ter sido removido ou sua configuração não foi concluída.',
        'grant_failed' => 'Está instalado, mas o painel não conseguiu criar a própria conta nele.',
        'repository_failed' => 'Não foi possível adicionar o repositório de pacotes do MongoDB. Verifique se o servidor alcança repo.mongodb.org.',
        'unreachable' => 'Foi instalado mas não chegou a responder. Indique a referência abaixo ao suporte.',
        'auth_required' => 'O MongoDB já está instalado aqui e exige um início de sessão que o painel não tem. Adicione as credenciais nas definições de ligação e tente novamente.',
        'auth_config_present' => 'O MongoDB está instalado e a sua configuração já define uma secção security. O painel deixou-a intacta — ative aí o authorization e tente novamente.',
        'auth_failed' => 'Foi instalado mas não foi possível ativar a autenticação. Indique a referência abaixo ao suporte.',
    ],

    'uninstall_failed' => [
        'failed' => 'Não foi possível remover o PHP :version. Informe a referência abaixo ao suporte.',
        'worker' => 'A remoção do PHP :version parou inesperadamente. Pode ter excedido o tempo — tente novamente.',
        'unknown' => 'Não foi possível remover o PHP :version. Informe a referência abaixo ao suporte.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'Nenhum pacote para :extension no PHP :version. Pode não existir para esta versão.',
        'apt_lock' => 'Outra operação de pacotes já está em execução. Tente novamente em instantes.',
        'network' => 'Não foi possível alcançar o repositório de pacotes. Verifique o acesso à rede do servidor.',
        'no_space' => 'O servidor ficou sem espaço em disco.',
        'worker' => 'A instalação de :extension parou inesperadamente. Pode ter excedido o tempo — tente novamente.',
        'unknown' => 'A instalação de :extension falhou. Informe a referência abaixo ao suporte.',
        'enable_failed' => 'A :extension foi instalada mas não pôde ser ativada. Tente o botão novamente.',
    ],

    'fail2ban_install_failed' => [
        'package_not_found' => 'Não há nenhum pacote do fail2ban disponível. Verifique se as fontes de pacotes do servidor estão configuradas e acessíveis.',
        'apt_lock' => 'Já está a decorrer outra operação de pacotes. Tente novamente daqui a pouco.',
        'network' => 'Não foi possível aceder ao repositório de pacotes. Verifique o acesso à rede do servidor.',
        'no_space' => 'O servidor ficou sem espaço em disco.',
        'worker' => 'A instalação parou inesperadamente. Pode ter excedido o tempo — tente novamente.',
        'unknown' => 'A instalação do fail2ban falhou. Indique a referência abaixo ao suporte.',
    ],


    /*
    | Per-runtime overrides, consulted before the shared groups above.
    |
    | `install_failed` is shared by PHP, Node, database engines and
    | fail2ban. It used to be worded for PHP alone, so a failed MongoDB
    | install told the user to check the PHP repository. Only the reasons
    | that genuinely differ per runtime belong here; everything else
    | still falls through.
    */

    'php_install_failed' => [
        'package_not_found' => 'Nenhum pacote para :version. Verifique se o repositório do PHP está configurado e acessível.',
    ],

    'node_install_failed' => [
        'package_not_found' => 'Node :version não foi encontrado. Verifique o número da versão ou escolha uma da lista.',
    ],

    'database_install_failed' => [
        'package_not_found' => 'Não há pacote para :version neste servidor. Verifique se o repositório de pacotes dele está configurado e acessível.',
        // The repository was added and its index fetched successfully;
        // the engine simply has no build for this Ubuntu release.
        'os_unsupported' => 'O :version ainda não publica pacotes para :os. Não há nada de errado com este servidor — o painel é compatível com :os, mas o :version ainda não lançou uma versão para ele. Use outro mecanismo de banco de dados ou tente de novo quando lançar.',
    ],

    // Used for :os when /etc/os-release cannot be read.
    'this_server' => 'o sistema operacional deste servidor',
];
