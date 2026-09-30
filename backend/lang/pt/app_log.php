<?php

return [
    'sources' => [
        'access' => 'Registo de acessos',
        'error' => 'Registo de erros',
        'application' => 'Saída da aplicação',
        'application_error' => 'Erros da aplicação',
        'waf_detect' => 'Deteções da firewall',
    ],

    'errors' => [
        'unknown_source' => 'Esse registo não existe para esta aplicação.',
        'clear_shared' => 'No OpenLiteSpeed, as deteções da firewall fazem parte do registo de acesso do site. Limpe antes o registo de acesso.',
    ],
];
