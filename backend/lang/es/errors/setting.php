<?php

return [
    'operation_failed' => 'El cambio de configuración falló en el servidor.',
    'group_unavailable' => 'Ese grupo de configuración no está disponible en este servidor.',
    'no_ssh_key' => 'Agregue una clave SSH antes de desactivar la autenticación por contraseña, o podría quedar bloqueado.',
    'env_not_writable' => 'El panel no puede escribir su propio archivo .env, así que no se pudo guardar una nueva contraseña de Redis. Corrija primero los permisos del archivo; de lo contrario el panel perdería el acceso a Redis.',
    'swap_in_use' => 'La memoria de intercambio está en uso y no se pudo desactivar. El servidor no tiene suficiente memoria libre para recuperar lo que está intercambiado; libera memoria e inténtalo de nuevo.',
];
