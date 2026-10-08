<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version no está instalado.',
    'version_in_use' => 'Node :version lo usan :apps. Cambia primero esos sitios a otra versión de Node (ajuste Node.js de cada sitio) o elimínalos.',
    'version_unknown' => 'Node.js :version no existe. Elige una versión de la lista.',
    'version_is_default' => 'Esta es la versión predeterminada. Elija otra primero.',
    'version_runs_panel' => 'El propio panel se ejecuta con Node :version. No se puede eliminar.',
    'npm_target_unknown' => 'No se pudo acceder a la lista de versiones de npm, así que no hay forma de saber qué npm puede ejecutar esta versión de Node. Inténtalo de nuevo cuando el servidor tenga acceso a internet o ejecuta `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Este servidor aloja contenedores y no ejecuta aplicaciones en el propio host, así que no hay versiones de Node.js que gestionar. Un contenedor trae su propio entorno de ejecución.',
    'change_not_node' => 'Solo un sitio que funciona con Node.js tiene una versión de Node que cambiar.',
    'change_legacy_pm2' => 'Este sitio aún funciona con PM2 del panel anterior. Pásalo primero al gestor de procesos del panel y luego cambia su versión de Node.',
    'change_in_progress' => 'Este sitio ya se está cambiando a Node :version. Espera a que termine.',
    'change_use_endpoint' => 'La versión de Node se cambia con su propia acción (PUT /applications/{application}/node-version), que reinicia el sitio y lo comprueba.',
    'change_failed' => [
        'did_not_start' => 'El sitio no arrancó con Node :target, así que se volvió a Node :current y funciona como antes. Revisa el registro del sitio para ver el motivo.',
        'rollback_failed' => 'El sitio no arrancó con Node :target y tampoco funcionó volver a Node :current. Revisa el registro del sitio y reinícialo.',
        'unit_write' => 'No se pudo actualizar el servicio del sitio para Node :target. No cambió nada; sigue en Node :current.',
        'install_pm2' => 'No se pudo instalar PM2 en Node :target, que este sitio necesita para varios procesos. No cambió nada; sigue en Node :current.',
        'worker' => 'El cambio a Node :target se detuvo antes de terminar. El sitio consta como Node :current; comprueba que funciona y vuelve a intentarlo.',
    ],
    'remove_failed' => 'No se pudo eliminar Node :version. Indique la referencia de abajo al soporte.',
    'remove_failed_said' => 'No se pudo eliminar Node :version. fnm indicó: «:output»',
    'install_in_progress' => 'Node :version ya se está instalando. Espere a que termine esa instalación.',
];
