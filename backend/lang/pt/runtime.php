<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'Nenhum pacote para :version. Verifique se o repositório do PHP está configurado e acessível.',
        'apt_lock' => 'Outra operação de pacotes já está em execução. Tente novamente em instantes.',
        'network' => 'Não foi possível alcançar o repositório de pacotes. Verifique o acesso à rede do servidor.',
        'no_space' => 'O servidor ficou sem espaço em disco.',
        'worker' => 'A instalação parou inesperadamente. Pode ter excedido o tempo — tente novamente.',
        'unknown' => 'A instalação falhou. Informe a referência abaixo ao suporte.',
        'dpkg_broken' => 'A base de pacotes deste servidor precisa ser reparada antes que algo mais possa ser instalado.',
        'port_in_use_by_mysql' => 'O MySQL já está instalado e ocupa esta porta. Remova-o primeiro ou continue a usá-lo.',
        'port_in_use_by_mariadb' => 'O MariaDB já está instalado e ocupa esta porta. Remova-o primeiro ou continue a usá-lo.',
        'root_unreachable' => 'Está instalado, mas o painel não conseguiu entrar. O acesso de administrador foi alterado em relação ao padrão, então o painel precisa desses dados para continuar.',
        'grant_failed' => 'Está instalado, mas o painel não conseguiu criar a própria conta nele.',
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

];
