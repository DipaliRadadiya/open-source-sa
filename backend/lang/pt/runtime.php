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
    ],

    'extension_install_failed' => [
        'package_not_found' => 'Nenhum pacote para :extension no PHP :version. Pode não existir para esta versão.',
        'apt_lock' => 'Outra operação de pacotes já está em execução. Tente novamente em instantes.',
        'network' => 'Não foi possível alcançar o repositório de pacotes. Verifique o acesso à rede do servidor.',
        'no_space' => 'O servidor ficou sem espaço em disco.',
        'worker' => 'A instalação de :extension parou inesperadamente. Pode ter excedido o tempo — tente novamente.',
        'unknown' => 'A instalação de :extension falhou. Informe a referência abaixo ao suporte.',
    ],

];
