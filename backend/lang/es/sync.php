<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'Ya hay una sincronización en curso. Espera a que termine antes de iniciar otra.',
    ],

    'reasons' => [
        'firewall_direction_unsupported' => 'Es una regla de salida. El panel solo gestiona reglas de entrada, y registrarla aquí la aplicaría en la dirección equivocada.',
        'firewall_action_unsupported' => 'Esta regla limita o rechaza en lugar de permitir o denegar. El panel no tiene equivalente, y registrarla como un simple allow o deny describiría mal lo que hace el servidor.',
        'firewall_app_profile' => 'Esta regla usa un perfil de aplicación en vez de un puerto. Los puertos que hay detrás pueden cambiar al actualizar el paquete, así que importar los números de hoy sería una foto fija haciéndose pasar por la regla.',
        'panel_infrastructure' => 'Esto es el propio panel, no un sitio que pueda alojar. Se deja intacto a propósito.',
        'outside_panel_layout' => 'Este sitio no tiene la estructura de directorios que el panel gestiona, así que no se puede adoptar sin mover sus archivos. Sigue funcionando: no se ha cambiado nada.',
        'vhost_unreadable' => 'No se pudo leer la configuración del servidor web para este sitio, así que se dejó intacto.',
        'vhost_unparsed' => 'Este sitio se está sirviendo, pero su configuración no tiene un formato que el panel pueda leer. Adóptalo a mano o revisa el archivo.',
        'owner_not_tracked' => 'La cuenta de Linux propietaria de este sitio no es una que el panel gestione. Sincroniza primero los usuarios del sistema y vuelve a ejecutarlo.',
        'unreadable_key' => 'Esta línea no es una clave pública que el panel pueda leer, así que se dejó intacta. Puede seguir concediendo acceso: revísala a mano.',
        'discovery_failed' => 'No se pudo leer del servidor. No se cambió nada.',
        'adopt_failed' => 'Encontrado en el servidor, pero el panel no pudo crear un registro.',
        'requires_system_user' => 'Omitido porque los usuarios del sistema no formaban parte de esta ejecución y hacen falta primero.',
    ],

];
