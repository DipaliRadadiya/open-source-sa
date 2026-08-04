<?php

return [
    'checks' => [
        'privilege' => 'Comandos privilegiados',
        'services' => 'Servicios',
        'writable_paths' => 'Rutas escribibles',
        'database' => 'Base de datos',
        'health_endpoint' => 'Endpoint de estado',
        'binaries' => 'Herramientas necesarias',
        'web_server' => 'Servidor web',
        'queue' => 'Procesador de cola',
    ],
    'fixes' => [
        'privilege' => 'El panel no puede ejecutar comandos como root. Comprueba que /etc/sudoers.d/ contiene la concesión del panel y que el archivo pasa visudo -c.',
        'privilege_disabled' => 'La elevación de privilegios está desactivada pero el panel no es root. Elimina SERVER_OPS_SUDO=false de .env.',
        'services_missing' => 'No existe una unidad que el panel espera. Define PANEL_FRONTEND_SERVICE y PANEL_QUEUE_SERVICE en .env con los nombres reales de este servidor.',
        'services_down' => 'Inícialos con systemctl start y revisa journalctl -u <unidad> para ver por qué se detuvieron.',
        'writable_paths' => 'Da la propiedad a la cuenta del panel: chown -R <usuario del panel> en las rutas indicadas.',
        'database_unreachable' => 'Revisa la configuración DB_ en .env y que el servicio de base de datos esté en marcha.',
        'database_pending' => 'Ejecuta php artisan migrate --force. El código se actualizó sin aplicar sus cambios de esquema.',
        'health_unreachable' => 'Comprueba que APP_URL en .env coincide con la dirección donde se sirve el panel y que el servidor web y php-fpm están en marcha.',
        'health_version_mismatch' => 'El código en ejecución y la versión servida difieren. Limpia las cachés con php artisan optimize:clear y recarga php-fpm.',
        'binaries_required' => 'Instala los paquetes que faltan. Sin ellos las funciones básicas no pueden ejecutarse.',
        'binaries_optional' => 'Cada herramienta que falta desactiva la función indicada al lado. Instálala desde la página de configuración o ignórala si no la necesitas.',
        'web_server_missing' => 'No se encontró un servidor web compatible. Instala nginx o Apache.',
        'web_server_undrivable' => 'El panel no puede escribir configuración para este servidor web, así que no se pueden crear sitios. Cambia a nginx o Apache.',
        'web_server_config' => 'La configuración del servidor web no es válida. Ejecuta su prueba de configuración para ver por qué; la próxima recarga fallará hasta que se corrija.',
        'queue_stalled' => 'Hay trabajos en cola pero nada los procesa. Reinicia el servicio de cola; el aprovisionamiento, los despliegues y las instalaciones no terminarán hasta que funcione.',
        'queue_failed_jobs' => 'Algunos trabajos en segundo plano fallaron. Revisa la tabla failed_jobs: el trabajo descartado en silencio suele ser la razón de que algo pareciera no hacer nada.',
        'queue_unreadable' => 'No se pudieron leer las tablas de la cola. Ejecuta php artisan migrate --force.',
    ],
];
