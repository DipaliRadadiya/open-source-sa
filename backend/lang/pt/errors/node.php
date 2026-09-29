<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'O Node :version não está instalado.',
    'version_in_use' => 'O Node :version é usado por :apps. Altere primeiro esses sites.',
    'version_is_default' => 'Esta é a versão predefinida. Escolha outra primeiro.',
    'npm_target_unknown' => 'Não foi possível aceder à lista de versões do npm, por isso não há como saber que npm esta versão do Node consegue executar. Tente novamente quando o servidor tiver acesso à internet ou execute `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Este servidor aloja contentores e não executa aplicações no próprio anfitrião, por isso não há versões do Node.js para gerir. Um contentor traz o seu próprio ambiente de execução.',
];
