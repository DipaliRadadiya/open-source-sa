<?php

return [
    'kinds' => [
        'queue' => 'Queue-Worker',
        'horizon' => 'Horizon',
        'custom' => 'Benutzerdefiniert',
    ],

    'states' => [
        'running' => 'Läuft',
        'degraded' => 'Teilweise aktiv',
        'stopped' => 'Gestoppt',
    ],

    'presets' => [
        'queue' => [
            'title' => 'Queue-Worker',
            'description' => 'Verarbeitet Jobs aus der Warteschlange. Die übliche Wahl.',
        ],
        'horizon' => [
            'title' => 'Horizon',
            'description' => 'Überwacht eigene Queue-Worker, mit Dashboard. Statt eines Queue-Workers verwenden, nicht zusätzlich.',
        ],
        'custom' => [
            'title' => 'Eigener Befehl',
            'description' => 'Ein beliebiger Dauerprozess, am Leben gehalten.',
        ],
    ],

    'checks' => [
        'cache_driver_array' => [
            'title' => 'Worker können nicht automatisch neu gestartet werden',
            'detail' => 'Diese Anwendung nutzt den Cache-Treiber "array", der nicht zwischen Prozessen erhalten bleibt. Laravel startet Worker über eine Markierung im Cache neu — der Befehl meldet Erfolg und es passiert nichts, sodass Ihre Worker nach einem Deploy weiter alten Code ausführen. Verwenden Sie redis, database oder file.',
        ],
    ],

    'errors' => [
        'queue_conflict' => 'Diese Anwendung hat bereits die andere Art von Queue-Worker. Horizon überwacht eigene Worker, sodass bei beidem jeder Job doppelt verarbeitet wird.',
    ],
];
