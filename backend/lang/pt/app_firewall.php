<?php

return [

    'waf' => [
        'modes' => [
            'detect' => 'Apenas observar, não bloquear',
            'enforce' => 'Bloquear de facto',
        ],
        'categories' => [
            'query_string' => 'Termos de pesquisa maliciosos',
            'request_uri' => 'Endereços web maliciosos',
            'user_agent' => 'Visitantes maliciosos',
            'referrer' => 'Ligações maliciosas',
            'cookie' => 'Cookies maliciosos',
            'method' => 'Tipos de pedido maliciosos',
        ],
    ],

];
