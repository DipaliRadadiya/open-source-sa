<?php

return [

    'installing' => 'Instalando :component',

    'detail' => [
        'cache_in_use' => 'en uso para la caché del panel',
    ],

    'components' => [
        'database' => [
            'title' => 'Base de datos',
            'description' => 'Necesaria antes de instalar WordPress o cualquier aplicación que almacene datos.',
        ],
        'php' => [
            'title' => 'PHP',
            'description' => 'Añada otra versión cuando un sitio la necesite.',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'Gestionado con fnm, para que cada sitio fije su propia versión.',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'Se usa para la caché del panel. Sin él, el panel recurre a la base de datos: funciona, pero es más lento.',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'Bloquea intentos de acceso repetidos contra SSH y sus sitios.',
        ],
    ],

];
