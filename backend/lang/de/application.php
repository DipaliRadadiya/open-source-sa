<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Blog- und Website-Baukasten'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Verwalten Sie Ihre Datenbanken im Browser'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Private Dateisynchronisation und -freigabe'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Flexibles Content-Management-System'],
        'git' => ['title' => 'Aus Git-Repository', 'tagline' => 'Eigenen Code von GitHub, GitLab oder Bitbucket ausrollen'],
        'php' => ['title' => 'Leere PHP-Seite', 'tagline' => 'Eine leere Seite — eigene Dateien hochladen'],
        'static' => ['title' => 'Statische Seite', 'tagline' => 'Reines HTML, CSS und JavaScript'],
    ],

    'status' => [
        'pending' => 'Noch nicht ausgerollt',
        'provisioning' => 'Wird eingerichtet…',
        'active' => 'Läuft',
        'failed' => 'Einrichtung fehlgeschlagen',
    ],

    'unavailable' => [
        'php' => 'Auf diesem Server ist PHP nicht installiert.',
        'node' => 'Auf diesem Server ist Node.js nicht installiert.',
    ],

    'git_source' => [
        'account' => 'Aus einem verbundenen Konto',
        'public_url' => 'URL eines öffentlichen Repositorys einfügen',
    ],

    'fields' => [
        'name' => 'Name',
        'domain' => 'Domain',
        'system_user_id' => 'Systembenutzer',
        'php_version' => 'PHP-Version',
        'node_version' => 'Node.js-Version',
        'app_port' => 'App-Port',
        'web_root' => 'Web-Root',
        'build_command' => 'Build-Befehl',
        'start_command' => 'Startbefehl',
        'git_source' => 'Quelle',
        'git_account_id' => 'Git-Konto',
        'repository' => 'Repository',
        'repository_url' => 'Repository-URL',
        'branch' => 'Branch',
        'site_title' => 'Seitentitel',
        'admin_user' => 'Administrator-Benutzername',
        'admin_email' => 'Administrator-E-Mail',
        'admin_password' => 'Administrator-Passwort',
        'site_language' => 'Sprache der Seite',
        'table_prefix' => 'Tabellenpräfix',
    ],

    'help' => [
        'repository_url' => 'Ein öffentliches Repository — kein Konto nötig. Muss eine https://-Adresse sein.',
        'build_command' => 'Läuft nach dem Abrufen des Codes, z. B. composer install --no-dev',
    ],

    'steps' => [
        'create_database' => 'Datenbank wird erstellt',
        'download' => 'Anwendung wird heruntergeladen',
        'extract' => 'Dateien werden entpackt',
        'configure' => 'Konfiguration wird geschrieben',
        'install_cli' => 'Setup-Werkzeug wird installiert',
        'install_app' => 'Installer wird ausgeführt',
        'clone' => 'Repository wird geklont',
        'fetch' => 'Neuester Code wird geholt',
        'checkout' => 'Branch wird ausgecheckt',
        'build' => 'Build-Befehl wird ausgeführt',
        'write_credential' => 'Git-Zugang wird vorbereitet',
        'create_directory' => 'Verzeichnis wird erstellt',
        'set_ownership' => 'Besitzrechte werden gesetzt',
        'placeholder' => 'Platzhalterseite wird angelegt',
        'write_config' => 'Website-Konfiguration wird geschrieben',
        'test_config' => 'Konfiguration wird geprüft',
        'reload' => 'Webserver wird neu geladen',
        'worker' => 'Der Hintergrundprozess wurde beendet',
    ],
];
