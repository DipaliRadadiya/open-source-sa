<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Wenig Traffic',
            'description' => 'Ein paar Worker. Passend für die meisten kleinen Seiten und am schonendsten für einen kleinen Server.',
        ],
        'balanced' => [
            'title' => 'Ausgewogen',
            'description' => 'Bewältigt normalen Traffic, ohne selten benötigten Speicher zu reservieren.',
        ],
        'high' => [
            'title' => 'Viel Traffic',
            'description' => 'Hält Worker bereit. Für wirklich stark besuchte Seiten — reserviert Speicher, ob genutzt oder nicht.',
        ],
    ],

    'disable_functions_presets' => [
        'safe' => [
            'title' => 'Empfohlen',
            'description' => 'Blockiert jeden Weg, ein Programm aus PHP heraus auszuführen — genau das, was eine Web-Shell braucht und eine normale Website fast nie tut.',
        ],
        'strict' => [
            'title' => 'Streng',
            'description' => 'Ergänzt die empfohlene Liste um Prozess-, Benutzer- und Socket-Abfragen. Entspricht der üblichen Härtung im Shared Hosting und kann Websites beeinträchtigen, die die Sockets-Erweiterung nutzen.',
        ],
    ],

    'errors' => [
        'missing_account' => 'Das Linux-Konto, unter dem diese Seite läuft, existiert auf dem Server nicht, daher wurde kein PHP-Pool geschrieben. PHP-FPM startet mit einem Pool, dessen Benutzer sich nicht auflösen lässt, überhaupt nicht.',
        'version_not_installed' => 'PHP :version ist auf diesem Server nicht installiert. Installieren Sie es zuerst und wählen Sie es dann hier aus.',
        'version_busy' => 'PHP :version wird noch installiert oder entfernt. Warten Sie, bis das abgeschlossen ist, und ändern Sie dann die Version.',
        'directive_invalid' => '„:line" ist keine PHP-Einstellung. Eine Einstellung pro Zeile, z. B. display_errors = Off.',
        'directive_managed' => '„:line" setzt :name, das vom Panel verwaltet wird und hier nicht gesetzt werden kann. Hat diese Seite ein Feld dafür, nutzen Sie es.',
        'directive_extension' => '„:line" lädt eine PHP-Erweiterung. Nutzen Sie dafür die Seite PHP-Erweiterungen.',
        'unsupported_stack' => 'Dieser Server nutzt OpenLiteSpeed, das keine PHP-FPM-Pools verwendet.',
        'not_php_site' => 'Diese Website liefert kein PHP aus, daher gibt es keinen Pool, den sie bekommen könnte. Ändern Sie zuerst, wie sie ausgeliefert wird.',
        'already_isolated' => 'Diese Seite hat bereits einen eigenen PHP-Pool.',
        'not_isolated' => 'Diese Seite ist nicht isoliert.',
        'needs_isolation' => 'Diese Website hat noch keinen eigenen PHP-Pool, daher könnten diese Limits nicht durchgesetzt werden. Weise ihr zuerst einen zu und speichere dann.',
        'basedir_absolute' => 'Jeder Pfad muss absolut sein und mit / beginnen. „:path“ ist das nicht.',
        'basedir_root' => '„/“ erlaubt das gesamte Dateisystem – open_basedir wäre eingeschaltet, würde aber nichts durchsetzen. Schalte die Einstellung stattdessen aus.',
        'basedir_traversal' => '„:path“ ist nicht erlaubt – Pfade dürfen kein „..“ enthalten.',
        'write_failed' => 'Die Pool-Konfiguration konnte nicht geschrieben werden. Es wurde nichts geändert.',
        'config_test_failed' => 'PHP-FPM hat die Konfiguration abgelehnt, sie wurde nicht angewendet und nichts neu geladen. Die Seite wird weiterhin genau wie zuvor ausgeliefert.',
        'reload_failed' => 'PHP-FPM ließ sich nicht neu laden, daher wurde die vorherige Konfiguration wiederhergestellt.',
        'no_sections' => 'Abschnittsüberschriften sind hier nicht erlaubt — sie würden einen zweiten Pool in diesem starten.',
        'function_list' => 'Dies muss eine kommagetrennte Liste von Funktionsnamen sein.',
        'memory_unlimited' => 'Unbegrenzter Speicher (-1) ist nicht erlaubt: Eine Website könnte den gesamten Speicher des Servers belegen und alle anderen Websites mit lahmlegen. Gib ein Limit ein, z. B. 512M.',
        'memory_over_ram' => 'Das ist mehr Speicher, als der Server hat (:ram). Gib ein kleineres Limit ein.',
        'post_below_upload' => 'Die maximale POST-Größe (:post) muss mindestens so groß wie die maximale Upload-Größe (:upload) sein. Ein Upload wird innerhalb der Anfrage gesendet, sonst schlagen größere Uploads ohne Fehlermeldung fehl.',
        'prepend_outside_site' => 'Die Datei muss im Ordner dieser Website liegen (:root/…).',
    ],
];
