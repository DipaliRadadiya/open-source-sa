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

    'errors' => [
        'unsupported_stack' => 'Dieser Server nutzt OpenLiteSpeed, das keine PHP-FPM-Pools verwendet.',
        'already_isolated' => 'Diese Seite hat bereits einen eigenen PHP-Pool.',
        'not_isolated' => 'Diese Seite ist nicht isoliert.',
        'write_failed' => 'Die Pool-Konfiguration konnte nicht geschrieben werden. Es wurde nichts geändert.',
        'config_test_failed' => 'PHP-FPM hat die Konfiguration abgelehnt, sie wurde nicht angewendet und nichts neu geladen. Die Seite wird weiterhin genau wie zuvor ausgeliefert.',
        'reload_failed' => 'PHP-FPM ließ sich nicht neu laden, daher wurde die vorherige Konfiguration wiederhergestellt.',
        'no_sections' => 'Abschnittsüberschriften sind hier nicht erlaubt — sie würden einen zweiten Pool in diesem starten.',
        'function_list' => 'Dies muss eine kommagetrennte Liste von Funktionsnamen sein.',
    ],
];
