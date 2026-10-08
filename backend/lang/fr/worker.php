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
        'extra_config_user' => 'Définissez le compte dans « Exécuter en tant que », pas dans la configuration supplémentaire.',
        'extra_config_environment' => 'Le panneau définit déjà l\'environnement de ce processus. Supprimez la ligne environment= de la configuration supplémentaire.',
        'did_not_start' => 'Le processus ne s’est pas maintenu, il n’a donc pas été ajouté. Vérifiez la commande. Référence : :reference',
        'did_not_start_said' => 'Le processus ne s’est pas maintenu, il n’a donc pas été ajouté. Il a affiché : « :output » (référence :reference)',
        'did_not_start_field' => 'Cette commande se termine tout de suite au lieu de continuer à tourner.',
        'user_not_allowed' => 'Les workers ne peuvent s\'exécuter qu\'avec le compte du site (:user). Seul l\'administrateur du panneau peut choisir un autre compte.',
        'directory_outside_home' => 'Le répertoire doit se trouver dans le dossier personnel du site (:home).',
        'log_outside_logs' => 'Le fichier journal doit se trouver dans le dossier des journaux du site (:path).',
        'extra_config_key' => 'La configuration supplémentaire ne peut pas définir « :key ». Seul l\'administrateur du panneau peut le modifier.',
    ],
];
