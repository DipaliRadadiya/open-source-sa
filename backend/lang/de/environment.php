<?php

return [
    'frameworks' => [
        'laravel' => 'Laravel',
        'craft' => 'Craft CMS',
        'statamic' => 'Statamic',
        'nextjs' => 'Next.js',
        'nuxt' => 'Nuxt',
        'node' => 'Node.js',
        'unknown' => 'Unbekannt',
    ],

    'checks' => [
        'app_debug_on' => [
            'title' => 'Der Debug-Modus ist aktiv',
            'detail' => 'Wer einen Fehler auslöst, sieht die vollständige Fehlerausgabe samt Datenbank-Zugangsdaten. Setzen Sie APP_DEBUG auf einer Live-Seite auf false.',
        ],
        'app_env_local' => [
            'title' => 'Die Seite läuft in einer Entwicklungsumgebung',
            'detail' => 'APP_ENV hat einen Entwicklungswert, was Fehlerausgabe, Caching und Mailversand verändert. Setzen Sie ihn auf einer Live-Seite auf production.',
        ],
        'app_key_missing' => [
            'title' => 'APP_KEY fehlt',
            'detail' => 'Ohne ihn kann die Anwendung Sitzungen und Cookies nicht entschlüsseln und startet meist gar nicht.',
        ],
        'next_public_secret' => [
            'title' => '":key" wird an jeden Besucher ausgeliefert',
            'detail' => 'Alles mit dem Präfix NEXT_PUBLIC_ landet im Browser-Bundle. Ein Geheimnis hier ist bereits öffentlich.',
        ],
        'duplicate_key' => [
            'title' => '":key" ist mehrfach gesetzt',
            'detail' => 'Nur der letzte Wert gilt, der angezeigte ist also womöglich nicht der wirksame. Zeile :line.',
        ],
        'syntax_no_equals' => [
            'title' => 'Zeile :line enthält kein "="',
            'detail' => 'Jede Zeile muss SCHLÜSSEL=Wert, ein Kommentar oder leer sein.',
        ],
        'syntax_bad_key' => [
            'title' => 'Zeile :line ist keine gültige Variable',
            'detail' => 'Ein Name muss mit einem Buchstaben oder Unterstrich beginnen und darf nur Buchstaben, Ziffern und Unterstriche enthalten.',
        ],
        'syntax_unbalanced_quote' => [
            'title' => '":key" hat ein nicht geschlossenes Anführungszeichen',
            'detail' => 'Der Wert in Zeile :line öffnet ein Anführungszeichen, das nie geschlossen wird, und läuft in die folgenden Zeilen.',
        ],
        'syntax_export' => [
            'title' => '":key" verwendet "export"',
            'detail' => 'Diese Anwendung liest ihre Umgebung über systemd, das export ablehnt und nicht startet. Entfernen Sie es. Zeile :line.',
        ],
    ],

];
