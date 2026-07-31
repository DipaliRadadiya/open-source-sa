<?php

return [

    'installing' => 'Instalando :component',

    'detail' => [
        'cache_in_use' => 'em uso para o cache do painel',
    ],

    'components' => [
        'database' => [
            'title' => 'Banco de dados',
            'description' => 'Necessário antes de instalar o WordPress ou qualquer aplicação que armazene dados.',
        ],
        'php' => [
            'title' => 'PHP',
            'description' => 'Adicione outra versão quando um site precisar.',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'Gerenciado com fnm, para que os sites fixem a própria versão.',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'Usado para o cache do painel. Sem ele, o painel recorre ao banco de dados: funciona, mas é mais lento.',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'Bloqueia tentativas de login repetidas contra o SSH e seus sites.',
        ],
    ],

];
