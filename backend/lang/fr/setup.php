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
            'description' => 'Ajoutez une autre version lorsqu\'une application en a besoin.',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'Géré avec fnm, pour que chaque application fixe sa propre version.',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'Utilisé pour le cache du panneau. Sans lui, le panneau se rabat sur la base de données : cela fonctionne, mais c\'est plus lent.',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'Bloque les tentatives de connexion répétées contre SSH et vos applications.',
        ],
        'build_tools' => [
            'title' => 'Outils de compilation',
            'description' => 'Permet au serveur de compiler les composants d\'application qui ne sont pas livrés précompilés. Certaines applications Node en ont besoin pour s\'installer.',
        ],
        'wp_cli' => [
            'title' => 'WP-CLI',
            'description' => 'L\'outil en ligne de commande de WordPress. Le panneau l\'utilise pour chaque tâche WordPress et le télécharge avec la première application WordPress s\'il manque.',
        ],
    ],

];
