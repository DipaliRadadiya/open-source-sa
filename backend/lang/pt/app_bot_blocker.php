<?php

return [

    'policies' => [
        'allow_all' => [
            'title' => 'Permitir todos os bots de IA',
            'description' => 'Nenhum rastreador de IA é bloqueado.',
        ],
        'block_training' => [
            'title' => 'Bloquear bots de treino de IA',
            'description' => 'Impede bots que recolhem o seu conteúdo para treinar modelos de IA. Motores de pesquisa de IA que lhe enviam visitantes, como a pesquisa do ChatGPT e o Perplexity, continuam a funcionar.',
        ],
        'block_agents' => [
            'title' => 'Bloquear treino de IA e assistentes de IA',
            'description' => 'Impede os rastreadores de treino e os assistentes de IA que pedem as suas páginas uma a uma. Os motores de pesquisa de IA continuam a indexá-lo, por isso mantém as suas citações nas respostas de IA.',
        ],
        'block_all' => [
            'title' => 'Bloquear todos os bots de IA',
            'description' => 'Bloqueia todos os bots de IA conhecidos, incluindo os que lhe enviam tráfego a partir de resultados de pesquisa de IA.',
        ],
    ],

];
