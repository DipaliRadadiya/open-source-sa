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
];
