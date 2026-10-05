<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'O Node :version não está instalado.',
    'version_in_use' => 'O Node :version é usado por :apps. Mude primeiro esses sites para outra versão do Node (definição Node.js de cada site) ou elimine-os.',
    'version_unknown' => 'O Node.js :version não existe. Escolha uma versão da lista.',
    'version_is_default' => 'Esta é a versão predefinida. Escolha outra primeiro.',
    'version_runs_panel' => 'O próprio painel é executado no Node :version. Esta versão não pode ser removida.',
    'npm_target_unknown' => 'Não foi possível aceder à lista de versões do npm, por isso não há como saber que npm esta versão do Node consegue executar. Tente novamente quando o servidor tiver acesso à internet ou execute `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Este servidor aloja contentores e não executa aplicações no próprio anfitrião, por isso não há versões do Node.js para gerir. Um contentor traz o seu próprio ambiente de execução.',
    'change_not_node' => 'Só um site que corre em Node.js tem uma versão do Node para alterar.',
    'change_legacy_pm2' => 'Este site ainda corre com o PM2 do painel anterior. Passe-o primeiro para o gestor de processos do painel e depois altere a versão do Node.',
    'change_in_progress' => 'Este site já está a ser mudado para o Node :version. Aguarde que termine.',
    'change_use_endpoint' => 'A versão do Node altera-se com a sua própria ação (PUT /applications/{application}/node-version), que reinicia o site e o verifica.',
    'change_failed' => [
        'did_not_start' => 'O site não arrancou com o Node :target, por isso voltou ao Node :current e funciona como antes. Veja o registo do site para saber o motivo.',
        'rollback_failed' => 'O site não arrancou com o Node :target e voltar ao Node :current também não resultou. Veja o registo do site e reinicie-o.',
        'unit_write' => 'Não foi possível atualizar o serviço do site para o Node :target. Nada mudou; continua no Node :current.',
        'install_pm2' => 'Não foi possível instalar o PM2 no Node :target, de que este site precisa para vários processos. Nada mudou; continua no Node :current.',
        'worker' => 'A mudança para o Node :target parou antes de terminar. O site está registado como Node :current; confirme que funciona e tente de novo.',
    ],
];
