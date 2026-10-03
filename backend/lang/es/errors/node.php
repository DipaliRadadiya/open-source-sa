<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version no está instalado.',
    'version_in_use' => 'Node :version lo usan :apps. Cambie primero esos sitios.',
    'version_unknown' => 'Node.js :version no existe. Elige una versión de la lista.',
    'version_is_default' => 'Esta es la versión predeterminada. Elija otra primero.',
    'version_runs_panel' => 'El propio panel se ejecuta con Node :version. No se puede eliminar.',
    'npm_target_unknown' => 'No se pudo acceder a la lista de versiones de npm, así que no hay forma de saber qué npm puede ejecutar esta versión de Node. Inténtalo de nuevo cuando el servidor tenga acceso a internet o ejecuta `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Este servidor aloja contenedores y no ejecuta aplicaciones en el propio host, así que no hay versiones de Node.js que gestionar. Un contenedor trae su propio entorno de ejecución.',
];
