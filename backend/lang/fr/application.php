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
        'docker' => ['title' => 'Conteneur Docker', 'tagline' => 'N\'importe quelle image, de n\'importe quel registre, servie via nginx.'],
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Créateur de blogs et de sites web'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Gérez vos bases de données dans le navigateur'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Surveillance de disponibilité et pages de statut'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Automatisation de flux de travail (licence fair-code)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Reliez appareils, API et services'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Logiciel de forum — nécessite MongoDB ou PostgreSQL'],
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
        'stack' => 'Ce serveur n\'exécute que des conteneurs ; il n\'héberge donc pas ce type d\'application.',
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
        'compose' => 'Fichier compose',
        'image' => 'Image',
        'container_port' => 'Port du conteneur',
        'docker_network' => 'Réseau',
        'database_engine' => 'Moteur de base de données',
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
        'mailer_host' => 'smtp.exemple.fr',
        'mailer_port' => '587',
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
        'compose' => 'Facultatif. Collez votre propre fichier compose et tout ce que Compose gère est géré : plusieurs services, volumes nommés, healthchecks. Laissez-le vide et le panel en écrira un à partir des champs ci-dessus. Les ports doivent être publiés sur 127.0.0.1 et les montages rester dans le répertoire de cette application ; tout le reste est refusé avec la raison.',
        'image' => 'L\'image à exécuter, avec une étiquette explicite : `nginx:1.27-alpine`. Un nom sans étiquette utilise `latest`, ce qui rend un déploiement non reproductible et un retour arrière vide de sens.',
        'container_port' => 'Le port sur lequel votre application écoute à l\'intérieur du conteneur. Le panel attribue le port sur le serveur lui-même et y dirige nginx.',
        'docker_network' => 'Rejoignez un réseau Docker pour que ce conteneur et les autres du même réseau puissent s\'atteindre par leur nom. Laissez vide pour le bridge par défaut de Docker, où ce n\'est pas possible. Les réseaux se créent sur la page Docker.',
        'table_prefix_random' => 'Laissez vide et un préfixe aléatoire sera généré, ce qui sépare les tables si la base de données est un jour partagée.',
        'timezone' => 'Fuseau horaire du site, par ex. America/New_York ou Europe/Paris. Voir Réglages → Général → Fuseau horaire.',
        'table_prefix_optional' => 'Facultatif. Si vous le videz, les tables sont créées sans aucun préfixe.',
        'start_command' => 'Le fichier d\'entrée, par exemple « node server.js ». Pas « npm start » : un gestionnaire de paquets fork le vrai processus, donc les signaux d\'arrêt ne l\'atteignent jamais.',
        'app_port' => 'Laissé vide, le panneau en choisit un libre.',
        'rendering_type' => 'Le rendu côté serveur exécute votre app et lui sert de proxy. Les deux autres compilent des fichiers que le serveur web sert directement — plus rapide, et rien à maintenir en marche.',
        'repository_url' => 'Un dépôt public — aucun compte requis. Doit être une adresse https://.',
        'build_command' => 'Exécutée après la récupération du code, ex. composer install --no-dev',
        'deploy_script' => 'S’exécute après la récupération du code, en tant qu’utilisateur du site et avec la version de PHP de ce site. Laissez vide pour utiliser la commande de build.',
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
        'ensure_account' => 'Création du compte système',
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
        'script' => 'Exécution du script de déploiement',
        'dependencies' => 'Vérification des dépendances',
        'verify' => 'Vérification que le site répond',
        'verify_serving' => 'Vérification que le site répond',
        'worker' => 'Le processus en arrière-plan s\'est arrêté',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'site_type_change' => [
        'git_cannot_change' => 'Ce site est déployé depuis un dépôt git, son type ne peut donc pas être modifié. Ses écrans Déploiements, Workers et fichier d\'environnement existent à cause de ce type, et les supprimer n\'arrêterait pas les workers en arrière-plan ni n\'empêcherait le webhook de déploiement d\'accepter des pushes : cela retirerait seulement les écrans qui les gèrent.',
        'git_not_a_target' => 'Un site ne peut pas être transformé en déploiement git. Cela nécessite un dépôt, une branche et un script de déploiement que le panneau gère, ce qui ne peut pas être créé à partir des fichiers déjà présents sur le serveur. Créez plutôt une application git.',
        'unchanged' => 'Ce site est déjà défini sur ce type.',
        'not_suggestable' => 'Ce site ne peut pas être changé vers ce type. Seules les applications que le panneau peut reconnaître sur le disque peuvent être réétiquetées — tout le reste revendiquerait des fonctionnalités que le site ne pourrait pas utiliser.',
        'only_from_generic' => 'Seul un site PHP personnalisé ou statique peut être réétiqueté vers un autre type d\'application. Ce site est déjà défini sur une application précise, et transformer une application en une autre n\'est pas quelque chose qu\'une étiquette peut faire.',
        'no_evidence' => 'Rien sur ce site ne ressemble à :type. Téléversez d\'abord l\'application, puis relancez Détecter : le panneau ne change le type d\'un site que lorsqu\'il peut voir l\'application dans le répertoire du site.',
    ],

    'failure_reason' => [
        'attached_database_engine_mismatch' => 'Cette application a déjà une base de données associée, mais elle fonctionne sur un moteur que cette application ne peut pas utiliser. Détachez-la, ou associez-en une sur un moteur pris en charge, puis réessayez.',
        'serving_error' => 'L\'application a démarré mais répond à chaque requête par une erreur. Ses ressources n\'ont probablement pas été entièrement construites — voir le journal de l\'application.',
        'not_answering' => 'L\'application a démarré mais n\'a jamais répondu à une requête. Consultez le journal de l\'application pour savoir pourquoi elle n\'écoute pas.',
        'out_of_memory' => 'Le serveur a manqué de mémoire pendant cette étape et le système l\'a arrêtée. Libérez de la mémoire, ou ajoutez du swap, puis réessayez.',
        'no_build_tools' => 'Cette étape devait compiler un module natif, et aucun compilateur n’est installé sur ce serveur. Installez les outils de compilation depuis l’écran de configuration, puis réessayez. Choisir une autre version de Node peut aussi aider, car certaines fournissent des binaires précompilés — mais chaque paquet décide lesquelles, ce n’est donc pas une solution fiable à elle seule.',
        'composer_platform' => 'Composer n’a pas pu installer les dépendances de cette application avec la version de PHP configurée pour ce site. La version de PHP du site, ou l’une des extensions dont elle a besoin, ne correspond pas à ce qu’exige le projet. Choisissez une version de PHP prise en charge par le projet, ou installez l’extension manquante, puis redéployez.',
        'composer_dependencies_missing' => 'Ce projet nécessite des dépendances Composer et aucune n’a été installée : l’application n’a pas de vendor/autoload.php et toutes les requêtes échoueront. Ajoutez une étape composer install au script de déploiement, puis redéployez.',
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

    'supervisor_installing' => 'Installation de supervisor, sous lequel tournent les workers. Cela prend un instant : recréez le worker une fois terminé.',

    'placeholder_page' => [
        'lede' => 'Ce site est prêt et en ligne. Remplacez cette page par la vôtre — d\'ici là, chaque visiteur la voit.',
        'php_running' => 'PHP fonctionne sur ce site',
        'step_files_title' => 'Envoyez vos fichiers',
        'step_files_body' => 'Utilisez le gestionnaire de fichiers du panneau, ou connectez-vous en SFTP avec l\'utilisateur système du site.',
        'step_deploy_title' => 'Ou déployez depuis git',
        'step_deploy_body' => 'Reliez le site à un dépôt et le panneau le récupérera et le construira à chaque push.',
        'foot' => 'Page provisoire créée par le panneau de contrôle.',
    ],

    'disabled_page' => [
        'title' => 'Site indisponible',
        'heading' => 'Ce site est temporairement indisponible',
        'lede' => 'Il a été mis hors ligne par son propriétaire. Merci de réessayer plus tard.',
        'foot' => 'Servi par le panneau de contrôle.',
    ],
];
