<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'O PHP :version não está instalado.',
    'version_in_use' => 'O PHP :version é usado por :apps. Altere primeiro esses sites.',
    'version_is_default' => 'Esta é a versão predefinida. Escolha outra primeiro.',
    'version_runs_panel' => 'Remover o PHP :version deixaria o painel offline — é a versão em que o próprio painel corre.',
    'extension_builtin' => 'A extensão :extension está compilada no PHP. Não pode ser desativada.',
    'extension_runs_panel' => 'Desativar :extension deixaria o painel offline — precisa de :modules.',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'Isto não é suportado na pilha PHP :stack.',

    'ioncube_unsupported_version' => 'A ionCube não publica um Loader para o PHP :version.',
    'ioncube_unsupported_architecture' => 'A ionCube não publica um Loader para a arquitetura deste servidor (:architecture).',
    'ioncube_download_failed' => 'Não foi possível transferir o Loader da ionCube. Verifique o acesso à Internet do servidor e tente novamente.',
    'ioncube_invalid_loader' => 'O ficheiro transferido não é um Loader da ionCube válido para este servidor. Nada foi instalado.',
    'ioncube_install_failed' => 'Não foi possível instalar o Loader da ionCube.',
    'ioncube_config_test_failed' => 'O PHP recusou-se a arrancar com o Loader da ionCube, por isso foi removido. Os seus sites não foram afetados.',
];
