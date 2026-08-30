<?php

return [
    'frameworks' => [
        'laravel' => 'Laravel',
        'craft' => 'Craft CMS',
        'statamic' => 'Statamic',
        'nextjs' => 'Next.js',
        'nuxt' => 'Nuxt',
        'node' => 'Node.js',
        'unknown' => 'Desconocido',
    ],

    'checks' => [
        'file_exposed' => [
            'title' => 'El archivo de entorno es accesible desde la web',
            'detail' => 'Este sitio sirve el directorio donde está su .env, así que el archivo queda a una URL de distancia y Apache no bloquea los archivos ocultos por nombre. Define una raíz web (una app Laravel sirve public/), lo que sitúa el directorio servido por debajo del archivo.',
        ],
        'app_debug_on' => [
            'title' => 'El modo de depuración está activado',
            'detail' => 'Quien provoque un error verá la traza completa, incluidas las credenciales de la base de datos. Pon APP_DEBUG en false en un sitio en producción.',
        ],
        'app_env_local' => [
            'title' => 'El sitio se ejecuta en un entorno de desarrollo',
            'detail' => 'APP_ENV tiene un valor de desarrollo, lo que cambia el comportamiento de errores, caché y correo. Ponlo en production en un sitio en producción.',
        ],
        'app_key_missing' => [
            'title' => 'Falta APP_KEY',
            'detail' => 'Sin ella la aplicación no puede descifrar sesiones ni cookies, y normalmente no arrancará.',
        ],
        'next_public_secret' => [
            'title' => '":key" se envía a todos los visitantes',
            'detail' => 'Todo lo que empieza por NEXT_PUBLIC_ se incluye en el paquete del navegador. Un secreto aquí ya es público.',
        ],
        'duplicate_key' => [
            'title' => '":key" está definida más de una vez',
            'detail' => 'Solo la última tiene efecto, así que el valor que ves puede no ser el que se usa. Línea :line.',
        ],
        'syntax_no_equals' => [
            'title' => 'La línea :line no tiene "="',
            'detail' => 'Cada línea debe ser CLAVE=valor, un comentario o estar vacía.',
        ],
        'syntax_bad_key' => [
            'title' => 'La línea :line no es una variable válida',
            'detail' => 'Un nombre debe empezar por letra o guion bajo y contener solo letras, números y guiones bajos.',
        ],
        'syntax_unbalanced_quote' => [
            'title' => '":key" tiene una comilla sin cerrar',
            'detail' => 'El valor de la línea :line abre una comilla que nunca cierra, por lo que continuará en las líneas siguientes.',
        ],
        'syntax_export' => [
            'title' => '":key" usa "export"',
            'detail' => 'Esta aplicación lee su entorno mediante systemd, que rechaza la palabra export y no arrancará. Elimínala. Línea :line.',
        ],
    ],

];
