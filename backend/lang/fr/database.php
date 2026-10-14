<?php

return [

    'install_steps' => [
        'queued' => 'En attente',
        'checking_conflicts' => 'Vérification des moteurs de base de données en conflit',
        'preparing_repository' => 'Préparation du dépôt de paquets',
        'waiting_for_package_manager' => 'En attente de la fin d’une autre opération de paquets',
        'updating_package_index' => 'Mise à jour de l’index des paquets',
        'preparing' => 'Préparation des paquets',
        'downloading' => 'Téléchargement des paquets',
        'unpacking' => 'Décompression des paquets',
        'configuring' => 'Configuration des paquets',
        'starting_service' => 'Démarrage du service de base de données',
        'verifying_cluster' => 'Vérification du cluster de base de données',
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

    'import_failed' => [
        'unknown' => 'L’importation a échoué. Communiquez la référence ci-dessous au support.',
        'database_missing' => 'La base de données a été supprimée avant que l’importation puisse s’exécuter.',
        'no_account' => 'Cette base de données n’a aucun utilisateur avec lequel exécuter l’importation : un utilisateur dont le panneau connaît le mot de passe et qui se connecte depuis ce serveur.',
        'wrong_format' => 'Ce fichier n’est pas un dump que cette base de données peut importer. MySQL et MariaDB acceptent .sql ou .sql.gz ; PostgreSQL accepte .sql, .sql.gz ou une archive personnalisée de pg_dump ; MongoDB accepte un fichier mongodump --archive.',
        'client_command' => 'La ligne :line du fichier contient une commande psql (une commande avec barre oblique inverse comme \\!). Ces commandes peuvent lancer des programmes sur le serveur, le fichier a donc été refusé. Exportez à nouveau la base de données en SQL brut avec pg_dump.',
        'truncated' => 'Le fichier s’arrête au milieu de ses données : il est incomplet. Exportez-le à nouveau.',
        'unreadable' => 'Le fichier n’a pas pu être lu comme une archive PostgreSQL.',
        'backup_failed' => 'La sauvegarde effectuée avant l’importation a échoué, rien n’a donc été importé.',
        'load_failed' => 'Le fichier n’a pas pu être chargé dans la base de données. Communiquez la référence ci-dessous au support.',
        'worker' => 'L’importation s’est arrêtée de façon inattendue. Le délai a peut-être été dépassé.',
        'abandoned' => 'L’envoi n’a pas été terminé dans la journée et a été supprimé.',
        'restored' => 'La base de données a été restaurée depuis la sauvegarde automatique : rien n’a été perdu.',
        'untouched' => 'La base de données n’a pas été modifiée.',
        'not_restored' => 'La sauvegarde automatique n’a pas pu être restaurée d’elle-même. Elle se trouve dans la liste des exports : téléchargez-la et importez-la pour restaurer la base de données.',
    ],

];
