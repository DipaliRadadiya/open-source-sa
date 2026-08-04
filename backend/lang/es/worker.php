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
    ],
];
