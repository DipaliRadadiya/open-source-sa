<?php

return [
    'status' => [
        'pending' => 'Aguardando início',
        'running' => 'Atualizando',
        'completed' => 'Atualizada',
        'failed' => 'Falhou',
    ],
    'reasons' => [
        'unsupported' => 'Este tipo de aplicação não pode ser atualizado pelo painel.',
        'version_unchanged' => 'A atualização foi executada, mas a aplicação ainda informa a versão antiga.',
        'step_failed' => 'Uma etapa da atualização falhou. A referência aponta para o log do servidor.',
        'failed' => 'A atualização falhou inesperadamente. A referência aponta para o log do servidor.',
        'worker' => 'A atualização parou antes de terminar (o processo em segundo plano terminou ou excedeu o tempo).',
        'abandoned' => 'A atualização nunca começou e foi cancelada.',
        'release_not_found' => 'A nova versão não foi encontrada na página de downloads do projeto.',
        'download_unreachable' => 'Não foi possível baixar a versão. Verifique o acesso à internet do servidor e tente novamente.',
        'out_of_memory' => 'O servidor ficou sem memória durante a atualização.',
        'composer_platform' => 'A versão do PHP ou as extensões do site não atendem aos requisitos da nova versão.',
        'no_build_tools' => 'Faltam ferramentas de compilação no servidor. Instale-as e tente novamente.',
        'node_unsupported' => 'A nova versão precisa de uma versão do Node.js que este site não usa. Altere a versão do Node.js do site e atualize novamente.',
        'app_not_ready' => 'A nova versão não iniciou, por isso a versão anterior foi reposta.',
    ],
    'errors' => [
        'running' => 'Já existe uma atualização desta aplicação em andamento.',
        'unsupported' => 'Este tipo de aplicação não pode ser atualizado pelo painel.',
        'not_active' => 'Somente uma aplicação ativa e habilitada pode ser atualizada.',
        'version_unknown' => 'Não foi possível ler a versão instalada nos arquivos da aplicação.',
        'lookup_failed' => 'Não foi possível verificar as versões disponíveis agora. Tente novamente mais tarde.',
        'up_to_date' => 'A versão :version já é a mais recente desta versão principal.',
    ],
];
