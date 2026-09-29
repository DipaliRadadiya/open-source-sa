<?php

return [
    'registry_status' => [
        'connected' => 'Ligado',
        'never_tested' => 'Ainda não testado',
        'failed' => 'O último teste falhou',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'O registo recusou este nome de utilizador e este token.',
        'unreachable' => 'Não foi possível contactar o registo a partir deste servidor. Verifique o endereço e se está acessível por HTTPS.',
        'unknown' => 'O registo recusou a ligação e não disse porquê. A resposta do Docker está no registo de operações do servidor.',
    ],
];
