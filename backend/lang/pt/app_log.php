<?php

return [
    'sources' => [
        'container' => 'Contentor',
        'access' => 'Registo de acessos',
        'error' => 'Registo de erros',
        'application' => 'Saída da aplicação',
        'application_error' => 'Erros da aplicação',
        'waf_detect' => 'Deteções da firewall',
    ],

    'errors' => [

        'not_downloadable' => 'Este registo não é um ficheiro — a saída de um contentor é guardada pelo Docker. Leia-a no ecrã.',
        'unknown_source' => 'Esse registo não existe para esta aplicação.',
        'clear_shared' => 'No OpenLiteSpeed, as deteções da firewall fazem parte do registo de acesso do site. Limpe antes o registo de acesso.',
    ],
];
