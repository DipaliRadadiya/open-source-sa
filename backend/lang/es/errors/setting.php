<?php

return [
    'operation_failed' => 'El cambio de configuración falló en el servidor.',
    'group_unavailable' => 'Ese grupo de configuración no está disponible en este servidor.',
    'security_updates_unavailable' => 'unattended-upgrades no está instalado en este servidor, así que el panel no tiene nada que ejecutar. Instala el paquete unattended-upgrades y vuelve a intentarlo.',
    'security_updates_in_progress' => 'Ya se está ejecutando una actualización de seguridad.',
    'no_ssh_key' => 'Agregue una clave SSH antes de desactivar la autenticación por contraseña, o podría quedar bloqueado.',
    'redis_credential_unusable' => 'El panel no puede acceder a Redis con la contraseña que tiene guardada, por lo que no puede cambiarla. Redis está en ejecución pero rechaza la credencial del panel: corrija REDIS_PASSWORD en el .env del panel con la contraseña que Redis requiere realmente y vuelva a intentarlo.',
    'env_not_writable' => 'El panel no puede escribir su propio archivo .env, así que no se pudo guardar una nueva contraseña de Redis. Corrija primero los permisos del archivo; de lo contrario el panel perdería el acceso a Redis.',
    'swap_in_use' => 'La memoria de intercambio está en uso y no se pudo desactivar. El servidor no tiene suficiente memoria libre para recuperar lo que está intercambiado; libera memoria e inténtalo de nuevo.',
];
