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
        'mailer_host' => 'smtp.beispiel.de',
        'mailer_port' => '587',
        'site_title' => 'Meine Website',
        'site_name' => 'Meine Website',
        'shop_name' => 'Mein Shop',
        'company_name' => 'Meine Firma',
        'short_name' => 'meineseite',
        'mailer_name' => 'Meine Website',
        'admin_email' => 'du@beispiel.de',
        'company_email' => 'du@beispiel.de',
        'mailer_email' => 'no-reply@beispiel.de',
        'mailer_username' => 'no-reply@beispiel.de',
        'timezone' => 'Europe/Berlin',
        'repository_url' => 'https://github.com/du/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'help' => [
        'table_prefix_random' => 'Leer lassen, dann wird ein zufälliges Präfix erzeugt — so bleiben die Tabellen getrennt, falls die Datenbank je geteilt wird.',
        'timezone' => 'Zeitzone der Website, z. B. America/New_York oder Europe/Berlin. Siehe Einstellungen → Allgemein → Zeitzone.',
        'table_prefix_optional' => 'Optional. Wird das Feld geleert, werden die Tabellen ganz ohne Präfix angelegt.',
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
        'seed_env' => 'Umgebungsdatei wird vorbereitet',
        'build' => 'Build-Befehl wird ausgeführt',
        'write_credential' => 'Git-Zugang wird vorbereitet',
        'check_account' => 'Systemkonto wird geprüft',
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
        'verify_serving' => 'Prüfen, ob die Website antwortet',
        'worker' => 'Der Hintergrundprozess wurde beendet',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'serving_error' => 'Die Anwendung wurde gestartet, beantwortet aber jede Anfrage mit einem Fehler. Wahrscheinlich wurden ihre Assets nicht vollständig gebaut — Einzelheiten im Anwendungsprotokoll.',
        'not_answering' => 'Die Anwendung wurde gestartet, hat aber nie auf eine Anfrage geantwortet. Im Anwendungsprotokoll steht, warum sie nicht lauscht.',
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
