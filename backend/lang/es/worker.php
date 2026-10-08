<?php

return [
    'kinds' => [
        'queue' => 'Worker de cola',
        'horizon' => 'Horizon',
        'custom' => 'Personalizado',
    ],

    'states' => [
        'running' => 'En ejecución',
        'degraded' => 'Parcialmente en ejecución',
        'stopped' => 'Detenido',
    ],

    'presets' => [
        'queue' => [
            'title' => 'Worker de cola',
            'description' => 'Procesa los trabajos en cola. La opción habitual.',
        ],
        'horizon' => [
            'title' => 'Horizon',
            'description' => 'Supervisa sus propios workers, con panel. Úsalo en lugar de un worker de cola, no junto a él.',
        ],
        'custom' => [
            'title' => 'Comando personalizado',
            'description' => 'Cualquier comando de larga duración, mantenido en ejecución.',
        ],
    ],

    'checks' => [
        'cache_driver_array' => [
            'title' => 'Los workers no se pueden reiniciar automáticamente',
            'detail' => 'Esta aplicación usa el driver de caché "array", que no persiste entre procesos. Laravel reinicia los workers dejando una marca en la caché, así que el comando tendrá éxito y no pasará nada: tras un despliegue tus workers seguirán con el código antiguo. Usa redis, database o file.',
        ],
    ],

    'errors' => [
        'queue_conflict' => 'Esta aplicación ya tiene el otro tipo de worker de cola. Horizon supervisa sus propios workers, así que ejecutar ambos hace que cada trabajo se procese dos veces.',
        'extra_config_user' => 'Indique la cuenta en «Ejecutar como», no en la configuración adicional.',
        'extra_config_environment' => 'El panel ya define el entorno de este proceso. Quite la línea environment= de la configuración adicional.',
        'did_not_start' => 'El proceso no siguió en ejecución, así que no se añadió. Revise el comando. Referencia: :reference',
        'did_not_start_said' => 'El proceso no siguió en ejecución, así que no se añadió. Mostró: «:output» (referencia :reference)',
        'did_not_start_field' => 'Este comando termina enseguida en lugar de seguir ejecutándose.',
        'user_not_allowed' => 'Los workers solo pueden ejecutarse con la cuenta del sitio (:user). Solo el administrador del panel puede elegir otra cuenta.',
        'directory_outside_home' => 'El directorio debe estar dentro de la carpeta personal del sitio (:home).',
        'log_outside_logs' => 'El archivo de registro debe estar en la carpeta de registros del sitio (:path).',
        'extra_config_key' => 'La configuración adicional no puede definir «:key». Solo el administrador del panel puede cambiarlo.',
    ],
];
