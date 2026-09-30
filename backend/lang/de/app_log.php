<?php

return [
    'sources' => [
        'access' => 'Zugriffsprotokoll',
        'error' => 'Fehlerprotokoll',
        'application' => 'Anwendungsausgabe',
        'application_error' => 'Anwendungsfehler',
        'waf_detect' => 'Firewall-Erkennungen',
    ],

    'errors' => [
        'unknown_source' => 'Dieses Protokoll gibt es für diese Anwendung nicht.',
        'clear_shared' => 'Unter OpenLiteSpeed sind die Firewall-Erkennungen Teil des Zugriffsprotokolls dieser Website. Leeren Sie stattdessen das Zugriffsprotokoll.',
    ],
];
