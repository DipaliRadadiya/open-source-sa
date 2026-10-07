<?php

return [
    'status' => [
        'pending' => 'En cola',
        'running' => 'Clonando',
        'completed' => 'Completado',
        'failed' => 'Fallido',
    ],

    'current_step' => [
        'provisioning' => 'Creando el sitio',
        'copying_files' => 'Copiando archivos',
        'cloning_database' => 'Clonando la base de datos',
        'starting_process' => 'Iniciando la aplicación',
    ],

    'cloning_errors' => [
        'crashed' => 'La clonación se detuvo inesperadamente.',
        'failed' => 'La clonación falló. Indique la referencia al soporte.',
        'abandoned' => 'Esta clonación nunca empezó y se liberó. Iníciela de nuevo.',
        'copy_failed' => 'La copia de la aplicación falló en el servidor. Indique la referencia al soporte.',
        'setup_failed' => 'No se pudo preparar la copia en el servidor. Indique la referencia al soporte.',
    ],

    'errors' => [
        'already_running' => 'Esta aplicación ya se está clonando. Espere a que termine y luego inicie otra.',
    ],
];
