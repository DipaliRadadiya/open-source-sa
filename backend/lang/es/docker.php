<?php

return [
    'registry_status' => [
        'connected' => 'Conectado',
        'never_tested' => 'Sin probar todavía',
        'failed' => 'La última prueba falló',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'El registro rechazó este usuario y este token.',
        'unreachable' => 'No se pudo contactar con el registro desde este servidor. Compruebe la dirección y si es accesible por HTTPS.',
        'unknown' => 'El registro rechazó la conexión sin indicar el motivo. La respuesta de Docker está en el registro de operaciones del servidor.',
    ],
];
