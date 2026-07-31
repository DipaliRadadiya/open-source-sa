<?php

return [
    'operation_failed' => 'A operação de banco de dados falhou no servidor.',
    'collation_mismatch' => 'O agrupamento selecionado não pertence ao conjunto de caracteres escolhido.',
    'engine_not_installable' => 'O painel ainda não consegue instalar este motor de banco de dados. Instale-o você mesmo e o painel o detectará.',

    'engine_install' => [
        'package_not_found' => 'O pacote deste motor não está disponível nas fontes de pacotes deste servidor.',
        'apt_lock' => 'Outra operação de pacotes já está em execução. Aguarde o término e tente novamente.',
        'no_space' => 'Não há espaço livre em disco suficiente para instalar este motor.',
        'network' => 'O servidor não conseguiu acessar suas fontes de pacotes. Verifique a rede e o DNS.',
        'dpkg_broken' => 'A base de pacotes deste servidor precisa ser reparada antes que algo mais possa ser instalado.',
        'port_in_use_by_mysql' => 'O MySQL já está instalado e ocupa esta porta. Remova-o primeiro ou continue a usá-lo.',
        'port_in_use_by_mariadb' => 'O MariaDB já está instalado e ocupa esta porta. Remova-o primeiro ou continue a usá-lo.',
        'root_unreachable' => 'O motor está instalado, mas o painel não conseguiu entrar. O acesso de administrador foi alterado em relação ao padrão, então o painel precisa desses dados para continuar.',
        'grant_failed' => 'O motor está instalado, mas o painel não conseguiu criar a própria conta nele.',
        'unknown' => 'A instalação falhou. Informe a referência ao suporte.',
    ],
];
