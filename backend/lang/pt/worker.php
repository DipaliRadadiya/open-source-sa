<?php

return [
    'kinds' => [
        'queue' => 'Worker de fila',
        'horizon' => 'Horizon',
        'custom' => 'Personalizado',
    ],

    'states' => [
        'running' => 'Em execução',
        'degraded' => 'Parcialmente em execução',
        'stopped' => 'Parado',
    ],

    'presets' => [
        'queue' => [
            'title' => 'Worker de fila',
            'description' => 'Processa os trabalhos em fila. A escolha habitual.',
        ],
        'horizon' => [
            'title' => 'Horizon',
            'description' => 'Supervisiona os seus próprios workers, com painel. Use em vez de um worker de fila, não em conjunto.',
        ],
        'custom' => [
            'title' => 'Comando personalizado',
            'description' => 'Qualquer comando de longa duração, mantido a correr.',
        ],
    ],

    'checks' => [
        'cache_driver_array' => [
            'title' => 'Os workers não podem ser reiniciados automaticamente',
            'detail' => 'Esta aplicação usa o driver de cache "array", que não persiste entre processos. O Laravel reinicia workers deixando uma marca na cache, por isso o comando terá sucesso e nada acontecerá — após um deploy os workers continuam com o código antigo. Use redis, database ou file.',
        ],
    ],

    'errors' => [
        'queue_conflict' => 'Esta aplicação já tem o outro tipo de worker de fila. O Horizon supervisiona os seus próprios workers, por isso correr ambos faz cada trabalho ser processado duas vezes.',
        'extra_config_user' => 'Defina a conta em «Executar como», não na configuração extra.',
        'user_not_allowed' => 'Os workers só podem ser executados com a conta do site (:user). Apenas o administrador do painel pode escolher outra conta.',
        'directory_outside_home' => 'O diretório tem de estar dentro da pasta pessoal do site (:home).',
        'log_outside_logs' => 'O ficheiro de registo tem de estar na pasta de registos do site (:path).',
        'extra_config_key' => 'A configuração extra não pode definir «:key». Apenas o administrador do painel pode alterá-lo.',
    ],
];
