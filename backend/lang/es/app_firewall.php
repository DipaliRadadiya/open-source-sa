<?php

return [

    'waf' => [
        'modes' => [
            'detect' => 'Solo observar, no bloquear',
            'enforce' => 'Bloquear de verdad',
        ],
        'categories' => [
            'query_string' => 'Términos de búsqueda maliciosos',
            'request_uri' => 'Direcciones web maliciosas',
            'user_agent' => 'Visitantes maliciosos',
            'referrer' => 'Enlaces maliciosos',
            'cookie' => 'Cookies maliciosas',
            'method' => 'Tipos de solicitud maliciosos',
        ],
    ],

];
