<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'O Node :version não está instalado.',
    'version_in_use' => 'O Node :version é usado por :apps. Mude primeiro essas aplicações para outra versão do Node (definição Node.js de cada aplicação) ou elimine-as.',
    'version_unknown' => 'O Node.js :version não existe. Escolha uma versão da lista.',
    'version_is_default' => 'Esta é a versão predefinida. Escolha outra primeiro.',
    'version_runs_panel' => 'O próprio painel é executado no Node :version. Esta versão não pode ser removida.',
    'npm_target_unknown' => 'Não foi possível aceder à lista de versões do npm, por isso não há como saber que npm esta versão do Node consegue executar. Tente novamente quando o servidor tiver acesso à internet ou execute `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Este servidor aloja contentores e não executa aplicações no próprio anfitrião, por isso não há versões do Node.js para gerir. Um contentor traz o seu próprio ambiente de execução.',
    'change_not_node' => 'Só uma aplicação que corre em Node.js tem uma versão do Node para alterar.',
    'change_legacy_pm2' => 'Esta aplicação ainda corre com o PM2 do painel anterior. Passe-a primeiro para o gestor de processos do painel e depois altere a versão do Node.',
    'change_in_progress' => 'Esta aplicação já está a ser mudada para o Node :version. Aguarde que termine.',
    'change_use_endpoint' => 'A versão do Node altera-se com a sua própria ação (PUT /applications/{application}/node-version), que reinicia a aplicação e a verifica.',
    'change_failed' => [
        'did_not_start' => 'A aplicação não arrancou com o Node :target, por isso voltou ao Node :current e funciona como antes. Veja o registo da aplicação para saber o motivo.',
        'rollback_failed' => 'A aplicação não arrancou com o Node :target e voltar ao Node :current também não resultou. Veja o registo da aplicação e reinicie-a.',
        'unit_write' => 'Não foi possível atualizar o serviço da aplicação para o Node :target. Nada mudou; continua no Node :current.',
        'install_pm2' => 'Não foi possível instalar o PM2 no Node :target, de que esta aplicação precisa para vários processos. Nada mudou; continua no Node :current.',
        'worker' => 'A mudança para o Node :target parou antes de terminar. A aplicação está registada como Node :current; confirme que funciona e tente de novo.',
    ],
    'remove_failed' => 'Não foi possível remover o Node :version. Indique a referência abaixo ao suporte.',
    'remove_failed_said' => 'Não foi possível remover o Node :version. O fnm indicou: «:output»',
    'install_in_progress' => 'O Node :version já está a ser instalado. Aguarde que essa instalação termine.',
];
