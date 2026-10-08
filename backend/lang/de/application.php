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
        'docker' => ['title' => 'Docker-Container', 'tagline' => 'Jedes Image aus jeder Registry, über nginx bereitgestellt.'],
        'ghost' => ['title' => 'Ghost', 'tagline' => 'Publizieren und Newsletter — läuft mit eigenem MySQL'],
        'matomo' => ['title' => 'Matomo', 'tagline' => 'Web-Analyse auf Ihrem eigenen Server — ein Ersatz für Google Analytics'],
        'mattermost' => ['title' => 'Mattermost', 'tagline' => 'Team-Chat — läuft mit eigenem PostgreSQL'],
        'chatwoot' => ['title' => 'Chatwoot', 'tagline' => 'Kundensupport-Postfach — läuft mit eigenem PostgreSQL und Redis'],
        'excalidraw' => ['title' => 'Excalidraw', 'tagline' => 'Whiteboard — Zeichnungen bleiben im Browser; Teilen und Live-Zusammenarbeit laufen über excalidraw.com'],
        'forgejo' => ['title' => 'Forgejo', 'tagline' => 'Git-Hosting — von der Community verwalteter Gitea-Fork'],
        'freshrss' => ['title' => 'FreshRSS', 'tagline' => 'Selbst gehosteter Feed-Reader'],
        'gitea' => ['title' => 'Gitea', 'tagline' => 'Git-Hosting mit Issues und Pull Requests'],
        'glance' => ['title' => 'Glance', 'tagline' => 'Dashboard für Feeds, Monitore und Lesezeichen'],
        'homepage' => ['title' => 'Homepage', 'tagline' => 'Startseite für die Dienste auf Ihrem Server'],
        'ittools' => ['title' => 'IT-Tools', 'tagline' => 'Entwickler-Werkzeuge — es wird nichts gespeichert'],
        'stirlingpdf' => ['title' => 'Stirling PDF', 'tagline' => 'PDFs im Browser teilen, zusammenführen, signieren und umwandeln'],
        'vaultwarden' => ['title' => 'Vaultwarden', 'tagline' => 'Passwortmanager, kompatibel mit Bitwarden-Apps'],
        'nocodb' => ['title' => 'NocoDB', 'tagline' => 'Tabellenoberfläche für eine Datenbank — läuft mit eigenem PostgreSQL'],
        'metabase' => ['title' => 'Metabase', 'tagline' => 'Dashboards und Auswertungen Ihrer Daten — läuft mit eigenem PostgreSQL'],
        'wikijs' => ['title' => 'Wiki.js', 'tagline' => 'Dokumentations-Wiki — läuft mit eigenem PostgreSQL'],
        'grafana' => ['title' => 'Grafana', 'tagline' => 'Dashboards und Alarme über Ihre Metriken — das Admin-Passwort wird pro Site erzeugt'],
        'bookstack' => ['title' => 'BookStack', 'tagline' => 'Dokumentation in Regalen, Büchern und Seiten — läuft mit eigener MariaDB'],
        'wordpress_container' => ['title' => 'WordPress', 'tagline' => 'Blog- und Website-Baukasten — läuft als Container mit eigener MariaDB'],
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Blog- und Website-Baukasten'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Verwalten Sie Ihre Datenbanken im Browser'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Verfügbarkeitsüberwachung und Statusseiten'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Workflow-Automatisierung (Fair-Code-Lizenz)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Geräte, APIs und Dienste verbinden'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Forensoftware — benötigt MongoDB oder PostgreSQL'],
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
        'stack' => 'Dieser Server führt ausschließlich Container aus und hostet diese Art von Anwendung daher nicht.',
        'stack_profile' => 'Dieser Server ist nicht dafür eingerichtet, diese Art von Anwendung zu hosten.',
        'profile' => 'Dieser Server liefert keine :profile-Anwendungen aus, daher ist diese Darstellungsart hier nicht möglich. Wählen Sie eine, die dieser Server unterstützt.',
        'database' => 'Diese Anwendung benötigt :engines, das auf diesem Server fehlt.',
        'php' => 'Auf diesem Server ist PHP nicht installiert.',
        'php_version_install' => 'Auf diesem Server gibt es keine PHP-Version, auf der :type läuft (:range). Installieren Sie zuerst PHP :version im PHP-Bereich.',
        'php_version_none' => 'Auf diesem Server gibt es keine PHP-Version, auf der :type läuft (:range), und keine dieser Versionen lässt sich aus dem Paket-Repository des Servers installieren.',
        'node' => 'Auf diesem Server ist Node.js nicht installiert.',
        'web_server' => 'Diese Anwendung ist auf :web_server-Servern noch nicht verfügbar.',
    ],

    'git_source' => [
        'account' => 'Aus einem verbundenen Konto',
        'public_url' => 'URL eines öffentlichen Repositorys einfügen',
    ],

    'fields' => [
        'memory_limit' => 'Speicherlimit',
        'cpu_limit' => 'CPU-Limit',
        'compose' => 'Compose-Datei',
        'image' => 'Image',
        'registry_id' => 'Registry',
        'container_port' => 'Container-Port',
        'docker_network' => 'Netzwerk',
        'docker_mode' => 'Art der Ausführung',
        'docker_network_new' => 'Neues Netzwerk',
        'volume_new' => 'Neues Volume',
        'volume_path' => 'Volume-Pfad',
        'database_engine' => 'Datenbank-Engine',
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
        'cpu_limit' => 'Kein Limit',
        'mailer_host' => 'smtp.beispiel.de',
        'mailer_port' => '587',
        'site_title' => 'Meine Website',
        'site_name' => 'Meine Website',
        'shop_name' => 'Mein Shop',
        'company_name' => 'Meine Firma',
        'short_name' => 'meineseite',
        'mailer_name' => 'Meine Website',
        'admin_email' => 'sie@beispiel.de',
        'company_email' => 'sie@beispiel.de',
        'mailer_email' => 'no-reply@beispiel.de',
        'mailer_username' => 'no-reply@beispiel.de',
        'timezone' => 'Europe/Berlin',
        'repository_url' => 'https://github.com/ihr-name/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'options' => [
        'docker_mode' => [
            'simple' => 'Einfach — ein Image und ein Port',
            'compose' => 'Compose-Datei — selbst schreiben',
        ],
    ],

    'help' => [
        'memory_limit_app' => 'Optional. Der maximale Speicher, den der Container dieser Anwendung nutzen darf, zum Beispiel 512m oder 2g. Eine Zahl ohne Einheit bedeutet für Docker Bytes, nicht Megabytes. Leer gelassen gilt :default. Eine mitgelieferte Datenbank hat ihre eigene Obergrenze.',
        'memory_limit_app_floor' => 'Optional. Der maximale Speicher, den der Container dieser Anwendung nutzen darf. Leer gelassen gilt :default — der gemessene Wert, den diese Anwendung braucht. Niedriger gesetzt startet sie möglicherweise nicht, und der Fehler erscheint als 502 ohne jeden Hinweis auf Speicher. Eine mitgelieferte Datenbank hat ihre eigene Obergrenze.',
        'cpu_limit_app' => 'Optional. Wie viele CPU-Kerne der Container dieser Anwendung nutzen darf — 1 für einen ganzen Kern, 0.5 für die Hälfte. Dieser Server hat :cores, und Docker startet keinen Container, der mehr verlangt. Leer gelassen gibt es kein CPU-Limit. Dieses zu überschreiten beendet nichts: der Container wartet, das Symptom ist also Langsamkeit und kein Fehler. Eine mitgelieferte Datenbank wird davon nicht begrenzt.',
        'memory_limit' => 'Optional. Der maximale Speicher, den dieser Container nutzen darf, zum Beispiel 512m oder 2g. Eine Zahl ohne Einheit bedeutet für Docker Bytes, nicht Megabytes. Leer gelassen gilt der Serverstandard :default. Das ist eine Obergrenze, keine Reservierung — es wird nichts freigehalten — und wer sie überschreitet, dessen Container wird beendet und neu gestartet.',
        'cpu_limit' => 'Optional. Wie viele CPU-Kerne dieser Container nutzen darf — 1 für einen ganzen Kern, 0.5 für die Hälfte. Dieser Server hat :cores, und Docker startet keinen Container, der mehr verlangt. Leer gelassen gibt es überhaupt kein CPU-Limit. Dieses zu überschreiten beendet nichts: der Container wartet, das Symptom ist also Langsamkeit und kein Fehler.',
        'compose' => 'Optional. Fügen Sie Ihre eigene Compose-Datei ein — alles, was Compose unterstützt, wird unterstützt: mehrere Dienste, benannte Volumes, Healthchecks. Lassen Sie sie leer, schreibt das Panel eine aus den Feldern oben. Ports müssen auf 127.0.0.1 veröffentlicht werden und Bind-Mounts innerhalb des Verzeichnisses dieser Anwendung bleiben; alles andere wird mit Begründung abgelehnt.',
        'image' => 'Das auszuführende Image, mit ausdrücklichem Tag — `nginx:1.27-alpine`. Ein Name ohne Tag zieht `latest`, was ein Deployment nicht reproduzierbar und ein Rollback bedeutungslos macht.',
        'registry_id' => 'Für ein öffentliches Image leer lassen — das ist der Normalfall. Für ein privates legen Sie auf der Docker-Seite unter „Registry-Anmeldedaten“ welche an und wählen sie dann hier. Das gilt auch für eine eingefügte Compose-Datei, denn jedes darin genannte Image kann privat sein.',
        'container_port' => 'Der Port, auf dem Ihre Anwendung innerhalb des Containers lauscht. Den Port auf dem Server selbst vergibt das Panel und richtet nginx darauf aus.',
        'docker_network' => 'Treten Sie einem Docker-Netzwerk bei, damit dieser Container und andere darin sich über ihren Namen erreichen können. Leer lassen für die Standard-Bridge von Docker, in der das nicht möglich ist. Netzwerke werden auf der Docker-Seite erstellt.',
        'docker_mode' => 'Einfach bedeutet Image und Port, und das Panel schreibt die Compose-Datei. Compose-Datei ist für alles andere — Sie schreiben sie, und das Panel erzwingt weiterhin Loopback-Veröffentlichung, ein Speicherlimit und begrenzte Logs.',
        'docker_network_new' => 'Lassen Sie die Auswahl oben leer und geben Sie hier einen Namen ein, um ein neues Netzwerk zu erstellen und diese Website damit zu verbinden. Wird abgelehnt, wenn es ein Netzwerk mit diesem Namen schon gibt — wählen Sie es dann oben aus.',
        'volume_new' => 'Erstellt ein Volume mit diesem Namen und bindet es in die Website ein, damit deren Daten einen Neuaufbau des Containers überstehen. Wird abgelehnt, wenn es ein Volume mit diesem Namen schon gibt.',
        'volume_path' => 'Wo das Volume im Container erscheint, zum Beispiel /var/lib/ghost/content. Nicht das eigene Verzeichnis der Website — ein Volume dort würde deren Dateien verbergen.',
        'table_prefix_random' => 'Leer lassen, dann wird ein zufälliges Präfix erzeugt — so bleiben die Tabellen getrennt, falls die Datenbank je geteilt wird.',
        'timezone' => 'Zeitzone der Website, z. B. America/New_York oder Europe/Berlin. Siehe Einstellungen → Allgemein → Zeitzone.',
        'table_prefix_optional' => 'Optional. Wird das Feld geleert, werden die Tabellen ganz ohne Präfix angelegt.',
        'start_command' => 'Die Einstiegsdatei, z. B. „node server.js“. Nicht „npm start“ – ein Paketmanager forkt den eigentlichen Prozess, sodass Shutdown-Signale ihn nie erreichen.',
        'app_port' => 'Leer gelassen wählt das Panel einen freien Port.',
        'rendering_type' => 'Server-Rendering führt Ihre App aus und leitet an sie weiter. Die übrigen Arten liefern PHP oder fertig gebaute Dateien direkt über den Webserver aus – schneller, und nichts muss laufen.',
        'repository_url' => 'Ein öffentliches Repository — kein Konto nötig. Muss eine https://-Adresse sein.',
        'build_command' => 'Läuft nach dem Abrufen des Codes, z. B. composer install --no-dev',
        'deploy_script' => 'Läuft nach dem Abrufen des Codes, als Ihr Site-Benutzer und mit der PHP-Version dieser Site. Leer lassen, um den Build-Befehl zu verwenden.',
        'package_manager' => 'Was Ihre Abhängigkeiten installiert und baut. Füllt den Build-Befehl unten aus – danach frei bearbeitbar.',
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
        'ensure_account' => 'Systemkonto wird angelegt',
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
        'script' => 'Deploy-Skript wird ausgeführt',
        'dependencies' => 'Abhängigkeiten werden geprüft',
        'verify' => 'Prüfen, ob die Website antwortet',
        'verify_serving' => 'Prüfen, ob die Website antwortet',
        'create_admin' => 'Administratorkonto wird angelegt',
        'schedule_cron' => 'Hintergrundaufgaben werden eingeplant',
        'worker' => 'Der Hintergrundprozess wurde beendet',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'site_type_change' => [
        'git_cannot_change' => 'Diese Site wird aus einem Git-Repository bereitgestellt, daher kann ihr Typ nicht geändert werden. Die Bildschirme für Deployments, Worker und Umgebungsdatei existieren wegen dieses Typs, und sie zu entfernen würde weder die Hintergrund-Worker anhalten noch verhindern, dass der Deploy-Webhook Pushes annimmt – es würde nur die Bildschirme entfernen, die sie verwalten.',
        'git_not_a_target' => 'Eine Anwendung kann nicht in ein Git-Deployment umgewandelt werden. Dafür braucht es ein Repository, einen Branch und ein Deploy-Skript, die das Panel verwaltet, und das lässt sich nicht aus den Dateien erzeugen, die schon auf dem Server liegen. Erstellen Sie stattdessen eine Git-Anwendung.',
        'unchanged' => 'Diese Site ist bereits auf diesen Typ gesetzt.',
        'not_suggestable' => 'Diese Site kann nicht auf diesen Typ geändert werden. Nur Anwendungen, die das Panel auf der Festplatte erkennen kann, lassen sich neu kennzeichnen – alles andere würde Funktionen beanspruchen, die die Site nicht nutzen kann.',
        'only_from_generic' => 'Nur eine Custom-PHP- oder statische Site kann als anderer Anwendungstyp gekennzeichnet werden. Diese Site ist bereits auf eine bestimmte Anwendung gesetzt, und eine Anwendung in eine andere zu verwandeln kann eine Kennzeichnung nicht leisten.',
        'no_evidence' => 'Nichts in dieser Anwendung sieht nach :type aus. Laden Sie zuerst die Anwendung hoch und führen Sie „Erkennen“ erneut aus – das Panel ändert den Typ einer Anwendung nur, wenn es sie in ihrem Verzeichnis sehen kann.',
    ],

    'failure_reason' => [

        'verify_http' => 'Nach dem Deployment hat die Anwendung mit einem Serverfehler (HTTP 5xx) statt mit einer Seite geantwortet. Prüfen Sie ihr Log — das Deployment-Log enthält den genauen Status.',
        'attached_database_engine_mismatch' => 'Diese Anwendung hat bereits eine Datenbank, die jedoch auf einer Engine läuft, die diese Anwendung nicht verwenden kann. Trennen Sie sie, oder verknüpfen Sie eine auf einer unterstützten Engine, und versuchen Sie es erneut.',
        'serving_error' => 'Die Anwendung wurde gestartet, beantwortet aber jede Anfrage mit einem Fehler. Wahrscheinlich wurden ihre Assets nicht vollständig gebaut — Einzelheiten im Anwendungsprotokoll.',
        'not_answering' => 'Die Anwendung wurde gestartet, hat aber nie auf eine Anfrage geantwortet. Im Anwendungsprotokoll steht, warum sie nicht lauscht.',
        'owner_not_created' => 'Die Anwendung wurde gestartet, aber ihr Administrator konnte nicht bestätigt werden. Die Website wurde nicht freigegeben, denn solange kein Administrator existiert, kann jeder, der sie öffnet, einen anlegen. Versuchen Sie es erneut; schlägt es weiter fehl, prüfen Sie das Anwendungsprotokoll.',
        'claim_refused' => 'Die Anwendung ist gestartet, hat aber den Administrator abgelehnt, den das Panel anlegen wollte — die Website hat damit keinen Besitzer. Meist liegt es an der E-Mail-Adresse: Chatwoot weist Wegwerf- und Platzhalter-Domains ab, test.com gehört dazu. Verwenden Sie eine echte Adresse. Die Anwendung protokolliert den Grund nicht, im Log steht dazu nichts. Löschen Sie die Website und legen Sie sie mit einer anderen Adresse neu an.',
        'app_not_ready' => 'Die Anwendung wurde gestartet, war aber nach 2 Minuten noch nicht fertig hochgefahren, daher konnte ihr Administrator nicht angelegt werden. Prüfen Sie das Anwendungsprotokoll und versuchen Sie es erneut.',
        'out_of_memory' => 'Dem Server ging bei diesem Schritt der Speicher aus und das System hat ihn beendet. Geben Sie Speicher frei oder fügen Sie Swap hinzu und versuchen Sie es erneut.',
        'no_build_tools' => 'Für diesen Schritt musste ein natives Modul kompiliert werden, und auf diesem Server ist kein Compiler installiert. Installieren Sie die Build-Tools im Einrichtungsbildschirm und versuchen Sie es erneut. Eine andere Node-Version kann ebenfalls helfen, da manche Versionen fertige Binärdateien mitbringen — welche das sind, entscheidet aber jedes Paket selbst, daher ist das allein keine verlässliche Lösung.',
        'composer_platform' => 'Composer konnte die Abhängigkeiten dieser Anwendung mit der für diese Site eingestellten PHP-Version nicht installieren. Die PHP-Version der Site oder eine der benötigten Erweiterungen erfüllt nicht, was das Projekt verlangt. Stellen Sie die Site auf eine unterstützte PHP-Version um oder installieren Sie die fehlende Erweiterung, und deployen Sie erneut.',
        'registry_auth' => 'Docker konnte dieses Image nicht laden, die Registry hat es abgelehnt. Entweder ist der Image-Name oder das Tag falsch, oder das Image ist privat — Docker meldet beides gleich, prüfen Sie daher zuerst die Referenz. Ist das Image privat: Das Panel kann sich noch nicht an einer Registry anmelden und daher nur öffentlich verfügbare Images ausführen.',
        'registry_credentials_rejected' => 'Die Registry hat die Anmeldedaten abgelehnt, mit denen diese Site Images lädt. Das Token ist höchstwahrscheinlich abgelaufen oder widerrufen — erneuern Sie es auf der Docker-Seite und deployen Sie erneut. Die Image-Referenz selbst ist in Ordnung: Die Registry hat geantwortet und nur diesen Benutzernamen und dieses Token nicht akzeptiert.',
        'container_restarting' => 'Der Container startet und stoppt immer wieder, daher kann die Site nichts ausliefern. Sein eigenes Log ist der richtige Ort — meist ein Befehl oder Entrypoint, der sofort endet, eine fehlende Umgebungsvariable oder eine Konfigurationsdatei, die das Image nicht lesen konnte.',
        'script_git_auth' => 'Ihr Deploy-Skript führt einen Git-Befehl aus (meist git pull), der sich am Repository anmelden muss. Das Skript hat keine Zugangsdaten und scheitert daher bei einem privaten Repository. Sie brauchen ihn nicht: Das Panel lädt den neuesten Code mit dem verbundenen Konto herunter, bevor Ihr Skript läuft. Entfernen Sie diese Zeile aus dem Deploy-Skript und deployen Sie erneut.',
        'script_php_missing' => 'Ihr Deploy-Skript verwendet eine {PHPxx}-Variable für eine PHP-Version, die auf diesem Server nicht installiert ist. Installieren Sie diese Version auf der PHP-Seite oder verwenden Sie {php} für die eigene Version der Website, und deployen Sie erneut.',
        'composer_dependencies_missing' => 'Dieses Projekt benötigt Composer-Abhängigkeiten, es wurden aber keine installiert. Der Anwendung fehlt daher vendor/autoload.php und jede Anfrage schlägt fehl. Fügen Sie dem Deploy-Skript einen Schritt mit composer install hinzu und deployen Sie erneut.',
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

    'supervisor_installing' => 'Supervisor wird installiert, unter dem Worker laufen. Das dauert einen Moment — legen Sie den Worker danach erneut an.',

    'placeholder_page' => [
        'lede' => 'Diese Seite ist bereit und wird ausgeliefert. Ersetzen Sie sie durch Ihre eigene — bis dahin sieht sie jeder Besucher.',
        'php_running' => 'PHP läuft auf dieser Seite',
        'step_files_title' => 'Dateien hochladen',
        'step_files_body' => 'Nutzen Sie den Dateimanager des Panels oder verbinden Sie sich per SFTP mit dem Systembenutzer dieser Seite.',
        'step_deploy_title' => 'Oder aus Git bereitstellen',
        'step_deploy_body' => 'Verbinden Sie die Seite mit einem Repository, und das Panel baut sie bei jedem Push neu.',
        'foot' => 'Platzhalterseite, erstellt vom Control Panel.',
    ],

    'supervisor_mode' => [
        'systemd' => 'systemd-Unit',
        'pm2' => 'PM2 (übernommen)',
    ],

    'disabled_page' => [
        'title' => 'Seite nicht verfügbar',
        'heading' => 'Diese Seite ist vorübergehend nicht verfügbar',
        'lede' => 'Sie wurde von ihrem Betreiber offline genommen. Bitte versuchen Sie es später erneut.',
        'foot' => 'Ausgeliefert vom Control Panel.',
    ],

    // A deploy that failed after its checkout left the new code live.
    // See Application::codeOnDisk().
    'code_on_disk' => [
        'incomplete' => 'Das letzte Deployment ist fehlgeschlagen, nachdem der neue Code bereits eingespielt war. Die Anwendung läuft daher mit Commit :commit, der nicht vollständig bereitgestellt ist. Beheben Sie das Problem und deployen Sie erneut.',
    ],

    // A delivery for a site whose deploy-on-push is switched off. See
    // ApplicationWebhookController::receive().
    'webhook_delivery' => [
        'disabled' => 'Deploy bei Push ist für diese Anwendung im Panel ausgeschaltet, deshalb wurde nichts bereitgestellt. Schalten Sie es im Panel wieder ein oder löschen Sie diesen Webhook.',
    ],

    // Why deploy-on-push still needs the webhook added by hand. See
    // WebhookRegistrar.
    'webhook_registration' => [
        'no_account' => 'Diese Anwendung wird von einer öffentlichen URL bereitgestellt, nicht über ein verbundenes Git-Konto, daher kann das Panel den Webhook nicht für Sie anlegen. Legen Sie ihn in den Repository-Einstellungen mit der URL und dem Secret unten an.',
        'signing_token' => 'GitLab erstellt Signatur-Tokens selbst, daher kann das Panel diesen Webhook nicht für Sie anlegen. Legen Sie ihn in den Webhook-Einstellungen des Repositorys mit der URL unten und Ihrem Signatur-Token an.',
        'not_public' => 'Die Adresse des Panels ist aus dem Internet nicht erreichbar, daher könnten GitHub, GitLab oder Bitbucket nichts zustellen. Geben Sie dem Panel eine öffentliche Adresse oder legen Sie den Webhook danach von Hand an.',
        'provider_refused' => 'Der Git-Anbieter hat dem Panel nicht erlaubt, den Webhook anzulegen. Wahrscheinlich fehlt dem verbundenen Token die Berechtigung, Webhooks in diesem Repository zu verwalten. Legen Sie ihn von Hand mit der URL und dem Secret unten an oder verbinden Sie das Konto mit dieser Berechtigung neu.',
        'removal_refused' => 'Deploy bei Push ist aus, aber der Git-Anbieter hat dem Panel nicht erlaubt, den angelegten Webhook zu entfernen. Wahrscheinlich fehlt dem verbundenen Token die Berechtigung, Webhooks zu löschen. Pushes werden weiter gesendet und abgewiesen, bis Sie den Webhook in den Einstellungen des Repositorys löschen.',
    ],
];
