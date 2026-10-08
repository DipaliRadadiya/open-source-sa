<?php

return [
    'sources' => [
        'container' => 'Container',
        'access' => 'Zugriffsprotokoll',
        'error' => 'Fehlerprotokoll',
        'application' => 'Anwendungsausgabe',
        'application_error' => 'Anwendungsfehler',
        'waf_detect' => 'Firewall-Erkennungen',
    ],

    'errors' => [

        'not_downloadable' => 'Dieses Log ist keine Datei — die Ausgabe eines Containers verwaltet Docker. Lesen Sie es auf dem Bildschirm.',
        'unknown_source' => 'Dieses Protokoll gibt es für diese Anwendung nicht.',
        'clear_shared' => 'Unter OpenLiteSpeed sind die Firewall-Erkennungen Teil des Zugriffsprotokolls dieser Website. Leeren Sie stattdessen das Zugriffsprotokoll.',
    ],
];
