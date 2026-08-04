<?php

return [
    'checks' => [
        'privilege' => 'Comandos privilegiados',
        'services' => 'Servicios',
        'writable_paths' => 'Rutas escribibles',
        'database' => 'Base de datos',
        'health_endpoint' => 'Endpoint de estado',
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
    ],
];
