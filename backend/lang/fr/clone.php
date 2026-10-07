<?php

return [
    'status' => [
        'pending' => 'En file d\'attente',
        'running' => 'Clonage',
        'completed' => 'Terminé',
        'failed' => 'Échec',
    ],

    'current_step' => [
        'provisioning' => 'Création du site',
        'copying_files' => 'Copie des fichiers',
        'cloning_database' => 'Clonage de la base de données',
        'starting_process' => 'Démarrage de l\'application',
    ],

    'cloning_errors' => [
        'crashed' => 'Le clonage s\'est arrêté de manière inattendue.',
        'failed' => 'Le clonage a échoué. Communiquez la référence au support.',
        'abandoned' => 'Ce clonage n’a jamais démarré et a été libéré. Relancez-le.',
        'copy_failed' => 'La copie de l’application a échoué sur le serveur. Communiquez la référence au support.',
        'setup_failed' => 'La copie n’a pas pu être préparée sur le serveur. Communiquez la référence au support.',
    ],

    'errors' => [
        'already_running' => 'Cette application est déjà en cours de clonage. Attendez la fin, puis lancez-en un autre.',
    ],
];
