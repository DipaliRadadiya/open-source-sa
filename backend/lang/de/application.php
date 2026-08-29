<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'Primär',
        'alias' => 'Alias',
        'redirect' => 'Weiterleitung',
    ],

    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Blog- und Website-Baukasten'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Verwalten Sie Ihre Datenbanken im Browser'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Verfügbarkeitsüberwachung und Statusseiten'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Workflow-Automatisierung (Fair-Code-Lizenz)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Geräte, APIs und Dienste verbinden'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Forensoftware — benötigt MongoDB'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Private Dateisynchronisation und -freigabe'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Flexibles Content-Management-System'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Online-Kurse und Lernen'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Marketing-Automatisierung und Kampagnen'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Content-Management für Entwickler'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Buchhaltung und Rechnungsstellung'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'Flat-File-CMS — ohne Datenbank'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'Onlineshop und E-Commerce'],
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
        'database' => 'Diese Anwendung benötigt :engines, das auf diesem Server fehlt.',
        'php' => 'Auf diesem Server ist PHP nicht installiert.',
        'node' => 'Auf diesem Server ist Node.js nicht installiert.',
        'web_server' => 'Diese Anwendung ist auf :web_server-Servern noch nicht verfügbar.',
    ],

    'git_source' => [
        'account' => 'Aus einem verbundenen Konto',
        'public_url' => 'URL eines öffentlichen Repositorys einfügen',
    ],

    'fields' => [
        'company_name' => 'Firmenname',
        'company_email' => 'Firmen-E-Mail',
        'locale' => 'Gebietsschema',
        'site_name' => 'Website-Name',
        'language' => 'Sprache',
        'admin_name' => 'Name des Administrators',
        'admin_first_name' => 'Vorname des Administrators',
        'admin_last_name' => 'Nachname des Administrators',
        'short_name' => 'Kurzname',
        'shop_name' => 'Shop-Name',
        'country' => 'Land',
        'timezone' => 'Zeitzone',
        'rendering_type' => 'Rendering-Typ',
        'name' => 'Name',
        'domain' => 'Domain',
        'system_user_id' => 'Systembenutzer',
        'php_version' => 'PHP-Version',
        'node_version' => 'Node.js-Version',
        'app_port' => 'App-Port',
        'web_root' => 'Web-Root',
        'build_command' => 'Build-Befehl',
        'deploy_script' => 'Deploy-Skript',
        'start_command' => 'Startbefehl',
        'package_manager' => 'Paketmanager',
        'git_source' => 'Quelle',
        'git_account_id' => 'Git-Konto',
        'repository' => 'Repository',
        'repository_url' => 'Repository-URL',
        'branch' => 'Branch',
        'site_title' => 'Seitentitel',
        'admin_user' => 'Administrator-Benutzername',
        'admin_username' => 'Admin-Benutzername',
        'admin_email' => 'Administrator-E-Mail',
        'admin_password' => 'Administrator-Passwort',
        'site_language' => 'Sprache der Seite',
        'table_prefix' => 'Tabellenpräfix',
        'mailer_name' => 'Absendername',
        'mailer_email' => 'Absenderadresse',
        'mailer_host' => 'SMTP-Host',
        'mailer_port' => 'SMTP-Port',
        'mailer_username' => 'SMTP-Benutzername',
        'mailer_password' => 'SMTP-Passwort',
    ],

    'help' => [
        'start_command' => 'Die Einstiegsdatei, z. B. „node server.js“. Nicht „npm start“ – ein Paketmanager forkt den eigentlichen Prozess, sodass Shutdown-Signale ihn nie erreichen.',
        'app_port' => 'Leer gelassen wählt das Panel einen freien Port.',
        'rendering_type' => 'Server-Rendering führt deine App aus und leitet an sie weiter. Die anderen beiden bauen Dateien, die der Webserver direkt ausliefert – schneller, und nichts muss laufen.',
        'repository_url' => 'Ein öffentliches Repository — kein Konto nötig. Muss eine https://-Adresse sein.',
        'build_command' => 'Läuft nach dem Abrufen des Codes, z. B. composer install --no-dev',
        'deploy_script' => 'Läuft nach dem Abrufen des Codes, als dein Website-Benutzer. Leer lassen, um den Build-Befehl zu verwenden.',
        'package_manager' => 'Was deine Abhängigkeiten installiert und baut. Füllt den Build-Befehl unten aus – danach frei bearbeitbar.',
    ],

    'steps' => [
        'create_database' => 'Datenbank wird erstellt',
        'download' => 'Anwendung wird heruntergeladen',
        'extract' => 'Dateien werden entpackt',
        'configure' => 'Konfiguration wird geschrieben',
        'install_cli' => 'Setup-Werkzeug wird installiert',
        'install_app' => 'Installer wird ausgeführt',
        'init' => 'Repository wird eingerichtet',
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
        'start_app' => 'Anwendung wird gestartet',
        'write_unit' => 'Dienst wird vorbereitet',
        'restart_app' => 'Anwendung wird neu gestartet',
        'harden' => 'Sicherheitseinstellungen werden angewendet',
        'trust_domain' => 'Domain wird freigegeben',
        'set_password' => 'Administrator-Passwort wird gesetzt',
        'worker' => 'Der Hintergrundprozess wurde beendet',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'out_of_memory' => 'Dem Server ging bei diesem Schritt der Speicher aus und das System hat ihn beendet. Geben Sie Speicher frei oder fügen Sie Swap hinzu und versuchen Sie es erneut.',
    ],

    'port_free' => 'Port :port ist frei.',

    'rendering' => [
        'php' => 'PHP-Anwendung (Laravel, Symfony, einfaches PHP)',
        'ssr' => 'Server-Rendering (führt einen Prozess aus)',
        'csr' => 'Client-Rendering (zu Dateien gebaut)',
        'static' => 'Statische Seite (zu Dateien gebaut)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

];
