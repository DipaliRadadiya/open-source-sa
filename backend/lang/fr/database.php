<?php

return [

    'install_steps' => [
        'queued' => 'En attente',
        'checking_conflicts' => 'Vérification des moteurs de base de données en conflit',
        'preparing_repository' => 'Préparation du dépôt de paquets',
        'updating_package_index' => 'Mise à jour de l’index des paquets',
        'preparing' => 'Préparation des paquets',
        'downloading' => 'Téléchargement des paquets',
        'unpacking' => 'Décompression des paquets',
        'configuring' => 'Configuration des paquets',
        'starting_service' => 'Démarrage du service de base de données',
        'verifying_connection' => 'Vérification de la connexion à la base de données',
        'creating_panel_account' => 'Création du compte de base de données du panel',
    ],

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'La sauvegarde de la base de données a échoué. Citez la référence ci-dessous au support.',
        'database_missing' => 'La base de données a été supprimée avant que l\'export puisse s\'exécuter.',
        'worker' => 'L\'export s\'est arrêté de manière inattendue. Il a peut-être expiré — réessayez.',
        'unknown' => 'L\'export a échoué. Citez la référence ci-dessous au support.',
    ],

];
