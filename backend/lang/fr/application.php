<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Créateur de blogs et de sites web'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Gérez vos bases de données dans le navigateur'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Synchronisation et partage de fichiers privés'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Système de gestion de contenu flexible'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Cours et apprentissage en ligne'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Automatisation marketing et campagnes'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Gestion de contenu pour développeurs'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Comptabilité et facturation'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'CMS en fichiers plats — sans base de données'],
        'git' => ['title' => 'Depuis un dépôt Git', 'tagline' => 'Déployez votre propre code depuis GitHub, GitLab ou Bitbucket'],
        'php' => ['title' => 'Site PHP vide', 'tagline' => 'Un site vide — téléversez vos propres fichiers'],
        'static' => ['title' => 'Site statique', 'tagline' => 'HTML, CSS et JavaScript simples'],
    ],

    'status' => [
        'pending' => 'Pas encore déployé',
        'provisioning' => 'Configuration en cours…',
        'active' => 'En service',
        'failed' => 'Échec de la configuration',
    ],

    'unavailable' => [
        'php' => 'PHP n\'est pas installé sur ce serveur.',
        'node' => 'Node.js n\'est pas installé sur ce serveur.',
    ],

    'git_source' => [
        'account' => 'Depuis un compte connecté',
        'public_url' => 'Coller l\'URL d\'un dépôt public',
    ],

    'fields' => [
        'name' => 'Nom',
        'domain' => 'Domaine',
        'system_user_id' => 'Utilisateur système',
        'php_version' => 'Version de PHP',
        'node_version' => 'Version de Node.js',
        'app_port' => 'Port de l\'application',
        'web_root' => 'Racine web',
        'build_command' => 'Commande de build',
        'start_command' => 'Commande de démarrage',
        'git_source' => 'Source',
        'git_account_id' => 'Compte Git',
        'repository' => 'Dépôt',
        'repository_url' => 'URL du dépôt',
        'branch' => 'Branche',
        'site_title' => 'Titre du site',
        'admin_user' => 'Identifiant administrateur',
        'admin_email' => 'E-mail administrateur',
        'admin_password' => 'Mot de passe administrateur',
        'site_language' => 'Langue du site',
        'table_prefix' => 'Préfixe des tables',
    ],

    'help' => [
        'repository_url' => 'Un dépôt public — aucun compte requis. Doit être une adresse https://.',
        'build_command' => 'Exécutée après la récupération du code, ex. composer install --no-dev',
    ],

    'steps' => [
        'create_database' => 'Création de la base de données',
        'download' => 'Téléchargement de l\'application',
        'extract' => 'Décompression des fichiers',
        'configure' => 'Écriture de la configuration',
        'install_cli' => 'Installation de l\'outil d\'installation',
        'install_app' => 'Exécution de l\'installateur',
        'clone' => 'Clonage du dépôt',
        'fetch' => 'Récupération du code le plus récent',
        'checkout' => 'Basculement sur la branche',
        'build' => 'Exécution de la commande de build',
        'write_credential' => 'Préparation de l\'accès git',
        'create_directory' => 'Création du répertoire',
        'set_ownership' => 'Attribution des droits',
        'placeholder' => 'Ajout d\'une page provisoire',
        'write_config' => 'Écriture de la configuration du site',
        'test_config' => 'Test de la configuration',
        'reload' => 'Rechargement du serveur web',
        'worker' => 'Le processus en arrière-plan s\'est arrêté',
    ],
];
