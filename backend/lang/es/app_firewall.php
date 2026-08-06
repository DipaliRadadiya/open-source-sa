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

        'category_descriptions' => [
            'query_string' => 'Bloquea solicitudes cuyos términos de búsqueda contienen trucos de SQL, scripts o rutas de archivo: la cadena de consulta después del ? en una dirección web.',
            'request_uri' => 'Bloquea solicitudes de rutas usadas para buscar instaladores, copias de seguridad, archivos de configuración y exploits conocidos.',
            'user_agent' => 'Bloquea solicitudes de escáneres, rastreadores y herramientas de exploits que se identifican en la cabecera User-Agent.',
            'referrer' => 'Bloquea solicitudes que llegan desde enlaces con cargas de inyección en la dirección de origen.',
            'cookie' => 'Bloquea solicitudes cuyas cookies contienen código o cargas de inyección en lugar de valores normales.',
            'method' => 'Bloquea métodos HTTP inusuales como TRACE y DEBUG que un visitante normal nunca envía.',
        ],
    ],

];
