<?php

return [

    'installing' => 'Installation de :component',

    'detail' => [
        'cache_in_use' => 'utilisé pour le cache du panneau',
    ],

    'components' => [
        'database' => [
            'title' => 'Base de données',
            'description' => 'Nécessaire avant d\'installer WordPress ou toute application qui stocke des données.',
        ],
        'php' => [
            'title' => 'PHP',
            'description' => 'Ajoutez une autre version lorsqu\'un site en a besoin.',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'Géré avec fnm, pour que chaque site fixe sa propre version.',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'Utilisé pour le cache du panneau. Sans lui, le panneau se rabat sur la base de données : cela fonctionne, mais c\'est plus lent.',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'Bloque les tentatives de connexion répétées contre SSH et vos sites.',
        ],
    ],

];
