<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'Principal',
        'alias' => 'Alias',
        'redirect' => 'Redirection',
    ],

    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Créateur de blogs et de sites web'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Gérez vos bases de données dans le navigateur'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Surveillance de disponibilité et pages de statut'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Automatisation de flux de travail (licence fair-code)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Reliez appareils, API et services'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Logiciel de forum — nécessite MongoDB'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Synchronisation et partage de fichiers privés'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Système de gestion de contenu flexible'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Cours et apprentissage en ligne'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Automatisation marketing et campagnes'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Gestion de contenu pour développeurs'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Comptabilité et facturation'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'CMS en fichiers plats — sans base de données'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'Boutique en ligne et e-commerce'],
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
        'database' => 'Cette application nécessite :engines, absent de ce serveur.',
        'php' => 'PHP n\'est pas installé sur ce serveur.',
        'node' => 'Node.js n\'est pas installé sur ce serveur.',
        'web_server' => 'Cette application n\'est pas encore disponible sur les serveurs :web_server.',
    ],

    'git_source' => [
        'account' => 'Depuis un compte connecté',
        'public_url' => 'Coller l\'URL d\'un dépôt public',
    ],

    'fields' => [
        'company_name' => 'Nom de la société',
        'company_email' => 'E-mail de la société',
        'locale' => 'Paramètres régionaux',
        'site_name' => 'Nom du site',
        'language' => 'Langue',
        'admin_name' => 'Nom de l’administrateur',
        'admin_first_name' => 'Prénom de l’administrateur',
        'admin_last_name' => 'Nom de l’administrateur',
        'short_name' => 'Nom court',
        'shop_name' => 'Nom de la boutique',
        'country' => 'Pays',
        'timezone' => 'Fuseau horaire',
        'rendering_type' => 'Type de rendu',
        'name' => 'Nom',
        'domain' => 'Domaine',
        'system_user_id' => 'Utilisateur système',
        'php_version' => 'Version de PHP',
        'node_version' => 'Version de Node.js',
        'app_port' => 'Port de l\'application',
        'web_root' => 'Racine web',
        'build_command' => 'Commande de build',
        'deploy_script' => 'Script de déploiement',
        'start_command' => 'Commande de démarrage',
        'package_manager' => 'Gestionnaire de paquets',
        'git_source' => 'Source',
        'git_account_id' => 'Compte Git',
        'repository' => 'Dépôt',
        'repository_url' => 'URL du dépôt',
        'branch' => 'Branche',
        'site_title' => 'Titre du site',
        'admin_user' => 'Identifiant administrateur',
        'admin_username' => 'Nom d\'utilisateur admin',
        'admin_email' => 'E-mail administrateur',
        'admin_password' => 'Mot de passe administrateur',
        'site_language' => 'Langue du site',
        'table_prefix' => 'Préfixe des tables',
        'mailer_name' => 'Nom de l\'expéditeur',
        'mailer_email' => 'Adresse de l\'expéditeur',
        'mailer_host' => 'Hôte SMTP',
        'mailer_port' => 'Port SMTP',
        'mailer_username' => 'Nom d\'utilisateur SMTP',
        'mailer_password' => 'Mot de passe SMTP',
    ],

    /*
    | Example values, shown as ghost text in an empty field.
    |
    | A placeholder is NOT a default: it is never submitted. Anything with a
    | correct value the panel can pick lives in the field's `default` instead,
    | which the form pre-fills and the request carries — a table prefix is a
    | default, an email address is a placeholder. Getting that backwards ships
    | a form that looks filled in and posts null.
    |
    | Keyed by field name, not by site type, so one entry serves every type
    | declaring that field — the same arrangement as `fields` and `help`.
    | Localized because these are read by a person: an example is only an
    | example if it is in a language they read.
    */
    'placeholders' => [
        'site_title' => 'Mon site',
        'site_name' => 'Mon site',
        'shop_name' => 'Ma boutique',
        'company_name' => 'Mon entreprise',
        'short_name' => 'monsite',
        'mailer_name' => 'Mon site',
        'admin_email' => 'vous@exemple.fr',
        'company_email' => 'vous@exemple.fr',
        'mailer_email' => 'no-reply@exemple.fr',
        'mailer_username' => 'no-reply@exemple.fr',
        'timezone' => 'Europe/Paris',
        'repository_url' => 'https://github.com/vous/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'help' => [
        'table_prefix_random' => 'Laissez vide et un préfixe aléatoire sera généré, ce qui sépare les tables si la base de données est un jour partagée.',
        'timezone' => 'Fuseau horaire du site, par ex. America/New_York ou Europe/Paris. Voir Réglages → Général → Fuseau horaire.',
        'table_prefix_optional' => 'Facultatif. Si vous le videz, les tables sont créées sans aucun préfixe.',
        'start_command' => 'Le fichier d\'entrée, par exemple « node server.js ». Pas « npm start » : un gestionnaire de paquets fork le vrai processus, donc les signaux d\'arrêt ne l\'atteignent jamais.',
        'app_port' => 'Laissé vide, le panneau en choisit un libre.',
        'rendering_type' => 'Le rendu côté serveur exécute votre app et lui sert de proxy. Les deux autres compilent des fichiers que le serveur web sert directement — plus rapide, et rien à maintenir en marche.',
        'repository_url' => 'Un dépôt public — aucun compte requis. Doit être une adresse https://.',
        'build_command' => 'Exécutée après la récupération du code, ex. composer install --no-dev',
        'deploy_script' => 'S\'exécute après la récupération du code, en tant qu\'utilisateur du site. Laissez vide pour utiliser la commande de build.',
        'package_manager' => 'Ce qui installe et compile vos dépendances. Remplit la commande de build ci-dessous — modifiable librement ensuite.',
    ],

    'steps' => [
        'create_database' => 'Création de la base de données',
        'download' => 'Téléchargement de l\'application',
        'extract' => 'Décompression des fichiers',
        'configure' => 'Écriture de la configuration',
        'install_cli' => 'Installation de l\'outil d\'installation',
        'install_app' => 'Exécution de l\'installateur',
        'init' => 'Configuration du dépôt',
        'fetch' => 'Récupération du code le plus récent',
        'checkout' => 'Basculement sur la branche',
        'seed_env' => 'Préparation du fichier d’environnement',
        'build' => 'Exécution de la commande de build',
        'write_credential' => 'Préparation de l\'accès git',
        'check_account' => 'Vérification du compte système',
        'create_directory' => 'Création du répertoire',
        'set_ownership' => 'Attribution des droits',
        'placeholder' => 'Ajout d\'une page provisoire',
        'write_config' => 'Écriture de la configuration du site',
        'test_config' => 'Test de la configuration',
        'reload' => 'Rechargement du serveur web',
        'start_app' => 'Démarrage de l\'application',
        'write_unit' => 'Préparation du service',
        'restart_app' => 'Redémarrage de l\'application',
        'harden' => 'Application des réglages de sécurité',
        'trust_domain' => 'Autorisation du domaine',
        'set_password' => 'Définition du mot de passe administrateur',
        'verify_serving' => 'Vérification que le site répond',
        'worker' => 'Le processus en arrière-plan s\'est arrêté',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'serving_error' => 'L\'application a démarré mais répond à chaque requête par une erreur. Ses ressources n\'ont probablement pas été entièrement construites — voir le journal de l\'application.',
        'not_answering' => 'L\'application a démarré mais n\'a jamais répondu à une requête. Consultez le journal de l\'application pour savoir pourquoi elle n\'écoute pas.',
        'out_of_memory' => 'Le serveur a manqué de mémoire pendant cette étape et le système l\'a arrêtée. Libérez de la mémoire, ou ajoutez du swap, puis réessayez.',
    ],

    'port_free' => 'Le port :port est libre.',

    'rendering' => [
        'php' => 'Application PHP (Laravel, Symfony, PHP simple)',
        'ssr' => 'Rendu côté serveur (exécute un processus)',
        'csr' => 'Rendu côté client (compilé en fichiers)',
        'static' => 'Site statique (compilé en fichiers)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

];
