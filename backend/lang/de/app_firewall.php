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
    ],

];
