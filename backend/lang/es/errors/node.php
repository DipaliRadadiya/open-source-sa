<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version no está instalado.',
    'version_in_use' => 'Node :version lo usan :apps. Cambie primero esos sitios.',
    'version_is_default' => 'Esta es la versión predeterminada. Elija otra primero.',
    'npm_target_unknown' => 'No se pudo acceder a la lista de versiones de npm, así que no hay forma de saber qué npm puede ejecutar esta versión de Node. Inténtalo de nuevo cuando el servidor tenga acceso a internet o ejecuta `php artisan runtimes:refresh-npm`.',
];
