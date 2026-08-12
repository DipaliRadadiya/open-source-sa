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
        'unsupported_stack' => 'Dieser Server nutzt OpenLiteSpeed, das keine PHP-FPM-Pools verwendet.',
        'already_isolated' => 'Diese Seite hat bereits einen eigenen PHP-Pool.',
        'not_isolated' => 'Diese Seite ist nicht isoliert.',
        'needs_isolation' => 'Diese Website hat noch keinen eigenen PHP-Pool, daher könnten diese Limits nicht durchgesetzt werden. Weise ihr zuerst einen zu und speichere dann.',
        'write_failed' => 'Die Pool-Konfiguration konnte nicht geschrieben werden. Es wurde nichts geändert.',
        'config_test_failed' => 'PHP-FPM hat die Konfiguration abgelehnt, sie wurde nicht angewendet und nichts neu geladen. Die Seite wird weiterhin genau wie zuvor ausgeliefert.',
        'reload_failed' => 'PHP-FPM ließ sich nicht neu laden, daher wurde die vorherige Konfiguration wiederhergestellt.',
        'no_sections' => 'Abschnittsüberschriften sind hier nicht erlaubt — sie würden einen zweiten Pool in diesem starten.',
        'function_list' => 'Dies muss eine kommagetrennte Liste von Funktionsnamen sein.',
    ],
];
