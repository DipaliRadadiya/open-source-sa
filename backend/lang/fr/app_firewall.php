<?php

return [

    'waf' => [
        'modes' => [
            'detect' => 'Observer seulement, ne pas bloquer',
            'enforce' => 'Bloquer réellement',
        ],
        'categories' => [
            'query_string' => 'Termes de recherche suspects',
            'request_uri' => 'Adresses web suspectes',
            'user_agent' => 'Visiteurs suspects',
            'referrer' => 'Liens suspects',
            'cookie' => 'Cookies suspects',
            'method' => 'Types de requête suspects',
        ],
    ],

];
