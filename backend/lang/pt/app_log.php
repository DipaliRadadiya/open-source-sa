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
        'range_unsupported' => 'Um número de linha só se aplica a um arquivo de log. O diário do sistema e a saída do contêiner não têm números de linha fixos — leia as últimas linhas em vez disso.',
        'fields_unsupported' => 'Os filtros de IP, status, método e URL só se aplicam ao log de acesso. Use a busca de texto para este log.',

        'not_downloadable' => 'Este registo não é um ficheiro — a saída de um contentor é guardada pelo Docker. Leia-a no ecrã.',
        'unknown_source' => 'Esse registo não existe para esta aplicação.',
        'clear_shared' => 'No OpenLiteSpeed, as deteções da firewall fazem parte do registo de acesso da aplicação. Limpe antes o registo de acesso.',
    ],
];
