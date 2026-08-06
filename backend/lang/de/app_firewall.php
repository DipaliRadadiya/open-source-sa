<?php

return [

    'waf' => [
        'modes' => [
            'detect' => 'Nur beobachten, nicht blockieren',
            'enforce' => 'Tatsächlich blockieren',
        ],
        'categories' => [
            'query_string' => 'Schlechte Suchbegriffe',
            'request_uri' => 'Schlechte Webadressen',
            'user_agent' => 'Schlechte Besucher',
            'referrer' => 'Schlechte Links',
            'cookie' => 'Schlechte Cookies',
            'method' => 'Schlechte Anfragetypen',
        ],

        'category_descriptions' => [
            'query_string' => 'Blockiert Anfragen, deren Suchbegriffe SQL-, Skript- oder Dateipfad-Tricks enthalten — der Query-String nach dem ? in einer Webadresse.',
            'request_uri' => 'Blockiert Anfragen an Pfade, die zum Aufspüren von Installern, Backups, Konfigurationsdateien und bekannten Exploits dienen.',
            'user_agent' => 'Blockiert Anfragen von Scannern, Scrapern und Exploit-Werkzeugen, die sich im User-Agent-Header zu erkennen geben.',
            'referrer' => 'Blockiert Anfragen von Links, die Injection-Payloads in der Herkunftsadresse tragen.',
            'cookie' => 'Blockiert Anfragen, deren Cookies Code oder Injection-Payloads statt gewöhnlicher Werte enthalten.',
            'method' => 'Blockiert ungewöhnliche HTTP-Methoden wie TRACE und DEBUG, die ein normaler Besucher nie sendet.',
        ],
    ],

];
