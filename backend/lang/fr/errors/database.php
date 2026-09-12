<?php

return [
    'operation_failed' => "L'opération de base de données a échoué sur le serveur.",
    'export_already_running' => "Un export de cette base de données est déjà en cours. Attendez qu'il se termine avant d'en lancer un autre.",
    'collation_mismatch' => "Le classement sélectionné n'appartient pas au jeu de caractères choisi.",
    'application_already_attached' => "La base de données :database est déjà rattachée à :application. Détachez-la d'abord, ou rattachez cette base de données à une autre application.",
    'engine_not_accepted' => ':application ne peut pas utiliser une base de données :engine. Elle accepte :accepted.',
    'engine_not_installable' => 'Le panneau ne peut pas encore installer ce moteur de base de données. Installez-le vous-même et le panneau le détectera.',
    // The vendor publishes nothing for this Ubuntu release. Refused
    // before the install rather than discovered two minutes into apt.
    'engine_os_unsupported' => ':engine ne publie pas encore de paquets pour :os, le panneau ne peut donc pas l\'installer ici. Ce serveur n\'a aucun problème : le panneau prend en charge :os, mais :engine n\'a pas encore publié de version pour ce système. Utilisez un autre moteur de base de données, ou réessayez lorsqu\'elle sera disponible.',
    'phpmyadmin_engine_not_supported' => 'phpMyAdmin ne prend pas en charge les bases de données :engine.',
    'phpmyadmin_not_deployed' => 'Aucun site phpMyAdmin n\'est installé sur ce serveur.',
    'phpmyadmin_no_users' => 'Créez un utilisateur de base de données avant d\'accéder à phpMyAdmin.',
    'remote_users_unsupported' => 'L\'accès distant n\'est pas disponible pour :engine — ses comptes ne sont pas liés à un hôte. Utilisez localhost.',
    // 409, not 422: the request is fine, the cluster is not ready. The
    // client re-sends with restart_cluster as explicit consent.
    'remote_access_restart_required' => 'Autoriser les connexions distantes nécessite de redémarrer PostgreSQL, car l\'adresse d\'écoute ne peut être modifiée qu\'au démarrage. Les applications qui utilisent cette base de données perdront leur connexion un instant. Renvoyez la requête avec restart_cluster pour continuer.',
    'phpmyadmin_not_selectable' => 'Le site sélectionné n\'est pas une installation phpMyAdmin active.',
    'phpmyadmin_user_not_found' => 'L\'utilisateur de base de données spécifié n\'appartient pas à cette base de données.',
    'phpmyadmin_not_isolated' => 'Ce site phpMyAdmin partage le pool PHP de tout le serveur : un lien de connexion serait donc lisible par tous les autres sites. Attribuez-lui son propre pool PHP, ou ouvrez phpMyAdmin et connectez-vous avec les identifiants de la base de données.',
    'phpmyadmin_sso_unavailable' => 'Le lien de connexion n\'a pas pu être préparé sur le site phpMyAdmin.',

];
