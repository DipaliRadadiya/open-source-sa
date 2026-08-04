<?php

return [
    'kinds' => [
        'queue' => 'Worker de file',
        'horizon' => 'Horizon',
        'custom' => 'Personnalisé',
    ],

    'states' => [
        'running' => 'En cours',
        'degraded' => 'Partiellement actif',
        'stopped' => 'Arrêté',
    ],

    'presets' => [
        'queue' => [
            'title' => 'Worker de file',
            'description' => 'Traite les tâches en file d\'attente. Le choix habituel.',
        ],
        'horizon' => [
            'title' => 'Horizon',
            'description' => 'Supervise ses propres workers, avec tableau de bord. À utiliser à la place d\'un worker de file, pas en plus.',
        ],
        'custom' => [
            'title' => 'Commande personnalisée',
            'description' => 'N\'importe quelle commande de longue durée, maintenue en vie.',
        ],
    ],

    'checks' => [
        'cache_driver_array' => [
            'title' => 'Les workers ne peuvent pas être redémarrés automatiquement',
            'detail' => 'Cette application utilise le driver de cache « array », qui ne persiste pas entre les processus. Laravel redémarre les workers en laissant un indicateur dans le cache : la commande réussira sans rien faire, et après un déploiement vos workers continueront avec l\'ancien code. Utilisez redis, database ou file.',
        ],
    ],

    'errors' => [
        'queue_conflict' => 'Cette application a déjà l\'autre type de worker de file. Horizon supervise ses propres workers : exécuter les deux fait traiter chaque tâche deux fois.',
    ],
];
